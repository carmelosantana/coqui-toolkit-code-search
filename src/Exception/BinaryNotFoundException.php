<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeSearch\Exception;

/**
 * Thrown when a required external binary (rg, ctags) is not found on PATH.
 */
final class BinaryNotFoundException extends \RuntimeException
{
    public static function forBinary(string $name, string $hint = ''): self
    {
        $message = sprintf('Binary "%s" not found on PATH.', $name);

        if ($hint !== '') {
            $message .= ' ' . $hint;
        }

        return new self($message);
    }

    public static function ripgrep(): self
    {
        return self::forBinary(
            'rg',
            'Install ripgrep: https://github.com/BurntSushi/ripgrep#installation (e.g. sudo apt install ripgrep)',
        );
    }

    public static function ctags(): self
    {
        return self::forBinary(
            'ctags',
            'Install universal-ctags: https://github.com/universal-ctags/ctags#how-to-build-and-install (e.g. sudo apt install universal-ctags)',
        );
    }
}
