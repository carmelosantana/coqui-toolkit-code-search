<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeSearch\Runtime;

/**
 * Wraps ripgrep (rg) for text/regex code search.
 *
 * Executes searches with --json output, parses JSON Lines into structured
 * arrays, handles timeout and output truncation. All searches respect
 * .gitignore by default.
 */
final class RipgrepRunner
{
    private const int DEFAULT_TIMEOUT = 30;
    private const int MAX_OUTPUT_BYTES = 65_536;

    public function __construct(
        private readonly BinaryResolver $resolver,
    ) {}

    /**
     * Run a ripgrep search and return parsed matches.
     *
     * @param list<string> $extraArgs Additional rg arguments
     * @return array{matches: list<array<string, mixed>>, stats: array<string, mixed>, truncated: bool}
     */
    public function search(
        string $pattern,
        string $searchPath,
        array $extraArgs = [],
        int $timeout = self::DEFAULT_TIMEOUT,
    ): array {
        $binary = $this->resolver->resolveRipgrep();

        $args = [
            escapeshellarg($binary),
            '--json',
            '--no-messages',
        ];

        foreach ($extraArgs as $arg) {
            $args[] = $arg;
        }

        $args[] = '--';
        $args[] = escapeshellarg($pattern);
        $args[] = escapeshellarg($searchPath);

        $command = implode(' ', $args);
        $result = $this->execute($command, $searchPath, $timeout);

        return $this->parseJsonOutput($result, $searchPath);
    }

    /**
     * Find files matching a glob or name pattern (no content search).
     *
     * @param list<string> $extraArgs Additional rg arguments
     * @return list<string> Matching file paths (relative to searchPath)
     */
    public function findFiles(
        string $searchPath,
        ?string $glob = null,
        ?string $fileType = null,
        array $extraArgs = [],
        int $maxResults = 200,
    ): array {
        $binary = $this->resolver->resolveRipgrep();

        $args = [
            escapeshellarg($binary),
            '--files',
            '--no-messages',
        ];

        if ($glob !== null && $glob !== '') {
            $args[] = '--glob';
            $args[] = escapeshellarg($glob);
        }

        if ($fileType !== null && $fileType !== '') {
            $args[] = '--type';
            $args[] = escapeshellarg($fileType);
        }

        foreach ($extraArgs as $arg) {
            $args[] = $arg;
        }

        $args[] = escapeshellarg($searchPath);

        $command = implode(' ', $args);
        $result = $this->execute($command, $searchPath, self::DEFAULT_TIMEOUT);

        if (!$result->isSuccess() && $result->stdout === '') {
            return [];
        }

        $lines = array_filter(
            explode("\n", trim($result->stdout)),
            static fn(string $line): bool => $line !== '',
        );

        // Make paths relative to search path
        $prefix = rtrim($searchPath, '/') . '/';
        $files = array_map(
            static fn(string $line): string => str_starts_with($line, $prefix)
                ? substr($line, strlen($prefix))
                : $line,
            $lines,
        );

        return array_slice(array_values($files), 0, $maxResults);
    }

    /**
     * Check if ripgrep is available.
     */
    public function isAvailable(): bool
    {
        return $this->resolver->isAvailable('rg');
    }

    /**
     * Parse ripgrep --json output into structured matches.
     *
     * @return array{matches: list<array<string, mixed>>, stats: array<string, mixed>, truncated: bool}
     */
    private function parseJsonOutput(ProcessResult $result, string $searchPath): array
    {
        $matches = [];
        $stats = [
            'matched_lines' => 0,
            'files_with_matches' => 0,
        ];
        $truncated = false;
        $filesSet = [];
        $prefix = rtrim($searchPath, '/') . '/';

        $lines = explode("\n", trim($result->stdout));

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            /** @var array<string, mixed>|null $data */
            $data = json_decode($line, true);
            if (!is_array($data)) {
                continue;
            }

            $type = (string) ($data['type'] ?? '');

            if ($type === 'match') {
                $matchData = $data['data'] ?? [];
                if (!is_array($matchData)) {
                    continue;
                }

                $filePath = (string) ($matchData['path']['text'] ?? '');
                if (str_starts_with($filePath, $prefix)) {
                    $filePath = substr($filePath, strlen($prefix));
                }

                $lineText = (string) ($matchData['lines']['text'] ?? '');
                $lineNumber = (int) ($matchData['line_number'] ?? 0);

                $submatches = [];
                if (isset($matchData['submatches']) && is_array($matchData['submatches'])) {
                    foreach ($matchData['submatches'] as $sub) {
                        if (is_array($sub)) {
                            $submatches[] = [
                                'match' => (string) ($sub['match']['text'] ?? ''),
                                'start' => (int) ($sub['start'] ?? 0),
                                'end' => (int) ($sub['end'] ?? 0),
                            ];
                        }
                    }
                }

                $matches[] = [
                    'path' => $filePath,
                    'line' => $lineNumber,
                    'text' => rtrim($lineText, "\n"),
                    'submatches' => $submatches,
                ];

                $stats['matched_lines']++;
                $filesSet[$filePath] = true;
            } elseif ($type === 'context') {
                $ctxData = $data['data'] ?? [];
                if (!is_array($ctxData)) {
                    continue;
                }

                $filePath = (string) ($ctxData['path']['text'] ?? '');
                if (str_starts_with($filePath, $prefix)) {
                    $filePath = substr($filePath, strlen($prefix));
                }

                $matches[] = [
                    'path' => $filePath,
                    'line' => (int) ($ctxData['line_number'] ?? 0),
                    'text' => rtrim((string) ($ctxData['lines']['text'] ?? ''), "\n"),
                    'context' => true,
                ];
            } elseif ($type === 'summary') {
                $summaryData = $data['data'] ?? [];
                if (is_array($summaryData) && isset($summaryData['stats'])) {
                    $rgStats = $summaryData['stats'];
                    if (is_array($rgStats)) {
                        $stats['matched_lines'] = (int) ($rgStats['matched_lines'] ?? $stats['matched_lines']);
                        $stats['matches'] = (int) ($rgStats['matches'] ?? 0);
                        $stats['bytes_searched'] = (int) ($rgStats['bytes_searched'] ?? 0);
                    }
                }
            }
        }

        $stats['files_with_matches'] = count($filesSet);

        if (strlen($result->stdout) >= self::MAX_OUTPUT_BYTES) {
            $truncated = true;
        }

        return [
            'matches' => $matches,
            'stats' => $stats,
            'truncated' => $truncated,
        ];
    }

    private function execute(string $command, string $cwd, int $timeout): ProcessResult
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd);

        if (!is_resource($process)) {
            return new ProcessResult(1, '', 'Failed to start process: ' . $command);
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = time();

        while (true) {
            $status = proc_get_status($process);

            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';

            if (!$status['running']) {
                break;
            }

            // Truncate if output exceeds limit
            if (strlen($stdout) >= self::MAX_OUTPUT_BYTES) {
                proc_terminate($process, 15);
                usleep(100_000);
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return new ProcessResult(0, $stdout, $stderr);
            }

            if ($timeout > 0 && (time() - $startTime) >= $timeout) {
                proc_terminate($process, 15);
                usleep(100_000);
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return new ProcessResult(124, $stdout, "Search timed out after {$timeout}s.\n" . $stderr);
            }

            usleep(10_000);
        }

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return new ProcessResult($exitCode, $stdout, trim($stderr));
    }
}
