<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeSearch\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Toolkits\CodeSearch\Index\CodeIndex;

/**
 * Find symbols (classes, functions, methods, etc.) from the ctags index.
 *
 * Queries the SQLite symbol index for fast lookups by name, kind,
 * language, or parent scope. Triggers lazy index build on first call.
 */
final readonly class SymbolSearchTool
{
    public function __construct(
        private CodeIndex $index,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'symbol_search',
            description: 'Find symbol definitions (classes, functions, methods, interfaces, etc.) by name. Queries the ctags index for fast, structured results. The index is built automatically on first use.',
            parameters: [
                new StringParameter('name', 'Symbol name to search for. Partial matches are supported unless exact=true.', required: true),
                new StringParameter('kind', 'Filter by symbol kind: class, function, method, interface, trait, enum, property, constant, variable, type, namespace, module.', required: false),
                new StringParameter('language', 'Filter by programming language (e.g. "PHP", "JavaScript", "Python", "Go").', required: false),
                new StringParameter('scope', 'Filter by parent scope name (e.g. class name for methods).', required: false),
                new BoolParameter('exact', 'Require exact name match (default: false — substring match).', required: false),
                new NumberParameter('limit', 'Maximum results to return (default: 30).', required: false, integer: true, minimum: 1, maximum: 100),
            ],
            callback: fn(array $input): ToolResult => $this->execute($input),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('The "name" parameter is required.');
        }

        $kind = trim((string) ($input['kind'] ?? ''));
        $language = trim((string) ($input['language'] ?? ''));
        $scope = trim((string) ($input['scope'] ?? ''));
        $exact = (bool) ($input['exact'] ?? false);
        $limit = (int) ($input['limit'] ?? 30);

        try {
            $symbols = $this->index->searchSymbols(
                name: $name,
                exact: $exact,
                kind: $kind !== '' ? $kind : null,
                language: $language !== '' ? $language : null,
                scope: $scope !== '' ? $scope : null,
                limit: $limit,
            );
        } catch (\Throwable $e) {
            return ToolResult::error('Symbol search failed: ' . $e->getMessage());
        }

        if ($symbols === []) {
            $hint = $exact
                ? sprintf('No symbol named exactly "%s" found.', $name)
                : sprintf('No symbols matching "%s" found.', $name);

            if ($kind !== '') {
                $hint .= sprintf(' (filtered to kind: %s)', $kind);
            }

            $hint .= ' Try a broader search or run code_index(action: "update") to refresh.';

            return ToolResult::success(json_encode([
                'symbols' => [],
                'summary' => $hint,
            ], JSON_UNESCAPED_SLASHES) ?: '{}');
        }

        // Format symbols for output
        $formatted = array_map(static function (array $symbol): array {
            $result = [
                'name' => $symbol['name'],
                'kind' => $symbol['kind'],
                'path' => self::displayPath($symbol['path'] ?? ''),
                'line' => $symbol['line'],
            ];

            if (($symbol['signature'] ?? null) !== null) {
                $result['signature'] = $symbol['signature'];
            }
            if (($symbol['scope'] ?? null) !== null) {
                $result['scope'] = $symbol['scope'];
            }
            if (($symbol['access'] ?? null) !== null) {
                $result['access'] = $symbol['access'];
            }

            return $result;
        }, $symbols);

        return ToolResult::success(json_encode([
            'symbols' => $formatted,
            'count' => count($formatted),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
    }

    /**
     * Extract the display path from a stored path (strip root prefix).
     */
    private static function displayPath(string $storedPath): string
    {
        // Stored paths use "root::relative" format
        $parts = explode('::', $storedPath, 2);

        return count($parts) === 2 ? $parts[1] : $storedPath;
    }
}
