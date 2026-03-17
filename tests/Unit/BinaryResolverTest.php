<?php

declare(strict_types=1);

use CoquiBot\Toolkits\CodeSearch\Exception\BinaryNotFoundException;
use CoquiBot\Toolkits\CodeSearch\Runtime\BinaryResolver;

test('resolves existing binary and caches it', function () {
    $resolver = new BinaryResolver();

    // 'ls' should exist on any Linux system
    $path = $resolver->resolve('ls');

    expect($path)->not->toBeEmpty();
    expect(file_exists($path))->toBeTrue();

    // Second call should return cached value
    $path2 = $resolver->resolve('ls');
    expect($path2)->toBe($path);
});

test('throws BinaryNotFoundException for missing binary', function () {
    $resolver = new BinaryResolver();

    $resolver->resolve('nonexistent_binary_that_should_never_exist');
})->throws(BinaryNotFoundException::class);

test('isAvailable returns true for existing binary', function () {
    $resolver = new BinaryResolver();

    expect($resolver->isAvailable('ls'))->toBeTrue();
});

test('isAvailable returns false for missing binary', function () {
    $resolver = new BinaryResolver();

    expect($resolver->isAvailable('nonexistent_binary_xyz'))->toBeFalse();
});

test('ripgrep exception includes installation hint', function () {
    $e = BinaryNotFoundException::ripgrep();

    expect($e->getMessage())->toContain('rg');
    expect($e->getMessage())->toContain('ripgrep');
});

test('ctags exception includes installation hint', function () {
    $e = BinaryNotFoundException::ctags();

    expect($e->getMessage())->toContain('ctags');
    expect($e->getMessage())->toContain('universal-ctags');
});
