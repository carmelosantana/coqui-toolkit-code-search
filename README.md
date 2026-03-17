# Coqui Toolkit: Code Search

A code search toolkit for [Coqui](https://github.com/coquibot/coqui) that provides fast full-text search, symbol lookup, and file discovery powered by [ripgrep](https://github.com/BurntSushi/ripgrep) and [universal-ctags](https://github.com/universal-ctags/ctags).

## Requirements

| Dependency | Version | Purpose |
|-----------|---------|---------|
| PHP | 8.4+ | Runtime |
| ripgrep (`rg`) | Any | Text/regex search |
| universal-ctags (`ctags`) | Any | Symbol extraction |

### Installing System Dependencies

**Debian / Ubuntu:**

```bash
sudo apt install ripgrep universal-ctags
```

**macOS (Homebrew):**

```bash
brew install ripgrep universal-ctags
```

**Arch Linux:**

```bash
sudo pacman -S ripgrep ctags
```

## Installation

```bash
composer require coquibot/coqui-toolkit-code-search
```

Coqui discovers the toolkit automatically via `extra.php-agents.toolkits` in `composer.json`. No manual registration needed.

## Tools

### `code_search`

Full-text search through file contents using ripgrep. Ideal for finding specific strings, patterns, or code snippets.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `query` | string | Yes | — | Search pattern (text or regex) |
| `path` | string | No | workspace | Directory or file to search within |
| `file_type` | string | No | — | Restrict to file type (e.g. `php`, `js`, `python`) |
| `is_regex` | boolean | No | `false` | Treat query as a regex pattern |
| `context_lines` | integer | No | `2` | Lines of context around matches (0-10) |
| `max_results` | integer | No | `50` | Maximum matches to return (1-200) |
| `case_sensitive` | boolean | No | `false` | Enable case-sensitive matching |
| `word_match` | boolean | No | `false` | Match whole words only |
| `include_hidden` | boolean | No | `false` | Include hidden files/directories |
| `glob` | string | No | — | File glob pattern (e.g. `*.php`, `src/**/*.ts`) |

### `symbol_search`

Search the symbol index for classes, functions, methods, constants, and other named entities across all indexed languages.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `name` | string | Yes | — | Symbol name to search for |
| `kind` | string | No | — | Filter by kind: `class`, `function`, `method`, `variable`, `constant`, `interface`, `trait`, `enum`, `property`, `namespace` |
| `language` | string | No | — | Filter by language (e.g. `PHP`, `JavaScript`) |
| `scope` | string | No | — | Parent scope (e.g. class name for methods) |
| `exact` | boolean | No | `false` | Require exact name match |
| `limit` | integer | No | `30` | Maximum results (1-100) |

### `file_structure`

Display the symbol outline of a file — classes, methods, functions, properties — organized hierarchically. Useful for understanding file organization before reading it.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `path` | string | Yes | — | File path (relative to workspace) |
| `kind` | string | No | — | Filter to specific symbol kind |

### `search_files`

Find files by name pattern. Uses ripgrep's `--files` mode for fast filesystem traversal.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `pattern` | string | Yes | — | Glob pattern (e.g. `*.php`, `Test*.js`) |
| `file_type` | string | No | — | Restrict to file type |
| `path` | string | No | workspace | Directory to search within |
| `max_results` | integer | No | `50` | Maximum files to return (1-200) |

### `code_index`

Manage the persistent symbol index. Build, update, clear, or check status. Supports multiple search roots.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `action` | enum | Yes | — | One of: `build`, `update`, `status`, `clear`, `add_root`, `remove_root`, `list_roots` |
| `path` | string | No | — | Required for `add_root` and `remove_root` actions |

## Index Management

The toolkit maintains a SQLite index at `{workspace}/code-search/index.db` for fast symbol lookups. The index is built lazily on the first `symbol_search` or `file_structure` call.

### Index Actions

- **`build`** — Full rebuild: scans all files, extracts symbols with ctags, stores in SQLite.
- **`update`** — Incremental: only re-indexes files that changed since the last build (based on mtime comparison).
- **`status`** — Show index statistics: file count, symbol count, languages, search roots.
- **`clear`** — Delete all index data.
- **`add_root`** — Add a directory to the search roots for indexing.
- **`remove_root`** — Remove a directory from search roots.
- **`list_roots`** — Show all configured search roots.

### Multi-Root Support

By default, the workspace directory is the only search root. Additional directories can be added:

```
code_index(action: "add_root", path: "/path/to/project")
code_index(action: "update")
```

Indexed paths use the format `{root}::{relative_path}` internally to disambiguate files across roots.

## Architecture

```
src/
├── CodeSearchToolkit.php           # Main toolkit — tools() + guidelines()
├── Contract/
│   ├── ChangeSet.php               # Added/modified/deleted file lists
│   ├── IndexStats.php              # Index statistics value object
│   └── TagEntry.php                # Symbol entry from ctags
├── Exception/
│   ├── BinaryNotFoundException.php # Missing rg/ctags
│   └── IndexException.php         # SQLite/indexing errors
├── Index/
│   ├── CodeIndex.php               # Orchestrates indexing + search
│   └── IncrementalIndexer.php      # Filesystem change detection
├── Runtime/
│   ├── BinaryResolver.php          # Locates rg/ctags binaries
│   ├── CtagsRunner.php             # Runs ctags, parses JSON output
│   ├── ProcessResult.php           # Process execution result VO
│   └── RipgrepRunner.php           # Runs rg, parses JSON output
├── Storage/
│   └── IndexDatabase.php           # SQLite schema + queries
└── Tool/
    ├── CodeIndexTool.php           # code_index tool
    ├── CodeSearchTool.php          # code_search tool
    ├── FileStructureTool.php       # file_structure tool
    ├── SearchFilesTool.php         # search_files tool
    └── SymbolSearchTool.php        # symbol_search tool
```

## Supported Languages

The index recognizes 30+ file extensions including PHP, JavaScript, TypeScript, Python, Ruby, Go, Rust, Java, C/C++, C#, Swift, Kotlin, Scala, Shell, Lua, Perl, R, SQL, CSS/SCSS, HTML, Vue, Svelte, JSON, YAML, TOML, XML, Markdown, GraphQL, and Protobuf.

ctags itself supports 100+ languages — any file type ctags recognizes will have its symbols extracted.

## Development

```bash
# Install dependencies
composer install

# Run tests
composer test

# Static analysis
vendor/bin/phpstan analyse
```

## License

MIT
