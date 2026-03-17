<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeSearch\Runtime;

use CoquiBot\Toolkits\CodeSearch\Exception\BinaryNotFoundException;

/**
 * Resolves external binary paths and caches the result.
 *
 * Used by RipgrepRunner and CtagsRunner to locate their respective
 * CLI tools on the system PATH.
 */
final class BinaryResolver
{
    /** @var array<string, string> */
    private array $cache = [];

    /**
     * Resolve the absolute path to a binary.
     *
     * @throws BinaryNotFoundException If the binary cannot be found.
     */
    public function resolve(string $name): string
    {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        $path = $this->which($name);

        if ($path === '') {
            throw BinaryNotFoundException::forBinary($name);
        }

        $this->cache[$name] = $path;

        return $path;
    }

    /**
     * Check if a binary is available without throwing.
     */
    public function isAvailable(string $name): bool
    {
        try {
            $this->resolve($name);
            return true;
        } catch (BinaryNotFoundException) {
            return false;
        }
    }

    /**
     * Resolve with a specific fallback exception for known binaries.
     */
    public function resolveRipgrep(): string
    {
        if (isset($this->cache['rg'])) {
            return $this->cache['rg'];
        }

        $path = $this->which('rg');

        if ($path === '') {
            throw BinaryNotFoundException::ripgrep();
        }

        $this->cache['rg'] = $path;

        return $path;
    }

    /**
     * Resolve with a specific fallback exception for ctags.
     */
    public function resolveCtags(): string
    {
        if (isset($this->cache['ctags'])) {
            return $this->cache['ctags'];
        }

        // Try universal-ctags first, then fall back to plain ctags
        foreach (['ctags', 'ctags-universal'] as $name) {
            $path = $this->which($name);
            if ($path !== '') {
                $this->cache['ctags'] = $path;
                return $path;
            }
        }

        throw BinaryNotFoundException::ctags();
    }

    private function which(string $name): string
    {
        $result = trim((string) shell_exec(sprintf('which %s 2>/dev/null', escapeshellarg($name))));

        return ($result !== '' && file_exists($result)) ? $result : '';
    }
}
