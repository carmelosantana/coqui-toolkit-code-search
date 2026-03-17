<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeSearch\Index;

use CoquiBot\Toolkits\CodeSearch\Contract\IndexStats;
use CoquiBot\Toolkits\CodeSearch\Contract\TagEntry;
use CoquiBot\Toolkits\CodeSearch\Exception\IndexException;
use CoquiBot\Toolkits\CodeSearch\Runtime\CtagsRunner;
use CoquiBot\Toolkits\CodeSearch\Storage\IndexDatabase;

/**
 * Main code index orchestrator.
 *
 * Coordinates between the IncrementalIndexer (change detection),
 * CtagsRunner (symbol extraction), and IndexDatabase (persistence).
 *
 * Supports lazy initialization: the index is built on first access
 * and updated incrementally on subsequent calls. Multiple search
 * roots can be configured for indexing across different directories.
 */
final class CodeIndex
{
    private bool $ready = false;

    public function __construct(
        private readonly IndexDatabase $db,
        private readonly CtagsRunner $ctags,
        private readonly IncrementalIndexer $indexer,
        private readonly string $workspacePath,
    ) {}

    /**
     * Ensure the index is ready for queries.
     *
     * On first call: builds the full index if it doesn't exist,
     * or runs an incremental update if it does.
     */
    public function ensureReady(): void
    {
        if ($this->ready) {
            return;
        }

        $roots = $this->getSearchRoots();

        if ($roots === []) {
            // Default: index the workspace itself
            $this->addSearchRoot($this->workspacePath);
            $roots = [$this->workspacePath];
        }

        if (!$this->db->exists() || $this->db->getMeta('schema_version') === null) {
            $this->fullBuild($roots);
        } else {
            $this->incrementalUpdate($roots);
        }

        $this->ready = true;
    }

    /**
     * Build the full index from scratch for all search roots.
     *
     * @param list<string> $roots Directories to index
     */
    public function fullBuild(array $roots): IndexStats
    {
        $startTime = microtime(true);

        $this->db->beginTransaction();

        try {
            $this->db->clear();
            $this->db->setMeta('schema_version', '1');

            $totalFiles = 0;
            $totalSymbols = 0;

            foreach ($roots as $root) {
                $root = rtrim($root, '/');
                if (!is_dir($root)) {
                    continue;
                }

                $scannedFiles = $this->indexer->scanDirectory($root);

                if ($scannedFiles === []) {
                    continue;
                }

                // Index files in batches to avoid memory issues
                $batchPaths = [];
                $batchMeta = [];

                foreach ($scannedFiles as $relativePath => $mtime) {
                    $absolutePath = $root . '/' . $relativePath;
                    $language = IncrementalIndexer::detectLanguage($relativePath);
                    $size = (int) @filesize($absolutePath);

                    $batchMeta[$relativePath] = [
                        'language' => $language,
                        'size' => $size,
                        'mtime' => $mtime,
                        'root' => $root,
                    ];
                    $batchPaths[] = $absolutePath;
                }

                // Run ctags on all files at once
                $tags = $this->ctags->indexFiles($batchPaths);

                // Group tags by file path
                $tagsByFile = $this->groupTagsByFile($tags, $root);

                // Insert file records and their symbols
                foreach ($batchMeta as $relativePath => $meta) {
                    $storedPath = $this->makeStoredPath($meta['root'], $relativePath);
                    $fileId = $this->db->insertFile(
                        $storedPath,
                        $meta['language'],
                        $meta['size'],
                        $meta['mtime'],
                    );
                    $totalFiles++;

                    // Find tags for this file
                    $fileTags = $tagsByFile[$relativePath] ?? [];
                    if ($fileTags !== []) {
                        $totalSymbols += $this->db->insertSymbols($fileId, $fileTags);
                    }
                }
            }

            $this->db->setMeta('last_indexed_at', date('c'));
            $this->db->setMeta('index_type', 'full');
            $this->db->commit();

            return new IndexStats(
                filesIndexed: $totalFiles,
                symbolsIndexed: $totalSymbols,
                filesAdded: $totalFiles,
                duration: microtime(true) - $startTime,
            );
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw IndexException::databaseError($e->getMessage());
        }
    }

    /**
     * Run an incremental update: detect changes and re-index only affected files.
     *
     * @param list<string> $roots Directories to check
     */
    public function incrementalUpdate(array $roots): IndexStats
    {
        $startTime = microtime(true);
        $indexedFiles = $this->db->getAllFileMtimes();

        $allAdded = [];
        $allModified = [];
        $allDeleted = [];

        foreach ($roots as $root) {
            $root = rtrim($root, '/');
            if (!is_dir($root)) {
                continue;
            }

            // Filter indexed files to this root
            $prefix = $this->makeStoredPath($root, '');
            $rootIndexed = [];
            foreach ($indexedFiles as $path => $mtime) {
                if (str_starts_with($path, $prefix)) {
                    // Convert back to relative path for comparison
                    $rootIndexed[substr($path, strlen($prefix))] = $mtime;
                }
            }

            $changes = $this->indexer->detectChanges($root, $rootIndexed);

            foreach ($changes->added as $file) {
                $allAdded[] = ['root' => $root, ...$file];
            }
            foreach ($changes->modified as $file) {
                $allModified[] = ['root' => $root, ...$file];
            }
            foreach ($changes->deleted as $path) {
                $allDeleted[] = $this->makeStoredPath($root, $path);
            }
        }

        if ($allAdded === [] && $allModified === [] && $allDeleted === []) {
            $this->ready = true;
            return new IndexStats(
                filesIndexed: 0,
                symbolsIndexed: 0,
                duration: microtime(true) - $startTime,
            );
        }

        $this->db->beginTransaction();

        try {
            $totalSymbols = 0;

            // Delete removed files
            if ($allDeleted !== []) {
                $this->db->deleteFiles($allDeleted);
            }

            // Delete and re-index modified files
            $modifiedPaths = array_map(
                static fn(array $f): string => $f['root'] . '/' . $f['path'],
                $allModified,
            );
            $modifiedStoredPaths = array_map(
                fn(array $f): string => $this->makeStoredPath($f['root'], $f['path']),
                $allModified,
            );

            if ($modifiedStoredPaths !== []) {
                $this->db->deleteFiles($modifiedStoredPaths);
            }

            // Combine added and modified for indexing
            $toIndex = [...$allAdded, ...$allModified];
            $absolutePaths = array_map(
                static fn(array $f): string => $f['root'] . '/' . $f['path'],
                $toIndex,
            );

            if ($absolutePaths !== []) {
                $tags = $this->ctags->indexFiles($absolutePaths);

                // Group tags by file
                $tagsByAbsolute = [];
                foreach ($tags as $tag) {
                    $tagsByAbsolute[$tag->path][] = $tag;
                }

                foreach ($toIndex as $file) {
                    $absolutePath = $file['root'] . '/' . $file['path'];
                    $storedPath = $this->makeStoredPath($file['root'], $file['path']);
                    $language = IncrementalIndexer::detectLanguage($file['path']);
                    $size = (int) @filesize($absolutePath);

                    $fileId = $this->db->insertFile(
                        $storedPath,
                        $language,
                        $size,
                        $file['mtime'],
                    );

                    $fileTags = $tagsByAbsolute[$absolutePath] ?? [];
                    if ($fileTags !== []) {
                        $totalSymbols += $this->db->insertSymbols($fileId, $fileTags);
                    }
                }
            }

            $this->db->setMeta('last_indexed_at', date('c'));
            $this->db->setMeta('index_type', 'incremental');
            $this->db->commit();

            return new IndexStats(
                filesIndexed: count($toIndex),
                symbolsIndexed: $totalSymbols,
                filesAdded: count($allAdded),
                filesModified: count($allModified),
                filesDeleted: count($allDeleted),
                duration: microtime(true) - $startTime,
            );
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw IndexException::databaseError($e->getMessage());
        }
    }

    /**
     * Search symbols by name with optional filters.
     *
     * @return list<array<string, mixed>>
     */
    public function searchSymbols(
        string $name,
        bool $exact = false,
        ?string $kind = null,
        ?string $language = null,
        ?string $scope = null,
        int $limit = 50,
    ): array {
        $this->ensureReady();

        return $this->db->searchSymbols($name, $exact, $kind, $language, $scope, $limit);
    }

    /**
     * Get all symbols for a specific file.
     *
     * @return list<array<string, mixed>>
     */
    public function fileSymbols(string $filePath, ?string $kind = null): array
    {
        $this->ensureReady();

        // Try the path as-is first, then try with each root prefix
        $symbols = $this->db->fileSymbols($filePath, $kind);

        if ($symbols !== []) {
            return $symbols;
        }

        // Try with root prefixes
        foreach ($this->getSearchRoots() as $root) {
            $storedPath = $this->makeStoredPath($root, $filePath);
            $symbols = $this->db->fileSymbols($storedPath, $kind);
            if ($symbols !== []) {
                return $symbols;
            }
        }

        return [];
    }

    /**
     * Get index statistics.
     *
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $dbStats = $this->db->stats();
        $roots = $this->getSearchRoots();

        return [
            ...$dbStats,
            'search_roots' => $roots,
            'db_path' => $this->workspacePath . '/code-search/index.db',
        ];
    }

    /**
     * Clear the entire index.
     */
    public function clear(): void
    {
        $this->db->clear();
        $this->ready = false;
    }

    /**
     * Get configured search roots.
     *
     * @return list<string>
     */
    public function getSearchRoots(): array
    {
        $rootsJson = $this->db->getMeta('search_roots');

        if ($rootsJson === null) {
            return [];
        }

        $roots = json_decode($rootsJson, true);

        return is_array($roots) ? array_values(array_filter($roots, 'is_string')) : [];
    }

    /**
     * Add a search root directory.
     */
    public function addSearchRoot(string $path): void
    {
        $path = rtrim($path, '/');
        $roots = $this->getSearchRoots();

        if (!in_array($path, $roots, true)) {
            $roots[] = $path;
            $this->db->setMeta('search_roots', json_encode($roots, JSON_UNESCAPED_SLASHES) ?: '[]');
        }
    }

    /**
     * Remove a search root directory.
     */
    public function removeSearchRoot(string $path): void
    {
        $path = rtrim($path, '/');
        $roots = $this->getSearchRoots();
        $roots = array_values(array_filter($roots, static fn(string $r): bool => $r !== $path));

        $this->db->setMeta('search_roots', json_encode($roots, JSON_UNESCAPED_SLASHES) ?: '[]');

        // Remove files from this root
        $prefix = $path . '::';
        $allFiles = $this->db->getAllFileMtimes();
        $toDelete = [];
        foreach (array_keys($allFiles) as $filePath) {
            if (str_starts_with($filePath, $prefix)) {
                $toDelete[] = $filePath;
            }
        }

        if ($toDelete !== []) {
            $this->db->deleteFiles($toDelete);
        }
    }

    /**
     * Group ctags entries by their relative file path.
     *
     * @param list<TagEntry> $tags
     * @return array<string, list<TagEntry>>
     */
    private function groupTagsByFile(array $tags, string $root): array
    {
        $root = rtrim($root, '/') . '/';
        $grouped = [];

        foreach ($tags as $tag) {
            $path = $tag->path;
            if (str_starts_with($path, $root)) {
                $path = substr($path, strlen($root));
            }
            $grouped[$path][] = $tag;
        }

        return $grouped;
    }

    /**
     * Create a stored path that encodes the root for multi-root support.
     *
     * Format: "{root}::{relativePath}" — allows unambiguous path resolution
     * when multiple roots are configured.
     */
    private function makeStoredPath(string $root, string $relativePath): string
    {
        return rtrim($root, '/') . '::' . ltrim($relativePath, '/');
    }
}
