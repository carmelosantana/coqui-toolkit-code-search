<?php

declare(strict_types=1);

use CoquiBot\Toolkits\CodeSearch\Contract\TagEntry;

test('creates tag entry from valid ctags JSON', function () {
    $data = [
        '_type' => 'tag',
        'name' => 'CodeSearchToolkit',
        'path' => 'src/CodeSearchToolkit.php',
        'kind' => 'class',
        'language' => 'PHP',
        'line' => 42,
        'end' => 120,
        'signature' => null,
        'scope' => null,
        'scopeKind' => null,
    ];

    $entry = TagEntry::fromCtagsJson($data);

    expect($entry)->not->toBeNull();
    expect($entry->name)->toBe('CodeSearchToolkit');
    expect($entry->path)->toBe('src/CodeSearchToolkit.php');
    expect($entry->kind)->toBe('class');
    expect($entry->language)->toBe('PHP');
    expect($entry->line)->toBe(42);
    expect($entry->endLine)->toBe(120);
});

test('creates tag entry with method scope', function () {
    $data = [
        '_type' => 'tag',
        'name' => 'execute',
        'path' => 'src/Tool/CodeSearchTool.php',
        'kind' => 'method',
        'language' => 'PHP',
        'line' => 80,
        'end' => 120,
        'signature' => '(array $input): ToolResult',
        'scope' => 'CodeSearchTool',
        'scopeKind' => 'class',
        'access' => 'private',
    ];

    $entry = TagEntry::fromCtagsJson($data);

    expect($entry)->not->toBeNull();
    expect($entry->name)->toBe('execute');
    expect($entry->kind)->toBe('method');
    expect($entry->signature)->toBe('(array $input): ToolResult');
    expect($entry->scope)->toBe('CodeSearchTool');
    expect($entry->scopeKind)->toBe('class');
    expect($entry->access)->toBe('private');
});

test('returns null for pseudo-tag entries', function () {
    $data = [
        '_type' => 'ptag',
        'name' => '!_TAG_FILE_FORMAT',
        'path' => '',
    ];

    expect(TagEntry::fromCtagsJson($data))->toBeNull();
});

test('returns null for entries with empty name', function () {
    $data = [
        '_type' => 'tag',
        'name' => '',
        'path' => 'src/Foo.php',
        'kind' => 'class',
        'language' => 'PHP',
        'line' => 1,
    ];

    expect(TagEntry::fromCtagsJson($data))->toBeNull();
});

test('returns null for entries with empty path', function () {
    $data = [
        '_type' => 'tag',
        'name' => 'Foo',
        'path' => '',
        'kind' => 'class',
        'language' => 'PHP',
        'line' => 1,
    ];

    expect(TagEntry::fromCtagsJson($data))->toBeNull();
});

test('toArray includes only non-null optional fields', function () {
    $entry = new TagEntry(
        name: 'myFunction',
        path: 'src/helpers.php',
        kind: 'function',
        language: 'PHP',
        line: 10,
    );

    $array = $entry->toArray();

    expect($array)->toBe([
        'name' => 'myFunction',
        'path' => 'src/helpers.php',
        'kind' => 'function',
        'language' => 'PHP',
        'line' => 10,
    ]);
    expect($array)->not->toHaveKey('end_line');
    expect($array)->not->toHaveKey('signature');
    expect($array)->not->toHaveKey('scope');
});

test('toArray includes all fields when populated', function () {
    $entry = new TagEntry(
        name: 'render',
        path: 'src/View.php',
        kind: 'method',
        language: 'PHP',
        line: 25,
        endLine: 50,
        signature: '(string $template): string',
        scope: 'View',
        scopeKind: 'class',
        access: 'public',
    );

    $array = $entry->toArray();

    expect($array)->toHaveKey('end_line', 50);
    expect($array)->toHaveKey('signature', '(string $template): string');
    expect($array)->toHaveKey('scope', 'View');
    expect($array)->toHaveKey('scope_kind', 'class');
    expect($array)->toHaveKey('access', 'public');
});
