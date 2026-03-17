<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeSearch\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Toolkits\CodeSearch\Index\CodeIndex;

/**
 * Get the symbol outline of a specific file.
 *
 * Returns all symbols (classes, functions, methods, properties, etc.)
 * in a file, ordered by line number. Useful for understanding file
 * structure before reading or editing.
 */
final readonly class FileStructureTool
{
    public function __construct(
        private CodeIndex $index,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'file_structure',
            description: 'Get the symbol outline of a file — lists all classes, functions, methods, properties, etc. ordered by line number. Useful for understanding file organization before reading or editing.',
            parameters: [
                new StringParameter('path', 'File path (relative to search root).', required: true),
                new StringParameter('kind', 'Filter to specific symbol kind: class, function, method, interface, trait, enum, property, constant.', required: false),
            ],
            callback: fn(array $input): ToolResult => $this->execute($input),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(array $input): ToolResult
    {
        $path = trim((string) ($input['path'] ?? ''));
        if ($path === '') {
            return ToolResult::error('The "path" parameter is required.');
        }

        $kind = trim((string) ($input['kind'] ?? ''));

        try {
            $symbols = $this->index->fileSymbols(
                $path,
                $kind !== '' ? $kind : null,
            );
        } catch (\Throwable $e) {
            return ToolResult::error('File structure lookup failed: ' . $e->getMessage());
        }

        if ($symbols === []) {
            return ToolResult::success(json_encode([
                'path' => $path,
                'symbols' => [],
                'summary' => sprintf('No symbols found in "%s". The file may not be indexed yet — run code_index(action: "update").', $path),
            ], JSON_UNESCAPED_SLASHES) ?: '{}');
        }

        // Build a hierarchical outline
        $outline = $this->buildOutline($symbols);

        return ToolResult::success(json_encode([
            'path' => $path,
            'outline' => $outline,
            'symbol_count' => count($symbols),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
    }

    /**
     * Build a hierarchical outline from flat symbol list.
     *
     * Groups methods/properties under their parent class/interface/trait.
     *
     * @param list<array<string, mixed>> $symbols
     * @return list<array<string, mixed>>
     */
    private function buildOutline(array $symbols): array
    {
        $containers = []; // Classes, interfaces, traits, enums
        $topLevel = [];   // Functions, constants, standalone symbols

        // First pass: identify containers
        $containerNames = [];
        foreach ($symbols as $symbol) {
            $kind = (string) ($symbol['kind'] ?? '');
            if (in_array($kind, ['class', 'interface', 'trait', 'enum'], true)) {
                $containerNames[(string) $symbol['name']] = true;
            }
        }

        // Second pass: organize symbols
        foreach ($symbols as $symbol) {
            $kind = (string) ($symbol['kind'] ?? '');
            $scope = $symbol['scope'] ?? null;
            $name = (string) $symbol['name'];

            $entry = [
                'name' => $name,
                'kind' => $kind,
                'line' => $symbol['line'],
            ];

            if (($symbol['signature'] ?? null) !== null) {
                $entry['signature'] = $symbol['signature'];
            }
            if (($symbol['access'] ?? null) !== null) {
                $entry['access'] = $symbol['access'];
            }
            if (($symbol['end_line'] ?? null) !== null) {
                $entry['end_line'] = $symbol['end_line'];
            }

            // Is this a container?
            if (in_array($kind, ['class', 'interface', 'trait', 'enum'], true)) {
                $containers[$name] = [
                    ...$entry,
                    'members' => [],
                ];
            } elseif ($scope !== null && isset($containers[$scope])) {
                // This is a member of a container
                $containers[$scope]['members'][] = $entry;
            } else {
                $topLevel[] = $entry;
            }
        }

        // Merge containers and top-level into final output
        $result = [];

        foreach ($containers as $container) {
            $result[] = $container;
        }

        foreach ($topLevel as $item) {
            $result[] = $item;
        }

        // Sort by line number
        usort($result, static fn(array $a, array $b): int => ($a['line'] ?? 0) <=> ($b['line'] ?? 0));

        return $result;
    }
}
