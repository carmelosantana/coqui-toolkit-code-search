<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeSearch\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Toolkits\CodeSearch\Exception\BinaryNotFoundException;
use CoquiBot\Toolkits\CodeSearch\Runtime\RipgrepRunner;

/**
 * Text/regex content search across codebases via ripgrep.
 *
 * Searches file contents for matching patterns using ripgrep's --json
 * output, returning structured results with file paths, line numbers,
 * matched text, and surrounding context. Works without the ctags index.
 */
final readonly class CodeSearchTool
{
    public function __construct(
        private RipgrepRunner $ripgrep,
        private string $defaultSearchPath,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'code_search',
            description: 'Search file contents for text or regex patterns. Returns matching lines with file paths, line numbers, and context. Uses ripgrep for fast, .gitignore-aware searching.',
            parameters: [
                new StringParameter('query', 'Search pattern — literal text or regex.', required: true),
                new StringParameter('path', 'Subdirectory to scope the search (relative to search root). Omit to search everything.', required: false),
                new StringParameter('file_type', 'Filter by language (e.g. "php", "js", "python", "go", "rust", "css", "html"). Uses ripgrep type definitions.', required: false),
                new BoolParameter('is_regex', 'Treat query as a regex pattern (default: false — literal string match).', required: false),
                new NumberParameter('context_lines', 'Lines of context to include around each match (default: 2).', required: false, integer: true, minimum: 0, maximum: 10),
                new NumberParameter('max_results', 'Maximum number of matching lines to return (default: 50).', required: false, integer: true, minimum: 1, maximum: 200),
                new BoolParameter('case_sensitive', 'Force case-sensitive matching (default: false — smart case).', required: false),
                new BoolParameter('word_match', 'Match whole words only (default: false).', required: false),
                new BoolParameter('include_hidden', 'Include hidden files and directories (default: false).', required: false),
                new StringParameter('glob', 'Filter files by glob pattern (e.g. "*.php", "src/**/*.ts"). Can be combined with file_type.', required: false),
            ],
            callback: fn(array $input): ToolResult => $this->execute($input),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(array $input): ToolResult
    {
        $query = trim((string) ($input['query'] ?? ''));
        if ($query === '') {
            return ToolResult::error('The "query" parameter is required.');
        }

        $path = trim((string) ($input['path'] ?? ''));
        $fileType = trim((string) ($input['file_type'] ?? ''));
        $isRegex = (bool) ($input['is_regex'] ?? false);
        $contextLines = (int) ($input['context_lines'] ?? 2);
        $maxResults = (int) ($input['max_results'] ?? 50);
        $caseSensitive = (bool) ($input['case_sensitive'] ?? false);
        $wordMatch = (bool) ($input['word_match'] ?? false);
        $includeHidden = (bool) ($input['include_hidden'] ?? false);
        $glob = trim((string) ($input['glob'] ?? ''));

        // Determine search path
        $searchPath = $this->defaultSearchPath;
        if ($path !== '') {
            $resolved = rtrim($this->defaultSearchPath, '/') . '/' . ltrim($path, '/');
            if (is_dir($resolved)) {
                $searchPath = $resolved;
            } else {
                return ToolResult::error(sprintf('Path "%s" is not a valid directory.', $path));
            }
        }

        // Build ripgrep flags
        $args = [];

        if ($contextLines > 0) {
            $args[] = '-C';
            $args[] = (string) $contextLines;
        }

        $args[] = '-m';
        $args[] = (string) $maxResults;

        if ($fileType !== '') {
            $args[] = '--type';
            $args[] = escapeshellarg($fileType);
        }

        if ($glob !== '') {
            $args[] = '--glob';
            $args[] = escapeshellarg($glob);
        }

        if ($isRegex) {
            $args[] = '--pcre2';
        } else {
            $args[] = '--fixed-strings';
        }

        if ($caseSensitive) {
            $args[] = '--case-sensitive';
        } else {
            $args[] = '--smart-case';
        }

        if ($wordMatch) {
            $args[] = '--word-regexp';
        }

        if ($includeHidden) {
            $args[] = '--hidden';
        }

        try {
            $result = $this->ripgrep->search($query, $searchPath, $args);
        } catch (BinaryNotFoundException $e) {
            return ToolResult::error($e->getMessage());
        }

        if ($result['matches'] === []) {
            return ToolResult::success(json_encode([
                'matches' => [],
                'summary' => 'No matches found.',
            ], JSON_UNESCAPED_SLASHES) ?: '{}');
        }

        // Group matches by file for cleaner output
        $grouped = $this->groupMatchesByFile($result['matches']);

        $output = [
            'files' => $grouped,
            'summary' => sprintf(
                '%d match(es) in %d file(s)',
                $result['stats']['matched_lines'] ?? count($result['matches']),
                $result['stats']['files_with_matches'] ?? count($grouped),
            ),
        ];

        if ($result['truncated']) {
            $output['truncated'] = true;
            $output['summary'] .= ' (results truncated — refine your query for more precise results)';
        }

        return ToolResult::success(json_encode($output, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
    }

    /**
     * Group flat match list into per-file structure.
     *
     * @param list<array<string, mixed>> $matches
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupMatchesByFile(array $matches): array
    {
        $grouped = [];

        foreach ($matches as $match) {
            $file = (string) ($match['path'] ?? 'unknown');

            $entry = [
                'line' => $match['line'] ?? 0,
                'text' => $match['text'] ?? '',
            ];

            if (isset($match['context']) && $match['context'] === true) {
                $entry['context'] = true;
            }

            $grouped[$file][] = $entry;
        }

        return $grouped;
    }
}
