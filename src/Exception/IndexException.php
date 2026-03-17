<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeSearch\Exception;

/**
 * Thrown for index corruption, schema issues, or indexing failures.
 */
final class IndexException extends \RuntimeException
{
    public static function databaseError(string $detail): self
    {
        return new self('Code search index error: ' . $detail);
    }

    public static function indexingFailed(string $file, string $reason): self
    {
        return new self(sprintf('Failed to index "%s": %s', $file, $reason));
    }
}
