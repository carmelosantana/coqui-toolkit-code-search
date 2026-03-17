<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeSearch\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Toolkits\CodeSearch\Exception\BinaryNotFoundException;
use CoquiBot\Toolkits\CodeSearch\Runtime\RipgrepRunner;

/**
 * Find files by name, glob pattern, or language type.
 *
 * Uses ripgrep's --files mode for fast file discovery without reading
 * content. Returns file paths with sizes and detected languages.
 */
final readonly class SearchFilesTool
{
    public function __construct(
        private RipgrepRunner $ripgrep,
        private string $defaultSearchPath,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'find_files',
            description: 'Find files by name pattern, glob, or language type. Does NOT search file contents — use code_search for that. Returns matching file paths with sizes.',
            parameters: [
                new StringParameter('pattern', 'Glob pattern or filename substring (e.g. "*.php", "Controller", "src/**/*.ts").', required: true),
                new StringParameter('file_type', 'Filter by language type (e.g. "php", "js", "python").', required: false),
                new StringParameter('path', 'Subdirectory to search within (relative to search root).', required: false),
                new NumberParameter('max_results', 'Maximum files to return (default: 50).', required: false, integer: true, minimum: 1, maximum: 500),
            ],
            callback: fn(array $input): ToolResult => $this->execute($input),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(array $input): ToolResult
    {
        $pattern = trim((string) ($input['pattern'] ?? ''));
        if ($pattern === '') {
            return ToolResult::error('The "pattern" parameter is required.');
        }

        $fileType = trim((string) ($input['file_type'] ?? ''));
        $path = trim((string) ($input['path'] ?? ''));
        $maxResults = (int) ($input['max_results'] ?? 50);

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

        // Determine if the pattern is a glob or a simple name substring
        $isGlob = str_contains($pattern, '*') || str_contains($pattern, '?') || str_contains($pattern, '{');
        $glob = $isGlob ? $pattern : '*' . $pattern . '*';

        try {
            $files = $this->ripgrep->findFiles(
                searchPath: $searchPath,
                glob: $glob,
                fileType: $fileType !== '' ? $fileType : null,
                maxResults: $maxResults,
            );
        } catch (BinaryNotFoundException $e) {
            return ToolResult::error($e->getMessage());
        }

        if ($files === []) {
            return ToolResult::success(json_encode([
                'files' => [],
                'summary' => sprintf('No files matching "%s" found.', $pattern),
            ], JSON_UNESCAPED_SLASHES) ?: '{}');
        }

        // Enrich with file sizes
        $enriched = [];
        foreach ($files as $file) {
            $absolutePath = rtrim($searchPath, '/') . '/' . $file;
            $size = is_file($absolutePath) ? (int) @filesize($absolutePath) : 0;

            $entry = [
                'path' => $file,
                'size' => $this->formatSize($size),
            ];

            $enriched[] = $entry;
        }

        return ToolResult::success(json_encode([
            'files' => $enriched,
            'count' => count($enriched),
            'summary' => sprintf('%d file(s) matching "%s"', count($enriched), $pattern),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . 'B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . 'KB';
        }

        return round($bytes / 1048576, 1) . 'MB';
    }
}
