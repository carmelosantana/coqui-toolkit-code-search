<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeSearch;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\Toolkits\CodeSearch\Index\CodeIndex;
use CoquiBot\Toolkits\CodeSearch\Index\IncrementalIndexer;
use CoquiBot\Toolkits\CodeSearch\Runtime\BinaryResolver;
use CoquiBot\Toolkits\CodeSearch\Runtime\CtagsRunner;
use CoquiBot\Toolkits\CodeSearch\Runtime\RipgrepRunner;
use CoquiBot\Toolkits\CodeSearch\Storage\IndexDatabase;
use CoquiBot\Toolkits\CodeSearch\Tool\CodeIndexTool;
use CoquiBot\Toolkits\CodeSearch\Tool\CodeSearchTool;
use CoquiBot\Toolkits\CodeSearch\Tool\FileStructureTool;
use CoquiBot\Toolkits\CodeSearch\Tool\SearchFilesTool;
use CoquiBot\Toolkits\CodeSearch\Tool\SymbolSearchTool;

/**
 * Code search toolkit for Coqui.
 *
 * Provides five tools for searching and navigating codebases:
 * - code_search: text/regex content search via ripgrep
 * - symbol_search: find symbol definitions via ctags index
 * - file_structure: get symbol outline of a file
 * - find_files: find files by name/glob pattern
 * - code_index: manage the search index (build, update, roots)
 *
 * The toolkit wraps two external binaries (ripgrep, universal-ctags)
 * and maintains an incremental SQLite index for fast symbol lookups.
 * The index is built lazily on first symbol_search or file_structure
 * call and updated incrementally on subsequent calls.
 *
 * Auto-discovered by Coqui's ToolkitDiscovery when installed via Composer.
 */
final class CodeSearchToolkit implements ToolkitInterface
{
    private readonly RipgrepRunner $ripgrep;
    private readonly CtagsRunner $ctags;
    private readonly CodeIndex $index;

    public function __construct(
        private readonly string $workspacePath,
        ?RipgrepRunner $ripgrep = null,
        ?CtagsRunner $ctags = null,
        ?CodeIndex $index = null,
    ) {
        $resolver = new BinaryResolver();
        $this->ripgrep = $ripgrep ?? new RipgrepRunner($resolver);
        $this->ctags = $ctags ?? new CtagsRunner($resolver);
        $this->index = $index ?? new CodeIndex(
            db: new IndexDatabase($this->workspacePath . '/code-search/index.db'),
            ctags: $this->ctags,
            indexer: new IncrementalIndexer(),
            workspacePath: $this->workspacePath,
        );
    }

    /**
     * Factory method for ToolkitDiscovery — reads workspace path from environment.
     */
    public static function fromEnv(): self
    {
        $workspacePath = getenv('COQUI_WORKSPACE_PATH');
        if ($workspacePath === false || $workspacePath === '') {
            $workspacePath = getcwd() . '/.workspace';
        }

        return new self(workspacePath: $workspacePath);
    }

    public function tools(): array
    {
        return [
            (new CodeSearchTool($this->ripgrep, $this->workspacePath))->build(),
            (new SymbolSearchTool($this->index))->build(),
            (new FileStructureTool($this->index))->build(),
            (new SearchFilesTool($this->ripgrep, $this->workspacePath))->build(),
            (new CodeIndexTool($this->index))->build(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <CODE-SEARCH-GUIDELINES>
            ## Code Search Toolkit

            You have 5 tools for searching and navigating codebases. The toolkit uses ripgrep for
            text search and universal-ctags with a SQLite index for symbol lookups.

            ### Tool Selection Guide

            | Task | Tool | When to Use |
            |------|------|-------------|
            | Find text/patterns in files | `code_search` | Grep for strings, regex, function calls, imports, error messages |
            | Find symbol definitions | `symbol_search` | Look up classes, functions, methods, interfaces by name |
            | Understand file layout | `file_structure` | Get class/method outline before reading or editing a file |
            | Find files by name | `find_files` | Locate files by name pattern, glob, or language |
            | Manage the index | `code_index` | Build/update index, add search roots, check status |

            ### Search Strategy

            Follow a **progressive refinement** approach:

            1. **Start with `find_files`** to locate relevant files by name/pattern
            2. **Use `file_structure`** to understand the outline of key files
            3. **Use `symbol_search`** to find specific class, function, or method definitions
            4. **Use `code_search`** for full-text search when you need to find usage patterns, string literals, or complex regex matches

            ### Token Efficiency

            - **Prefer `symbol_search` over `code_search`** when looking for definitions — it returns structured data, not raw file content
            - **Use `file_structure`** instead of reading entire files to understand code organization
            - **Use `find_files`** instead of `code_search` when you only need to find file locations
            - **Limit `code_search` context** — use `context_lines: 0` or `1` when you only need the matching line
            - **Scope searches with `path`** parameter to avoid searching irrelevant directories

            ### Index Management

            The symbol index is **built lazily** — it auto-creates on first `symbol_search` or `file_structure` call.
            After that, it updates incrementally based on file modification times.

            - Use `code_index(action: "add_root", path: "/path/to/code")` to add directories to the index
            - Use `code_index(action: "update")` to refresh the index after making changes
            - Use `code_index(action: "status")` to check index health and coverage
            - Use `code_index(action: "build")` to force a full rebuild if the index seems stale

            ### Multi-Root Search

            The index supports multiple search roots. This is useful when working across several projects
            or when you need to index both the workspace and an external codebase:

            ```
            code_index(action: "add_root", path: "/path/to/project-a")
            code_index(action: "add_root", path: "/path/to/project-b")
            code_index(action: "build")
            ```

            ### Requirements

            - **ripgrep** (`rg`) must be installed for `code_search` and `find_files`
            - **universal-ctags** must be installed for `symbol_search`, `file_structure`, and `code_index`
            - If a binary is missing, the tool will return an error with installation instructions
            </CODE-SEARCH-GUIDELINES>
            GUIDELINES;
    }
}
