<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeSearch\Contract;

/**
 * Statistics from an indexing operation.
 */
final readonly class IndexStats
{
    public function __construct(
        public int $filesIndexed,
        public int $symbolsIndexed,
        public int $filesAdded = 0,
        public int $filesModified = 0,
        public int $filesDeleted = 0,
        public float $duration = 0.0,
    ) {}

    /**
     * @return array<string, int|float>
     */
    public function toArray(): array
    {
        return [
            'files_indexed' => $this->filesIndexed,
            'symbols_indexed' => $this->symbolsIndexed,
            'files_added' => $this->filesAdded,
            'files_modified' => $this->filesModified,
            'files_deleted' => $this->filesDeleted,
            'duration_seconds' => round($this->duration, 3),
        ];
    }
}
