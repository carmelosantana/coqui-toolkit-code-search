<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeSearch\Index;

use CoquiBot\Toolkits\CodeSearch\Contract\ChangeSet;

/**
 * Detects filesystem changes by comparing directory state against the index.
 *
 * Walks the file tree, collects {path, mtime} for all source files,
 * and diffs against the stored index to produce a ChangeSet with
 * added, modified, and deleted file lists.
 */
final class IncrementalIndexer
{
    /**
     * Default file extensions to index.
     * Covers the most common programming languages.
     */
    private const array DEFAULT_EXTENSIONS = [
        'php', 'js', 'ts', 'jsx', 'tsx', 'py', 'rb', 'go', 'rs', 'java',
        'c', 'cpp', 'h', 'hpp', 'cs', 'swift', 'kt', 'kts', 'scala',
        'sh', 'bash', 'zsh', 'lua', 'pl', 'pm', 'r', 'R',
        'sql', 'graphql', 'gql', 'proto',
        'yaml', 'yml', 'toml', 'json', 'xml',
        'md', 'rst', 'txt',
        'css', 'scss', 'sass', 'less',
        'html', 'htm', 'vue', 'svelte',
        'Dockerfile', 'Makefile',
    ];

    /**
     * Directories to always skip during scanning.
     */
    private const array SKIP_DIRS = [
        '.git', '.svn', '.hg', 'node_modules', 'vendor', '.workspace',
        '__pycache__', '.cache', '.phpstan-cache', '.phpunit.cache',
        'dist', 'build', '.next', '.nuxt', 'target',
    ];

    /** @var list<string> */
    private array $extensions;

    /**
     * @param list<string>|null $extensions Override the default extension list
     */
    public function __construct(
        ?array $extensions = null,
    ) {
        $this->extensions = $extensions ?? self::DEFAULT_EXTENSIONS;
    }

    /**
     * Scan a directory and compare against indexed state.
     *
     * @param string              $rootPath     Directory to scan
     * @param array<string, int>  $indexedFiles Map of path => mtime from the index
     */
    public function detectChanges(string $rootPath, array $indexedFiles): ChangeSet
    {
        $currentFiles = $this->scanDirectory($rootPath);

        $added = [];
        $modified = [];

        foreach ($currentFiles as $path => $mtime) {
            if (!isset($indexedFiles[$path])) {
                $added[] = ['path' => $path, 'mtime' => $mtime];
            } elseif ($indexedFiles[$path] !== $mtime) {
                $modified[] = ['path' => $path, 'mtime' => $mtime];
            }
        }

        $deleted = [];
        foreach ($indexedFiles as $path => $mtime) {
            if (!isset($currentFiles[$path])) {
                $deleted[] = $path;
            }
        }

        return new ChangeSet(
            added: $added,
            modified: $modified,
            deleted: $deleted,
        );
    }

    /**
     * Scan a directory and return all source files with their mtimes.
     *
     * @return array<string, int> Map of relative path => mtime
     */
    public function scanDirectory(string $rootPath): array
    {
        $rootPath = rtrim($rootPath, '/');
        $files = [];

        if (!is_dir($rootPath)) {
            return $files;
        }

        $this->walkDirectory($rootPath, $rootPath, $files);

        return $files;
    }

    /**
     * Detect language from file extension.
     */
    public static function detectLanguage(string $filePath): string
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($ext) {
            'php' => 'PHP',
            'js', 'mjs', 'cjs' => 'JavaScript',
            'ts', 'mts' => 'TypeScript',
            'jsx' => 'JSX',
            'tsx' => 'TSX',
            'py' => 'Python',
            'rb' => 'Ruby',
            'go' => 'Go',
            'rs' => 'Rust',
            'java' => 'Java',
            'c', 'h' => 'C',
            'cpp', 'hpp', 'cc', 'cxx' => 'C++',
            'cs' => 'C#',
            'swift' => 'Swift',
            'kt', 'kts' => 'Kotlin',
            'scala' => 'Scala',
            'sh', 'bash', 'zsh' => 'Shell',
            'lua' => 'Lua',
            'pl', 'pm' => 'Perl',
            'r' => 'R',
            'sql' => 'SQL',
            'css' => 'CSS',
            'scss', 'sass' => 'SCSS',
            'less' => 'Less',
            'html', 'htm' => 'HTML',
            'vue' => 'Vue',
            'svelte' => 'Svelte',
            'json' => 'JSON',
            'yaml', 'yml' => 'YAML',
            'toml' => 'TOML',
            'xml' => 'XML',
            'md' => 'Markdown',
            'graphql', 'gql' => 'GraphQL',
            'proto' => 'Protobuf',
            default => $ext !== '' ? strtoupper($ext) : 'unknown',
        };
    }

    /**
     * @param array<string, int> $files Accumulator: relative path => mtime
     */
    private function walkDirectory(string $currentPath, string $rootPath, array &$files): void
    {
        $entries = @scandir($currentPath);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $currentPath . '/' . $entry;

            if (is_dir($fullPath)) {
                if (in_array($entry, self::SKIP_DIRS, true)) {
                    continue;
                }
                $this->walkDirectory($fullPath, $rootPath, $files);
                continue;
            }

            if (!is_file($fullPath) || !is_readable($fullPath)) {
                continue;
            }

            if (!$this->isSourceFile($entry)) {
                continue;
            }

            // Build relative path
            $relativePath = substr($fullPath, strlen($rootPath) + 1);
            $mtime = (int) @filemtime($fullPath);

            $files[$relativePath] = $mtime;
        }
    }

    private function isSourceFile(string $filename): bool
    {
        // Handle extensionless files like Dockerfile, Makefile
        if (in_array($filename, self::DEFAULT_EXTENSIONS, true)) {
            return true;
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return $ext !== '' && in_array($ext, $this->extensions, true);
    }
}
