<?php

declare(strict_types=1);

require_once __DIR__ . '/Schema.php';

/**
 * CrudGenerator
 *
 * Iterates over the IR (array<string, TableDef>) and writes:
 *   api/index.php          — router
 *   api/{table}.php        — per-table handler (one per table)
 *   api/spec.json          — machine-readable API spec
 */
class CrudGenerator
{
    private string $tplDir;

    /**
     * @param array<string, TableDef> $tables   IR from SchemaParser
     * @param array                   $config   DB credentials passed into the router template
     * @param string                  $outputDir Target directory for generated files
     */
    public function __construct(
        private readonly array  $tables,
        private readonly array  $config,
        private readonly string $outputDir,
    ) {
        $this->tplDir = __DIR__ . '/templates';
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public function run(): void
    {
        $this->ensureDir($this->outputDir);
        $this->ensureDir($this->outputDir . '/core');

        $this->generateRouter();

        foreach ($this->tables as $name => $table) {
            $this->generateHandler($table);
            echo "  [ok] {$name}.php\n";
        }

        $this->generateSpec();

        echo "  [ok] index.php\n";
        echo "  [ok] spec.json\n";
        echo "\nDone. Output: {$this->outputDir}\n";
    }

    // -------------------------------------------------------------------------
    // Spec generation
    // -------------------------------------------------------------------------

    private function generateSpec(): void
    {
        $spec = [
            'generated_at' => date('Y-m-d H:i:s'),
            'database'     => $this->config['name'],
            'base_url'     => '/{table}',
            'tables'       => [],
        ];

        foreach ($this->tables as $name => $table) {
            $spec['tables'][$name] = $this->buildTableSpec($table);
        }

        file_put_contents(
            $this->outputDir . '/spec.json',
            json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Build the full spec entry for one table.
     * Derives routes, fields, query params, and body contracts from the IR.
     */
    private function buildTableSpec(TableDef $table): array
    {
        return [
            'primary_key'  => $table->pk,
            'routes'       => $this->buildRoutes($table),
            'fields'       => $this->buildFields($table),
            'query_params' => $this->buildQueryParams($table),
        ];
    }

    /**
     * Enumerate every valid route for a table.
     *
     * Sources:
     *   - Base CRUD routes (always present)
     *   - Nested GET routes from fkIn (tables that have a FK pointing here)
     */
    private function buildRoutes(TableDef $table): array
    {
        $t      = $table->table;
        $pk     = $table->pk;
        $routes = [];

        // GET list
        $routes[] = [
            'method'      => 'GET',
            'path'        => "/{$t}",
            'description' => 'List records. Supports pagination and column filters.',
            'query'       => $this->buildQueryParams($table),
            'response'    => 'data[] + meta{page, per_page, total}',
        ];

        // GET single
        $routes[] = [
            'method'      => 'GET',
            'path'        => "/{$t}/{{$pk}}",
            'description' => 'Single record by primary key.',
            'response'    => 'object | 404',
        ];

        // Nested GET routes — driven entirely by fkIn
        // Each child table that has a FK pointing to this table gets a nested route here.
        // e.g. bookings.user_id → users.id  produces  GET /users/{id}/bookings
        foreach ($table->fkIn as $childTable => $fkDefs) {
            foreach ($fkDefs as $fk) {
                $routes[] = [
                    'method'      => 'GET',
                    'path'        => "/{$t}/{{$pk}}/{$childTable}",
                    'description' => "List {$childTable} records related to this {$t} via {$childTable}.{$fk->column}.",
                    'via'         => "{$childTable}.{$fk->column} → {$t}.{$fk->refColumn}",
                    'query'       => $this->buildNestedQueryParams($childTable),
                    'response'    => 'data[] + meta{page, per_page, total}',
                ];
            }
        }

        // POST create
        $routes[] = [
            'method'      => 'POST',
            'path'        => "/{$t}",
            'description' => 'Create a new record.',
            'body'        => $this->buildBodyContract($table->insertableColumns(), $table),
            'response'    => 'object (201) | errors (422)',
        ];

        // PUT full update
        $routes[] = [
            'method'      => 'PUT',
            'path'        => "/{$t}/{{$pk}}",
            'description' => 'Full update. All updatable fields required.',
            'body'        => $this->buildBodyContract($table->updatableColumns(), $table),
            'response'    => 'object | 404 | errors (422)',
        ];

        // DELETE
        $routes[] = [
            'method'      => 'DELETE',
            'path'        => "/{$t}/{{$pk}}",
            'description' => 'Delete by primary key.',
            'response'    => '204 | 404',
        ];

        return $routes;
    }

    /**
     * Build the field map for a table.
     * Every column gets: type, nullable, auto-managed flag, insertable, updatable,
     * and optionally: enum values, fk target.
     */
    private function buildFields(TableDef $table): array
    {
        $fields = [];

        foreach ($table->columns as $name => $col) {
            $entry = [
                'type'        => $col->type,
                'nullable'    => $col->nullable,
                'auto'        => $col->isAutoManaged(),
                'insertable'  => !$col->isAutoManaged(),
                'updatable'   => !$col->isAutoManaged() && $name !== $table->pk,
            ];

            if ($col->default !== null) {
                $entry['default'] = $col->default;
            }

            if ($col->length !== null) {
                $entry['max_length'] = $col->length;
            }

            if ($col->unsigned) {
                $entry['unsigned'] = true;
            }

            // ENUM allowed values
            if ($col->isEnum() && isset($table->enums[$name])) {
                $entry['enum'] = $table->enums[$name];
            }

            // FK target — if this column is a FK, tell the consumer
            // where to look for valid values
            if (isset($table->fkOut[$name])) {
                $fk = $table->fkOut[$name];
                $entry['fk'] = [
                    'table'    => $fk->refTable,
                    'column'   => $fk->refColumn,
                    'on_delete'=> $fk->onDelete,
                    'hint'     => "Valid values: GET /{$fk->refTable} → {$fk->refColumn}",
                ];
            }

            $fields[$name] = $entry;
        }

        return $fields;
    }

    /**
     * Query params valid on the list endpoint for this table.
     *
     * Always present: page, per_page.
     * Per column: every non-auto-managed column is filterable by exact match.
     */
    private function buildQueryParams(TableDef $table): array
    {
        $params = [
            'page'     => ['type' => 'int', 'default' => 1,  'description' => 'Page number'],
            'per_page' => ['type' => 'int', 'default' => 20, 'description' => 'Records per page (max 100)'],
        ];

        foreach ($table->columns as $name => $col) {
            if ($col->isAutoManaged()) continue;

            $param = [
                'type'        => $col->type,
                'description' => "Filter by exact {$name}",
                'filterable'  => true,
            ];

            if ($col->isEnum() && isset($table->enums[$name])) {
                $param['enum'] = $table->enums[$name];
            }

            if (isset($table->fkOut[$name])) {
                $fk = $table->fkOut[$name];
                $param['fk_hint'] = "Valid values: GET /{$fk->refTable} → {$fk->refColumn}";
            }

            $params[$name] = $param;
        }

        return $params;
    }

    /**
     * Query params for a nested route — we only know the child table name here,
     * not its full TableDef, so we emit what we can: pagination + a note.
     * The child table's own entry in the spec has the full field list.
     */
    private function buildNestedQueryParams(string $childTable): array
    {
        return [
            'page'     => ['type' => 'int', 'default' => 1,  'description' => 'Page number'],
            'per_page' => ['type' => 'int', 'default' => 20, 'description' => 'Records per page (max 100)'],
            '*'        => "Any non-auto column in {$childTable} — see spec.tables.{$childTable}.query_params",
        ];
    }

    /**
     * Build the body contract for POST / PUT.
     * Each field lists: type, required (based on nullable + default), enum if relevant, fk hint if relevant.
     */
    private function buildBodyContract(array $columns, TableDef $table): array
    {
        $body = [];

        foreach ($columns as $col) {
            $name = $col->name;

            $required = !$col->nullable && $col->default === null;

            $field = [
                'type'     => $col->type,
                'required' => $required,
            ];

            if ($col->length !== null) {
                $field['max_length'] = $col->length;
            }

            if ($col->default !== null) {
                $field['default'] = $col->default;
            }

            if ($col->isEnum() && isset($table->enums[$name])) {
                $field['enum'] = $table->enums[$name];
            }

            if (isset($table->fkOut[$name])) {
                $fk = $table->fkOut[$name];
                $field['fk'] = [
                    'table'  => $fk->refTable,
                    'column' => $fk->refColumn,
                    'hint'   => "Must exist in GET /{$fk->refTable}/{{{$fk->refColumn}}}",
                ];
            }

            $body[$name] = $field;
        }

        return $body;
    }

    // -------------------------------------------------------------------------
    // Existing internals (unchanged)
    // -------------------------------------------------------------------------

    private function generateRouter(): void
    {
        $tables = $this->tables;
        $config = $this->config;
        $output = $this->render($this->tplDir . '/index.tpl.php', compact('tables', 'config'));
        file_put_contents($this->outputDir . '/index.php', $output);
    }

    private function generateHandler(TableDef $table): void
    {
        $output = $this->render($this->tplDir . '/handler.tpl.php', compact('table'));
        file_put_contents($this->outputDir . '/' . $table->table . '.php', $output);
    }

    private function render(string $tplPath, array $vars = []): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        include $tplPath;
        return ob_get_clean();
    }

    private function ensureDir(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, recursive: true);
        }
    }
}