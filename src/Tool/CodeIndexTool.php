<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeSearch\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Toolkits\CodeSearch\Index\CodeIndex;

/**
 * Manage the code search index — build, update, status, clear, manage roots.
 *
 * Exposes index lifecycle operations to the LLM agent. The index is
 * built lazily on first symbol_search or file_structure call, but
 * this tool allows explicit control over indexing behavior.
 */
final readonly class CodeIndexTool
{
    public function __construct(
        private CodeIndex $index,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'code_index',
            description: 'Manage the code search index. Build, update, check status, clear, or configure search roots. The index powers symbol_search and file_structure tools.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Index management action to perform.',
                    values: ['build', 'update', 'status', 'clear', 'add_root', 'remove_root', 'list_roots'],
                    required: true,
                ),
                new StringParameter('path', 'Directory path — used with "add_root" and "remove_root" actions.', required: false),
            ],
            callback: fn(array $input): ToolResult => $this->execute($input),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(array $input): ToolResult
    {
        $action = trim((string) ($input['action'] ?? ''));

        return match ($action) {
            'build' => $this->executeBuild(),
            'update' => $this->executeUpdate(),
            'status' => $this->executeStatus(),
            'clear' => $this->executeClear(),
            'add_root' => $this->executeAddRoot($input),
            'remove_root' => $this->executeRemoveRoot($input),
            'list_roots' => $this->executeListRoots(),
            default => ToolResult::error(sprintf('Unknown action "%s". Use: build, update, status, clear, add_root, remove_root, list_roots.', $action)),
        };
    }

    private function executeBuild(): ToolResult
    {
        try {
            $roots = $this->index->getSearchRoots();
            if ($roots === []) {
                return ToolResult::error('No search roots configured. Add a root first: code_index(action: "add_root", path: "/path/to/code")');
            }

            $stats = $this->index->fullBuild($roots);

            return ToolResult::success(json_encode([
                'action' => 'build',
                'result' => 'Index built successfully.',
                'stats' => $stats->toArray(),
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
        } catch (\Throwable $e) {
            return ToolResult::error('Index build failed: ' . $e->getMessage());
        }
    }

    private function executeUpdate(): ToolResult
    {
        try {
            $roots = $this->index->getSearchRoots();
            if ($roots === []) {
                return ToolResult::error('No search roots configured. Add a root first: code_index(action: "add_root", path: "/path/to/code")');
            }

            $stats = $this->index->incrementalUpdate($roots);

            if ($stats->filesIndexed === 0 && $stats->filesDeleted === 0) {
                return ToolResult::success(json_encode([
                    'action' => 'update',
                    'result' => 'Index is up to date — no changes detected.',
                    'stats' => $stats->toArray(),
                ], JSON_UNESCAPED_SLASHES) ?: '{}');
            }

            return ToolResult::success(json_encode([
                'action' => 'update',
                'result' => 'Index updated.',
                'stats' => $stats->toArray(),
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
        } catch (\Throwable $e) {
            return ToolResult::error('Index update failed: ' . $e->getMessage());
        }
    }

    private function executeStatus(): ToolResult
    {
        try {
            $stats = $this->index->stats();

            return ToolResult::success(json_encode([
                'action' => 'status',
                ...$stats,
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
        } catch (\Throwable $e) {
            return ToolResult::error('Failed to get index status: ' . $e->getMessage());
        }
    }

    private function executeClear(): ToolResult
    {
        try {
            $this->index->clear();

            return ToolResult::success(json_encode([
                'action' => 'clear',
                'result' => 'Index cleared. Run code_index(action: "build") to rebuild.',
            ], JSON_UNESCAPED_SLASHES) ?: '{}');
        } catch (\Throwable $e) {
            return ToolResult::error('Failed to clear index: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function executeAddRoot(array $input): ToolResult
    {
        $path = trim((string) ($input['path'] ?? ''));
        if ($path === '') {
            return ToolResult::error('The "path" parameter is required for add_root.');
        }

        if (!is_dir($path)) {
            return ToolResult::error(sprintf('Directory "%s" does not exist.', $path));
        }

        $realPath = realpath($path);
        if ($realPath === false) {
            return ToolResult::error(sprintf('Cannot resolve path "%s".', $path));
        }

        try {
            $this->index->addSearchRoot($realPath);

            return ToolResult::success(json_encode([
                'action' => 'add_root',
                'result' => sprintf('Added search root: %s', $realPath),
                'roots' => $this->index->getSearchRoots(),
            ], JSON_UNESCAPED_SLASHES) ?: '{}');
        } catch (\Throwable $e) {
            return ToolResult::error('Failed to add search root: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function executeRemoveRoot(array $input): ToolResult
    {
        $path = trim((string) ($input['path'] ?? ''));
        if ($path === '') {
            return ToolResult::error('The "path" parameter is required for remove_root.');
        }

        try {
            $this->index->removeSearchRoot($path);

            return ToolResult::success(json_encode([
                'action' => 'remove_root',
                'result' => sprintf('Removed search root: %s', $path),
                'roots' => $this->index->getSearchRoots(),
            ], JSON_UNESCAPED_SLASHES) ?: '{}');
        } catch (\Throwable $e) {
            return ToolResult::error('Failed to remove search root: ' . $e->getMessage());
        }
    }

    private function executeListRoots(): ToolResult
    {
        try {
            $roots = $this->index->getSearchRoots();

            return ToolResult::success(json_encode([
                'action' => 'list_roots',
                'roots' => $roots,
                'count' => count($roots),
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
        } catch (\Throwable $e) {
            return ToolResult::error('Failed to list search roots: ' . $e->getMessage());
        }
    }
}
