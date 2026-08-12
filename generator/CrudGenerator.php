<?php

declare(strict_types=1);

require_once __DIR__ . '/Schema.php';

/**
 * CrudGenerator
 *
 * Mode 'api'    → api/index.php + api/{table}.php + api/spec.json
 * Mode 'models' → models/{ClassName}.php + models/spec.json
 * Mode 'both'   → all of the above
 */
class CrudGenerator
{
    private string $tplDir;

    /** @param 'api'|'models'|'both' $mode */
    public function __construct(
        private readonly array  $tables,
        private readonly array  $config,
        private readonly string $outputDir,
        private readonly string $mode = 'api',
    ) {
        $this->tplDir = __DIR__ . '/templates';
    }

    public function run(): void
    {
        if (in_array($this->mode, ['api', 'both'], true)) {
            $this->ensureDir($this->outputDir . '/core');
            $this->generateRouter();
            foreach ($this->tables as $name => $table) {
                $this->generateHandler($table);
                echo "  [ok] api/{$name}.php\n";
            }
            $this->generateSpec($this->outputDir);
            echo "  [ok] api/index.php\n";
            echo "  [ok] api/spec.json\n";
        }

        if (in_array($this->mode, ['models', 'both'], true)) {
            $modelsDir = ($this->mode === 'both')
                ? dirname($this->outputDir) . '/models'
                : $this->outputDir;
            $this->ensureDir($modelsDir);
            foreach ($this->tables as $name => $table) {
                $this->generateModel($table, $modelsDir);
                echo "  [ok] models/" . $this->toClassName($name) . ".php\n";
            }
            $this->generateSpec($modelsDir);
            echo "  [ok] models/spec.json\n";
        }

        echo "\nDone.\n";
    }

    // -------------------------------------------------------------------------
    // Model generation
    // -------------------------------------------------------------------------

    private function generateModel(TableDef $table, string $dir): void
    {
        $className = $this->toClassName($table->table);
        $output    = $this->render($this->tplDir . '/model.tpl.php', compact('table', 'className'));
        file_put_contents($dir . '/' . $className . '.php', $output);
    }

    /**
     * Convert snake_case table name to PascalCase class name.
     * classification_attributes → ClassificationAttributes
     * (singular form intentionally left to developer; generators avoid irregular English)
     */
    public function toClassName(string $table): string
    {
        return str_replace('_', '', ucwords($table, '_'));
    }

    // -------------------------------------------------------------------------
    // Spec generation (shared between modes — writes to whichever dir is passed)
    // -------------------------------------------------------------------------

    private function generateSpec(string $dir): void
    {
        $spec = [
            'generated_at' => date('Y-m-d H:i:s'),
            'database'     => $this->config['name'],
            'mode'         => $this->mode,
            'tables'       => [],
        ];

        foreach ($this->tables as $name => $table) {
            $spec['tables'][$name] = [
                'primary_key'  => $table->pk,
                'routes'       => $this->buildRoutes($table),
                'fields'       => $this->buildFields($table),
                'query_params' => $this->buildQueryParams($table),
            ];
        }

        file_put_contents(
            $dir . '/spec.json',
            json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private function buildRoutes(TableDef $table): array
    {
        $t = $table->table;
        $pk = $table->pk;
        $routes = [];

        $routes[] = [
            'method' => 'GET',
            'path' => "/{$t}",
            'description' => 'List (paginated, filterable)',
            'query' => $this->buildQueryParams($table),
            'response' => 'data[] + meta{page,per_page,total}'
        ];
        $routes[] = ['method' => 'GET',    'path' => "/{$t}/{{$pk}}", 'description' => 'Single record', 'response' => 'object|404'];

        foreach ($table->fkIn as $childTable => $fkDefs) {
            foreach ($fkDefs as $fk) {
                $routes[] = [
                    'method' => 'GET',
                    'path' => "/{$t}/{{$pk}}/{$childTable}",
                    'description' => "List {$childTable} via {$childTable}.{$fk->column}",
                    'via' => "{$childTable}.{$fk->column} → {$t}.{$fk->refColumn}",
                    'response' => 'data[] + meta{page,per_page,total}'
                ];
            }
        }

        $routes[] = [
            'method' => 'POST',
            'path' => "/{$t}",
            'description' => 'Create',
            'body' => $this->buildBodyContract($table->insertableColumns(), $table),
            'response' => 'object(201)|errors(422)'
        ];
        $routes[] = [
            'method' => 'PUT',
            'path' => "/{$t}/{{$pk}}",
            'description' => 'Full update',
            'body' => $this->buildBodyContract($table->updatableColumns(), $table),
            'response' => 'object|404|errors(422)'
        ];
        $routes[] = ['method' => 'DELETE', 'path' => "/{$t}/{{$pk}}", 'description' => 'Delete', 'response' => '204|404'];

        return $routes;
    }

    private function buildFields(TableDef $table): array
    {
        $fields = [];
        foreach ($table->columns as $name => $col) {
            $entry = [
                'type'       => $col->type,
                'nullable'   => $col->nullable,
                'auto'       => $col->isAutoManaged(),
                'insertable' => !$col->isAutoManaged(),
                'updatable'  => !$col->isAutoManaged() && $name !== $table->pk,
            ];
            if ($col->default !== null)  $entry['default']    = $col->default;
            if ($col->length  !== null)  $entry['max_length'] = $col->length;
            if ($col->unsigned)          $entry['unsigned']   = true;
            if ($col->isEnum() && isset($table->enums[$name]))
                $entry['enum'] = $table->enums[$name];
            if (isset($table->fkOut[$name])) {
                $fk = $table->fkOut[$name];
                $entry['fk'] = [
                    'table' => $fk->refTable,
                    'column' => $fk->refColumn,
                    'on_delete' => $fk->onDelete,
                    'hint' => "Valid: GET /{$fk->refTable}"
                ];
            }
            $fields[$name] = $entry;
        }
        return $fields;
    }

    private function buildQueryParams(TableDef $table): array
    {
        $params = [
            'page'     => ['type' => 'int', 'default' => 1,  'description' => 'Page number'],
            'per_page' => ['type' => 'int', 'default' => 20, 'description' => 'Per page (max 100)'],
        ];
        foreach ($table->columns as $name => $col) {
            if ($col->isAutoManaged()) continue;
            $p = ['type' => $col->type, 'description' => "Filter by {$name}", 'filterable' => true];
            if ($col->isEnum() && isset($table->enums[$name])) $p['enum'] = $table->enums[$name];
            if (isset($table->fkOut[$name])) $p['fk_hint'] = "GET /{$table->fkOut[$name]->refTable}";
            $params[$name] = $p;
        }
        return $params;
    }

    private function buildBodyContract(array $columns, TableDef $table): array
    {
        $body = [];
        foreach ($columns as $col) {
            $name  = $col->name;
            $field = ['type' => $col->type, 'required' => (!$col->nullable && $col->default === null)];
            if ($col->length  !== null) $field['max_length'] = $col->length;
            if ($col->default !== null) $field['default']    = $col->default;
            if ($col->isEnum() && isset($table->enums[$name])) $field['enum'] = $table->enums[$name];
            if (isset($table->fkOut[$name])) {
                $fk = $table->fkOut[$name];
                $field['fk'] = ['table' => $fk->refTable, 'column' => $fk->refColumn];
            }
            $body[$name] = $field;
        }
        return $body;
    }

    // -------------------------------------------------------------------------
    // API generation (unchanged)
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

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    private function render(string $tplPath, array $vars = []): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        include $tplPath;
        return ob_get_clean();
    }

    private function ensureDir(string $path): void
    {
        if (!is_dir($path)) mkdir($path, 0755, recursive: true);
    }
}
