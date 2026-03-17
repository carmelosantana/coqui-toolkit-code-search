<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeSearch\Storage;

use CoquiBot\Toolkits\CodeSearch\Contract\TagEntry;
use CoquiBot\Toolkits\CodeSearch\Exception\IndexException;

/**
 * SQLite database for the code search index.
 *
 * Manages the files, symbols, and index_meta tables. Uses WAL mode
 * for concurrent read performance and foreign keys with CASCADE deletes
 * so removing a file entry automatically cleans up its symbols.
 */
final class IndexDatabase
{
    private ?\PDO $pdo = null;

    public function __construct(
        private readonly string $dbPath,
    ) {}

    /**
     * Get or create the PDO connection.
     */
    public function connection(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $this->pdo = new \PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA foreign_keys=ON');
        $this->pdo->exec('PRAGMA synchronous=NORMAL');

        $this->ensureSchema();

        $pdo = $this->pdo;
        assert($pdo instanceof \PDO);

        return $pdo;
    }

    /**
     * Check if the database file exists and has been initialized.
     */
    public function exists(): bool
    {
        return file_exists($this->dbPath);
    }

    /**
     * Get a stored metadata value.
     */
    public function getMeta(string $key): ?string
    {
        $stmt = $this->connection()->prepare('SELECT value FROM index_meta WHERE key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? (string) $row['value'] : null;
    }

    /**
     * Set a metadata value.
     */
    public function setMeta(string $key, string $value): void
    {
        $this->connection()->prepare(
            'INSERT INTO index_meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value',
        )->execute([$key, $value]);
    }

    /**
     * Get the stored file record by path.
     *
     * @return array{id: int, path: string, language: string, size_bytes: int, mtime: int}|null
     */
    public function getFile(string $path): ?array
    {
        $stmt = $this->connection()->prepare('SELECT id, path, language, size_bytes, mtime FROM files WHERE path = ?');
        $stmt->execute([$path]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'path' => (string) $row['path'],
            'language' => (string) $row['language'],
            'size_bytes' => (int) $row['size_bytes'],
            'mtime' => (int) $row['mtime'],
        ];
    }

    /**
     * Get all indexed file paths with their mtimes.
     *
     * @return array<string, int> Map of path => mtime
     */
    public function getAllFileMtimes(): array
    {
        $stmt = $this->connection()->query('SELECT path, mtime FROM files');
        $result = [];

        if ($stmt === false) {
            return $result;
        }

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $result[(string) $row['path']] = (int) $row['mtime'];
            }
        }

        return $result;
    }

    /**
     * Insert a file record and return its ID.
     */
    public function insertFile(string $path, string $language, int $sizeBytes, int $mtime): int
    {
        $this->connection()->prepare(
            'INSERT INTO files (path, language, size_bytes, mtime, indexed_at) VALUES (?, ?, ?, ?, ?)',
        )->execute([$path, $language, $sizeBytes, $mtime, time()]);

        return (int) $this->connection()->lastInsertId();
    }

    /**
     * Delete a file record (and cascade-delete its symbols).
     */
    public function deleteFile(string $path): void
    {
        $this->connection()->prepare('DELETE FROM files WHERE path = ?')->execute([$path]);
    }

    /**
     * Delete multiple files by path.
     *
     * @param list<string> $paths
     */
    public function deleteFiles(array $paths): void
    {
        if ($paths === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($paths), '?'));
        $this->connection()->prepare(
            "DELETE FROM files WHERE path IN ({$placeholders})",
        )->execute($paths);
    }

    /**
     * Insert a batch of symbols for a file.
     *
     * @param list<TagEntry> $entries
     */
    public function insertSymbols(int $fileId, array $entries): int
    {
        $stmt = $this->connection()->prepare(
            'INSERT INTO symbols (file_id, name, kind, language, line, end_line, signature, scope, scope_kind, access, pattern)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );

        $count = 0;

        foreach ($entries as $entry) {
            $stmt->execute([
                $fileId,
                $entry->name,
                $entry->kind,
                $entry->language,
                $entry->line,
                $entry->endLine,
                $entry->signature,
                $entry->scope,
                $entry->scopeKind,
                $entry->access,
                $entry->pattern,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Search symbols by name with optional filters.
     *
     * @return list<array<string, mixed>>
     */
    public function searchSymbols(
        string $name,
        bool $exact = false,
        ?string $kind = null,
        ?string $language = null,
        ?string $scope = null,
        int $limit = 50,
    ): array {
        $conditions = [];
        $params = [];

        if ($exact) {
            $conditions[] = 's.name = ?';
            $params[] = $name;
        } else {
            $conditions[] = 's.name LIKE ?';
            $params[] = '%' . $name . '%';
        }

        if ($kind !== null && $kind !== '') {
            $conditions[] = 's.kind = ?';
            $params[] = $kind;
        }

        if ($language !== null && $language !== '') {
            $conditions[] = 's.language = ?';
            $params[] = $language;
        }

        if ($scope !== null && $scope !== '') {
            $conditions[] = 's.scope = ?';
            $params[] = $scope;
        }

        $where = implode(' AND ', $conditions);
        $params[] = $limit;

        $sql = <<<SQL
            SELECT s.name, s.kind, s.language, s.line, s.end_line, s.signature,
                   s.scope, s.scope_kind, s.access, f.path
            FROM symbols s
            JOIN files f ON s.file_id = f.id
            WHERE {$where}
            ORDER BY
                CASE WHEN s.name = ? THEN 0 ELSE 1 END,
                s.name,
                f.path,
                s.line
            LIMIT ?
        SQL;

        // Add the exact-match name for the ORDER BY case
        array_splice($params, -1, 0, [$name]);

        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);

        $results = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $results[] = [
                    'name' => $row['name'],
                    'kind' => $row['kind'],
                    'language' => $row['language'],
                    'line' => (int) $row['line'],
                    'end_line' => $row['end_line'] !== null ? (int) $row['end_line'] : null,
                    'signature' => $row['signature'],
                    'scope' => $row['scope'],
                    'scope_kind' => $row['scope_kind'],
                    'access' => $row['access'],
                    'path' => $row['path'],
                ];
            }
        }

        return $results;
    }

    /**
     * Get all symbols in a specific file, ordered by line number.
     *
     * @return list<array<string, mixed>>
     */
    public function fileSymbols(string $filePath, ?string $kind = null): array
    {
        $conditions = ['f.path = ?'];
        $params = [$filePath];

        if ($kind !== null && $kind !== '') {
            $conditions[] = 's.kind = ?';
            $params[] = $kind;
        }

        $where = implode(' AND ', $conditions);

        $stmt = $this->connection()->prepare(
            "SELECT s.name, s.kind, s.language, s.line, s.end_line, s.signature,
                    s.scope, s.scope_kind, s.access
             FROM symbols s
             JOIN files f ON s.file_id = f.id
             WHERE {$where}
             ORDER BY s.line",
        );
        $stmt->execute($params);

        $results = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $results[] = [
                    'name' => $row['name'],
                    'kind' => $row['kind'],
                    'language' => $row['language'],
                    'line' => (int) $row['line'],
                    'end_line' => $row['end_line'] !== null ? (int) $row['end_line'] : null,
                    'signature' => $row['signature'],
                    'scope' => $row['scope'],
                    'scope_kind' => $row['scope_kind'],
                    'access' => $row['access'],
                ];
            }
        }

        return $results;
    }

    /**
     * Get index statistics.
     *
     * @return array{file_count: int, symbol_count: int, languages: list<string>, last_indexed: string|null}
     */
    public function stats(): array
    {
        $db = $this->connection();

        $fileStmt = $db->query('SELECT COUNT(*) FROM files');
        $fileCount = $fileStmt !== false ? (int) $fileStmt->fetchColumn() : 0;

        $symbolStmt = $db->query('SELECT COUNT(*) FROM symbols');
        $symbolCount = $symbolStmt !== false ? (int) $symbolStmt->fetchColumn() : 0;

        $langStmt = $db->query('SELECT DISTINCT language FROM symbols ORDER BY language');
        $languages = [];

        if ($langStmt === false) {
            return [
                'file_count' => $fileCount,
                'symbol_count' => $symbolCount,
                'languages' => $languages,
                'last_indexed' => $this->getMeta('last_indexed_at'),
            ];
        }

        while ($row = $langStmt->fetch(\PDO::FETCH_ASSOC)) {
            if (is_array($row) && ($row['language'] ?? '') !== '') {
                $languages[] = (string) $row['language'];
            }
        }

        $lastIndexed = $this->getMeta('last_indexed_at');

        return [
            'file_count' => $fileCount,
            'symbol_count' => $symbolCount,
            'languages' => $languages,
            'last_indexed' => $lastIndexed,
        ];
    }

    /**
     * Drop all data and recreate tables.
     */
    public function clear(): void
    {
        $db = $this->connection();
        $db->exec('DELETE FROM symbols');
        $db->exec('DELETE FROM files');
        $db->exec('DELETE FROM index_meta');
    }

    /**
     * Begin a transaction.
     */
    public function beginTransaction(): void
    {
        $this->connection()->beginTransaction();
    }

    /**
     * Commit a transaction.
     */
    public function commit(): void
    {
        $this->connection()->commit();
    }

    /**
     * Roll back a transaction.
     */
    public function rollBack(): void
    {
        $this->connection()->rollBack();
    }

    private function ensureSchema(): void
    {
        $db = $this->connection();

        $db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS files (
                id          INTEGER PRIMARY KEY,
                path        TEXT NOT NULL UNIQUE,
                language    TEXT NOT NULL DEFAULT '',
                size_bytes  INTEGER NOT NULL DEFAULT 0,
                mtime       INTEGER NOT NULL DEFAULT 0,
                indexed_at  INTEGER NOT NULL DEFAULT 0
            )
        SQL);

        $db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS symbols (
                id          INTEGER PRIMARY KEY,
                file_id     INTEGER NOT NULL REFERENCES files(id) ON DELETE CASCADE,
                name        TEXT NOT NULL,
                kind        TEXT NOT NULL DEFAULT 'unknown',
                language    TEXT NOT NULL DEFAULT '',
                line        INTEGER NOT NULL DEFAULT 0,
                end_line    INTEGER,
                signature   TEXT,
                scope       TEXT,
                scope_kind  TEXT,
                access      TEXT,
                pattern     TEXT
            )
        SQL);

        $db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS index_meta (
                key   TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )
        SQL);

        // Create indexes (IF NOT EXISTS is not supported for indexes in all SQLite versions,
        // so we use a try/catch approach)
        $indexes = [
            'CREATE INDEX IF NOT EXISTS idx_files_path ON files(path)',
            'CREATE INDEX IF NOT EXISTS idx_symbols_name ON symbols(name)',
            'CREATE INDEX IF NOT EXISTS idx_symbols_file ON symbols(file_id)',
            'CREATE INDEX IF NOT EXISTS idx_symbols_kind ON symbols(kind)',
            'CREATE INDEX IF NOT EXISTS idx_symbols_scope ON symbols(scope, scope_kind)',
        ];

        foreach ($indexes as $sql) {
            $db->exec($sql);
        }
    }
}
