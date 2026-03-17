<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeSearch\Contract;

/**
 * A single symbol extracted from ctags JSON output.
 */
final readonly class TagEntry
{
    public function __construct(
        public string $name,
        public string $path,
        public string $kind,
        public string $language,
        public int $line,
        public ?int $endLine = null,
        public ?string $signature = null,
        public ?string $scope = null,
        public ?string $scopeKind = null,
        public ?string $pattern = null,
        public ?string $access = null,
    ) {}

    /**
     * Create from a decoded ctags JSON line.
     *
     * @param array<string, mixed> $data
     */
    public static function fromCtagsJson(array $data): ?self
    {
        if (($data['_type'] ?? '') !== 'tag') {
            return null;
        }

        $name = (string) ($data['name'] ?? '');
        $path = (string) ($data['path'] ?? '');

        if ($name === '' || $path === '') {
            return null;
        }

        return new self(
            name: $name,
            path: $path,
            kind: (string) ($data['kind'] ?? 'unknown'),
            language: (string) ($data['language'] ?? 'unknown'),
            line: (int) ($data['line'] ?? 0),
            endLine: isset($data['end']) ? (int) $data['end'] : null,
            signature: isset($data['signature']) ? (string) $data['signature'] : null,
            scope: isset($data['scope']) ? (string) $data['scope'] : null,
            scopeKind: isset($data['scopeKind']) ? (string) $data['scopeKind'] : null,
            pattern: isset($data['pattern']) ? (string) $data['pattern'] : null,
            access: isset($data['access']) ? (string) $data['access'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'name' => $this->name,
            'path' => $this->path,
            'kind' => $this->kind,
            'language' => $this->language,
            'line' => $this->line,
        ];

        if ($this->endLine !== null) {
            $result['end_line'] = $this->endLine;
        }
        if ($this->signature !== null) {
            $result['signature'] = $this->signature;
        }
        if ($this->scope !== null) {
            $result['scope'] = $this->scope;
        }
        if ($this->scopeKind !== null) {
            $result['scope_kind'] = $this->scopeKind;
        }
        if ($this->access !== null) {
            $result['access'] = $this->access;
        }

        return $result;
    }
}
