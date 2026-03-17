<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeSearch\Runtime;

use CoquiBot\Toolkits\CodeSearch\Contract\TagEntry;

/**
 * Wraps universal-ctags for symbol extraction.
 *
 * Supports two modes:
 * - Full index: process an entire directory tree recursively
 * - File list: process specific files via --filter mode (for incremental updates)
 *
 * All output uses --output-format=json for structured parsing.
 */
final class CtagsRunner
{
    private const int DEFAULT_TIMEOUT = 60;
    private const int MAX_OUTPUT_BYTES = 2_097_152; // 2MB for ctags (symbol data can be large)

    public function __construct(
        private readonly BinaryResolver $resolver,
    ) {}

    /**
     * Index all files in a directory recursively.
     *
     * @return list<TagEntry>
     */
    public function indexDirectory(string $directory, int $timeout = self::DEFAULT_TIMEOUT): array
    {
        $binary = $this->resolver->resolveCtags();

        $args = [
            escapeshellarg($binary),
            '--output-format=json',
            '--fields=+lnSKze',
            '--extras=+q',
            '--sort=no',
            '-R',
            escapeshellarg($directory),
        ];

        $command = implode(' ', $args);
        $result = $this->execute($command, $directory, $timeout);

        return $this->parseJsonOutput($result->stdout);
    }

    /**
     * Index specific files by passing them as arguments.
     *
     * @param list<string> $filePaths Absolute file paths to index
     * @return list<TagEntry>
     */
    public function indexFiles(array $filePaths, int $timeout = self::DEFAULT_TIMEOUT): array
    {
        if ($filePaths === []) {
            return [];
        }

        $binary = $this->resolver->resolveCtags();

        $args = [
            escapeshellarg($binary),
            '--output-format=json',
            '--fields=+lnSKze',
            '--extras=+q',
            '--sort=no',
            '-f',
            '-', // Output to stdout
        ];

        foreach ($filePaths as $file) {
            $args[] = escapeshellarg($file);
        }

        $command = implode(' ', $args);
        $cwd = dirname($filePaths[0]);
        $result = $this->execute($command, $cwd, $timeout);

        return $this->parseJsonOutput($result->stdout);
    }

    /**
     * Check if ctags is available.
     */
    public function isAvailable(): bool
    {
        return $this->resolver->isAvailable('ctags');
    }

    /**
     * Parse ctags JSON output into TagEntry objects.
     *
     * @return list<TagEntry>
     */
    private function parseJsonOutput(string $output): array
    {
        $entries = [];
        $lines = explode("\n", trim($output));

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            /** @var array<string, mixed>|null $data */
            $data = json_decode($line, true);
            if (!is_array($data)) {
                continue;
            }

            $entry = TagEntry::fromCtagsJson($data);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
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
            return new ProcessResult(1, '', 'Failed to start ctags process: ' . $command);
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

                return new ProcessResult(124, $stdout, "ctags timed out after {$timeout}s.\n" . $stderr);
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
