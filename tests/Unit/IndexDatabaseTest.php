<?php

declare(strict_types=1);

use CoquiBot\Toolkits\CodeSearch\Storage\IndexDatabase;
use CoquiBot\Toolkits\CodeSearch\Contract\TagEntry;

test('creates database and tables on connection', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-test-index-' . uniqid() . '.db';

    $db = new IndexDatabase($dbPath);
    $conn = $db->connection();

    expect(file_exists($dbPath))->toBeTrue();

    // Verify tables exist
    $tables = $conn->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

    expect($tables)->toContain('files');
    expect($tables)->toContain('symbols');
    expect($tables)->toContain('index_meta');

    unlink($dbPath);
});

test('inserts and retrieves file records', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-test-index-' . uniqid() . '.db';
    $db = new IndexDatabase($dbPath);

    $fileId = $db->insertFile('src/Foo.php', 'PHP', 1024, 1700000000);

    expect($fileId)->toBeGreaterThan(0);

    $file = $db->getFile('src/Foo.php');

    expect($file)->not->toBeNull();
    expect($file['path'])->toBe('src/Foo.php');
    expect($file['language'])->toBe('PHP');
    expect($file['size_bytes'])->toBe(1024);
    expect($file['mtime'])->toBe(1700000000);

    unlink($dbPath);
});

test('getAllFileMtimes returns all files with mtimes', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-test-index-' . uniqid() . '.db';
    $db = new IndexDatabase($dbPath);

    $db->insertFile('src/A.php', 'PHP', 100, 1000);
    $db->insertFile('src/B.php', 'PHP', 200, 2000);

    $mtimes = $db->getAllFileMtimes();

    expect($mtimes)->toBe([
        'src/A.php' => 1000,
        'src/B.php' => 2000,
    ]);

    unlink($dbPath);
});

test('inserts and searches symbols', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-test-index-' . uniqid() . '.db';
    $db = new IndexDatabase($dbPath);

    $fileId = $db->insertFile('src/Foo.php', 'PHP', 500, 1000);

    $entries = [
        new TagEntry('Foo', 'src/Foo.php', 'class', 'PHP', 5, 50),
        new TagEntry('bar', 'src/Foo.php', 'method', 'PHP', 10, 20, '(): void', 'Foo', 'class', null, 'public'),
        new TagEntry('baz', 'src/Foo.php', 'method', 'PHP', 25, 40, '(string $x): string', 'Foo', 'class', null, 'private'),
    ];

    $count = $db->insertSymbols($fileId, $entries);
    expect($count)->toBe(3);

    // Search by name
    $results = $db->searchSymbols('Foo', exact: true);
    expect($results)->toHaveCount(1);
    expect($results[0]['name'])->toBe('Foo');
    expect($results[0]['kind'])->toBe('class');

    // Search partial
    $results = $db->searchSymbols('ba');
    expect($results)->toHaveCount(2);

    // Search by kind
    $results = $db->searchSymbols('ba', kind: 'method');
    expect($results)->toHaveCount(2);

    // Search by scope
    $results = $db->searchSymbols('bar', scope: 'Foo');
    expect($results)->toHaveCount(1);

    unlink($dbPath);
});

test('fileSymbols returns symbols ordered by line', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-test-index-' . uniqid() . '.db';
    $db = new IndexDatabase($dbPath);

    $fileId = $db->insertFile('src/View.php', 'PHP', 800, 1000);

    $db->insertSymbols($fileId, [
        new TagEntry('render', 'src/View.php', 'method', 'PHP', 30),
        new TagEntry('View', 'src/View.php', 'class', 'PHP', 5),
        new TagEntry('__construct', 'src/View.php', 'method', 'PHP', 10),
    ]);

    $symbols = $db->fileSymbols('src/View.php');

    expect($symbols)->toHaveCount(3);
    expect($symbols[0]['name'])->toBe('View');
    expect($symbols[1]['name'])->toBe('__construct');
    expect($symbols[2]['name'])->toBe('render');

    unlink($dbPath);
});

test('deleteFile cascade deletes symbols', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-test-index-' . uniqid() . '.db';
    $db = new IndexDatabase($dbPath);

    $fileId = $db->insertFile('src/Old.php', 'PHP', 200, 1000);
    $db->insertSymbols($fileId, [
        new TagEntry('OldClass', 'src/Old.php', 'class', 'PHP', 5),
    ]);

    $db->deleteFile('src/Old.php');

    expect($db->getFile('src/Old.php'))->toBeNull();

    $stats = $db->stats();
    expect($stats['symbol_count'])->toBe(0);

    unlink($dbPath);
});

test('meta get and set work correctly', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-test-index-' . uniqid() . '.db';
    $db = new IndexDatabase($dbPath);

    expect($db->getMeta('foo'))->toBeNull();

    $db->setMeta('foo', 'bar');
    expect($db->getMeta('foo'))->toBe('bar');

    // Upsert
    $db->setMeta('foo', 'baz');
    expect($db->getMeta('foo'))->toBe('baz');

    unlink($dbPath);
});

test('clear removes all data', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-test-index-' . uniqid() . '.db';
    $db = new IndexDatabase($dbPath);

    $fileId = $db->insertFile('src/X.php', 'PHP', 100, 1000);
    $db->insertSymbols($fileId, [
        new TagEntry('X', 'src/X.php', 'class', 'PHP', 1),
    ]);
    $db->setMeta('version', '1');

    $db->clear();

    $stats = $db->stats();
    expect($stats['file_count'])->toBe(0);
    expect($stats['symbol_count'])->toBe(0);
    expect($db->getMeta('version'))->toBeNull();

    unlink($dbPath);
});

test('stats returns correct counts and languages', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-test-index-' . uniqid() . '.db';
    $db = new IndexDatabase($dbPath);

    $id1 = $db->insertFile('src/A.php', 'PHP', 100, 1000);
    $id2 = $db->insertFile('src/b.js', 'JavaScript', 200, 2000);

    $db->insertSymbols($id1, [
        new TagEntry('A', 'src/A.php', 'class', 'PHP', 1),
        new TagEntry('run', 'src/A.php', 'method', 'PHP', 5),
    ]);
    $db->insertSymbols($id2, [
        new TagEntry('main', 'src/b.js', 'function', 'JavaScript', 1),
    ]);

    $stats = $db->stats();

    expect($stats['file_count'])->toBe(2);
    expect($stats['symbol_count'])->toBe(3);
    expect($stats['languages'])->toContain('PHP');
    expect($stats['languages'])->toContain('JavaScript');

    unlink($dbPath);
});
