<?php

declare(strict_types=1);

use CoquiBot\Toolkits\CodeSearch\CodeSearchToolkit;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;

test('toolkit implements ToolkitInterface', function () {
    expect(CodeSearchToolkit::class)->toImplement(ToolkitInterface::class);
});

test('toolkit returns five tools', function () {
    $tmpDir = sys_get_temp_dir() . '/coqui-toolkit-test-' . uniqid();
    mkdir($tmpDir, 0o755, true);

    $toolkit = new CodeSearchToolkit($tmpDir);
    $tools = $toolkit->tools();

    expect($tools)->toHaveCount(5);

    $names = array_map(fn($t) => $t->name(), $tools);

    expect($names)->toContain('code_search');
    expect($names)->toContain('symbol_search');
    expect($names)->toContain('file_structure');
    expect($names)->toContain('find_files');
    expect($names)->toContain('code_index');

    // Cleanup
    $dbPath = $tmpDir . '/code-search/index.db';
    if (file_exists($dbPath)) {
        unlink($dbPath);
    }
    if (is_dir($tmpDir . '/code-search')) {
        rmdir($tmpDir . '/code-search');
    }
    rmdir($tmpDir);
});

test('toolkit guidelines are not empty', function () {
    $tmpDir = sys_get_temp_dir() . '/coqui-toolkit-test-' . uniqid();
    mkdir($tmpDir, 0o755, true);

    $toolkit = new CodeSearchToolkit($tmpDir);

    expect($toolkit->guidelines())->not->toBeEmpty();
    expect($toolkit->guidelines())->toContain('CODE-SEARCH-GUIDELINES');

    rmdir($tmpDir);
});

test('all tools produce valid function schemas', function () {
    $tmpDir = sys_get_temp_dir() . '/coqui-toolkit-test-' . uniqid();
    mkdir($tmpDir, 0o755, true);

    $toolkit = new CodeSearchToolkit($tmpDir);

    foreach ($toolkit->tools() as $tool) {
        $schema = $tool->toFunctionSchema();

        expect($schema)->toHaveKey('type', 'function');
        expect($schema)->toHaveKey('function');
        expect($schema['function'])->toHaveKey('name');
        expect($schema['function'])->toHaveKey('description');
        expect($schema['function'])->toHaveKey('parameters');
        expect($schema['function']['name'])->not->toBeEmpty();
        expect($schema['function']['description'])->not->toBeEmpty();
    }

    // Cleanup
    $dbPath = $tmpDir . '/code-search/index.db';
    if (file_exists($dbPath)) {
        unlink($dbPath);
    }
    if (is_dir($tmpDir . '/code-search')) {
        rmdir($tmpDir . '/code-search');
    }
    rmdir($tmpDir);
});
