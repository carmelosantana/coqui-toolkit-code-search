<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeSearch\Contract;

/**
 * Represents the set of filesystem changes detected by incremental indexing.
 */
final readonly class ChangeSet
{
    /**
     * @param list<array{path: string, mtime: int}> $added    New files not in the index
     * @param list<array{path: string, mtime: int}> $modified Files whose mtime changed
     * @param list<string>                           $deleted  Files in the index but not on disk
     */
    public function __construct(
        public array $added = [],
        public array $modified = [],
        public array $deleted = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->added === [] && $this->modified === [] && $this->deleted === [];
    }

    public function totalChanges(): int
    {
        return count($this->added) + count($this->modified) + count($this->deleted);
    }
}
