<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeSearch\Runtime;

/**
 * Result of a process execution.
 */
final readonly class ProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}

    public function isSuccess(): bool
    {
        return $this->exitCode === 0;
    }

    public function isTimeout(): bool
    {
        return $this->exitCode === 124;
    }
}
