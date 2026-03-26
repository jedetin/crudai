<?php

declare(strict_types=1);

require_once __DIR__ . '/Schema.php';

/**
 * Generator
 *
 * Iterates over the IR (array<string, TableDef>) and writes:
 *   api/index.php          — router
 *   api/{table}.php        — per-table handler (one per table)
 *
 * Usage:
 *   $gen = new Generator($tables, $config, outputDir: __DIR__ . '/../api');
 *   $gen->run();
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

        echo "  [ok] index.php\n";
        echo "\nDone. Output: {$this->outputDir}\n";
    }

    // -------------------------------------------------------------------------
    // Internals
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

    /**
     * Render a PHP template file with the given variables in scope.
     * Uses output buffering — the template just does <?= $var ?> style echoes.
     */
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