<?php

declare(strict_types=1);

use CoquiBot\Toolkits\CodeSearch\Index\IncrementalIndexer;
use CoquiBot\Toolkits\CodeSearch\Contract\ChangeSet;

test('scanDirectory finds source files', function () {
    $tmpDir = sys_get_temp_dir() . '/coqui-indexer-test-' . uniqid();
    mkdir($tmpDir . '/src', 0o755, true);
    file_put_contents($tmpDir . '/src/Foo.php', '<?php class Foo {}');
    file_put_contents($tmpDir . '/src/Bar.js', 'function bar() {}');
    file_put_contents($tmpDir . '/src/ignored.bin', 'binary data');

    $indexer = new IncrementalIndexer();
    $files = $indexer->scanDirectory($tmpDir);

    expect($files)->toHaveKey('src/Foo.php');
    expect($files)->toHaveKey('src/Bar.js');
    expect($files)->not->toHaveKey('src/ignored.bin');

    // Cleanup
    unlink($tmpDir . '/src/Foo.php');
    unlink($tmpDir . '/src/Bar.js');
    unlink($tmpDir . '/src/ignored.bin');
    rmdir($tmpDir . '/src');
    rmdir($tmpDir);
});

test('scanDirectory skips vendor and node_modules', function () {
    $tmpDir = sys_get_temp_dir() . '/coqui-indexer-test-' . uniqid();
    mkdir($tmpDir . '/src', 0o755, true);
    mkdir($tmpDir . '/vendor/foo', 0o755, true);
    mkdir($tmpDir . '/node_modules/bar', 0o755, true);
    file_put_contents($tmpDir . '/src/App.php', '<?php');
    file_put_contents($tmpDir . '/vendor/foo/Lib.php', '<?php');
    file_put_contents($tmpDir . '/node_modules/bar/index.js', '');

    $indexer = new IncrementalIndexer();
    $files = $indexer->scanDirectory($tmpDir);

    expect($files)->toHaveKey('src/App.php');
    expect($files)->not->toHaveKey('vendor/foo/Lib.php');
    expect($files)->not->toHaveKey('node_modules/bar/index.js');

    // Cleanup
    unlink($tmpDir . '/src/App.php');
    unlink($tmpDir . '/vendor/foo/Lib.php');
    unlink($tmpDir . '/node_modules/bar/index.js');
    rmdir($tmpDir . '/node_modules/bar');
    rmdir($tmpDir . '/node_modules');
    rmdir($tmpDir . '/vendor/foo');
    rmdir($tmpDir . '/vendor');
    rmdir($tmpDir . '/src');
    rmdir($tmpDir);
});

test('detectChanges identifies added files', function () {
    $tmpDir = sys_get_temp_dir() . '/coqui-indexer-test-' . uniqid();
    mkdir($tmpDir . '/src', 0o755, true);
    file_put_contents($tmpDir . '/src/New.php', '<?php');

    $indexer = new IncrementalIndexer();
    $changes = $indexer->detectChanges($tmpDir, []);

    expect($changes->added)->toHaveCount(1);
    expect($changes->added[0]['path'])->toBe('src/New.php');
    expect($changes->modified)->toBeEmpty();
    expect($changes->deleted)->toBeEmpty();

    unlink($tmpDir . '/src/New.php');
    rmdir($tmpDir . '/src');
    rmdir($tmpDir);
});

test('detectChanges identifies deleted files', function () {
    $tmpDir = sys_get_temp_dir() . '/coqui-indexer-test-' . uniqid();
    mkdir($tmpDir, 0o755, true);

    $indexer = new IncrementalIndexer();
    $changes = $indexer->detectChanges($tmpDir, [
        'src/Deleted.php' => 1000,
    ]);

    expect($changes->added)->toBeEmpty();
    expect($changes->modified)->toBeEmpty();
    expect($changes->deleted)->toBe(['src/Deleted.php']);

    rmdir($tmpDir);
});

test('detectChanges identifies modified files', function () {
    $tmpDir = sys_get_temp_dir() . '/coqui-indexer-test-' . uniqid();
    mkdir($tmpDir . '/src', 0o755, true);
    file_put_contents($tmpDir . '/src/Changed.php', '<?php // modified');

    $indexer = new IncrementalIndexer();
    $currentMtime = filemtime($tmpDir . '/src/Changed.php');

    $changes = $indexer->detectChanges($tmpDir, [
        'src/Changed.php' => $currentMtime - 100, // Old mtime
    ]);

    expect($changes->modified)->toHaveCount(1);
    expect($changes->modified[0]['path'])->toBe('src/Changed.php');
    expect($changes->added)->toBeEmpty();
    expect($changes->deleted)->toBeEmpty();

    unlink($tmpDir . '/src/Changed.php');
    rmdir($tmpDir . '/src');
    rmdir($tmpDir);
});

test('detectLanguage maps common extensions', function () {
    expect(IncrementalIndexer::detectLanguage('Foo.php'))->toBe('PHP');
    expect(IncrementalIndexer::detectLanguage('app.js'))->toBe('JavaScript');
    expect(IncrementalIndexer::detectLanguage('main.ts'))->toBe('TypeScript');
    expect(IncrementalIndexer::detectLanguage('script.py'))->toBe('Python');
    expect(IncrementalIndexer::detectLanguage('main.go'))->toBe('Go');
    expect(IncrementalIndexer::detectLanguage('lib.rs'))->toBe('Rust');
    expect(IncrementalIndexer::detectLanguage('style.css'))->toBe('CSS');
    expect(IncrementalIndexer::detectLanguage('page.html'))->toBe('HTML');
    expect(IncrementalIndexer::detectLanguage('data.json'))->toBe('JSON');
    expect(IncrementalIndexer::detectLanguage('config.yaml'))->toBe('YAML');
});

test('empty ChangeSet reports isEmpty correctly', function () {
    $cs = new ChangeSet();
    expect($cs->isEmpty())->toBeTrue();
    expect($cs->totalChanges())->toBe(0);
});
