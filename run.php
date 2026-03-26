<?php

declare(strict_types=1);

require_once __DIR__ . '/generator/Schema.php';
require_once __DIR__ . '/generator/SchemaParser.php';
require_once __DIR__ . '/generator/CrudGenerator.php';

// ---------------------------------------------------------------------------
// Config — edit this section before running
// ---------------------------------------------------------------------------

$config = [
    'host' => 'localhost',
    'name' => 'hotel',          // database name
    'user' => 'root',
    'pass' => '',
];

/**
 * Source mode — choose ONE:
 *
 *   'ddl'  — parse a local SQL file (no DB connection needed)
 *   'pdo'  — introspect the live database
 */
$mode   = 'ddl';
$ddlFile = __DIR__ . '/schema.sql';   // only used when $mode === 'ddl'

$outputDir = __DIR__ . '/api';

// ---------------------------------------------------------------------------
// Parse schema
// ---------------------------------------------------------------------------

echo "CRUDify API Generator\n";
echo str_repeat('-', 40) . "\n";
echo "Mode      : {$mode}\n";
echo "Database  : {$config['name']}\n";
echo "Output    : {$outputDir}\n\n";

if ($mode === 'ddl') {
    if (!file_exists($ddlFile)) {
        fwrite(STDERR, "Error: DDL file not found: {$ddlFile}\n");
        exit(1);
    }
    $ddl    = file_get_contents($ddlFile);
    $tables = SchemaParser::fromDdl($ddl);

} elseif ($mode === 'pdo') {
    try {
        $dsn = "mysql:host={$config['host']};dbname={$config['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $tables = SchemaParser::fromPdo($pdo, $config['name']);
    } catch (PDOException $e) {
        fwrite(STDERR, "DB connection failed: {$e->getMessage()}\n");
        exit(1);
    }

} else {
    fwrite(STDERR, "Unknown mode: {$mode}. Use 'ddl' or 'pdo'.\n");
    exit(1);
}

echo "Tables found: " . implode(', ', array_keys($tables)) . "\n\n";
echo "Generating files...\n";

// ---------------------------------------------------------------------------
// Generate
// ---------------------------------------------------------------------------

$gen = new CrudGenerator($tables, $config, $outputDir);
$gen->run();