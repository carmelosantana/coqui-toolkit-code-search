<?php

declare(strict_types=1);

use CoquiBot\Toolkits\CodeSearch\Contract\IndexStats;
use CoquiBot\Toolkits\CodeSearch\Contract\ChangeSet;

test('IndexStats toArray includes all fields', function () {
    $stats = new IndexStats(
        filesIndexed: 100,
        symbolsIndexed: 500,
        filesAdded: 10,
        filesModified: 5,
        filesDeleted: 3,
        duration: 1.2345,
    );

    $array = $stats->toArray();

    expect($array)->toBe([
        'files_indexed' => 100,
        'symbols_indexed' => 500,
        'files_added' => 10,
        'files_modified' => 5,
        'files_deleted' => 3,
        'duration_seconds' => 1.235,
    ]);
});

test('IndexStats defaults to zero for optional fields', function () {
    $stats = new IndexStats(filesIndexed: 50, symbolsIndexed: 200);

    expect($stats->filesAdded)->toBe(0);
    expect($stats->filesModified)->toBe(0);
    expect($stats->filesDeleted)->toBe(0);
    expect($stats->duration)->toBe(0.0);
});

test('ChangeSet isEmpty returns true when empty', function () {
    $cs = new ChangeSet();

    expect($cs->isEmpty())->toBeTrue();
    expect($cs->totalChanges())->toBe(0);
});

test('ChangeSet isEmpty returns false when has changes', function () {
    $cs = new ChangeSet(
        added: [['path' => 'a.php', 'mtime' => 100]],
    );

    expect($cs->isEmpty())->toBeFalse();
    expect($cs->totalChanges())->toBe(1);
});

test('ChangeSet totalChanges sums all categories', function () {
    $cs = new ChangeSet(
        added: [
            ['path' => 'a.php', 'mtime' => 100],
            ['path' => 'b.php', 'mtime' => 101],
        ],
        modified: [['path' => 'c.php', 'mtime' => 102]],
        deleted: ['d.php', 'e.php', 'f.php'],
    );

    expect($cs->totalChanges())->toBe(6);
});
