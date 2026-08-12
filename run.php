<?php

declare(strict_types=1);

require_once __DIR__ . '/generator/Schema.php';
require_once __DIR__ . '/generator/SchemaParser.php';
require_once __DIR__ . '/generator/CrudGenerator.php';

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

$config = [
    'host' => 'localhost',
    'name' => 'crudai_test',
    'user' => 'root',
    'pass' => '',
];

/**
 * Output mode:
 *   'api'    — generates api/ folder (HTTP handlers + router)
 *   'models' — generates models/ folder (static PHP classes, no HTTP)
 *   'both'   — generates both
 */
$mode = 'models';

/**
 * Schema source:
 *   'ddl' — parse a local .sql file (no DB connection needed)
 *   'pdo' — introspect a live database
 */
$source  = 'ddl';
$ddlFile = __DIR__ . '/schema.sql';

$outputDir = __DIR__ . '/api';   // used as-is for 'api' and 'models'; for 'both', models/ is auto-placed beside api/

// ---------------------------------------------------------------------------
// Parse
// ---------------------------------------------------------------------------

echo "CRUDify Generator<br />" . str_repeat('-', 40) . "<br />";
echo "Mode   : {$mode}<br />";
echo "Source : {$source}<br />";
echo "DB     : {$config['name']}<br /><br />";

if ($source === 'ddl') {
    if (!file_exists($ddlFile)) {
        fwrite(STDERR, "DDL file not found: {$ddlFile}<br />");
        exit(1);
    }
    $tables = SchemaParser::fromDdl(file_get_contents($ddlFile));
} elseif ($source === 'pdo') {
    try {
        $pdo    = new PDO(
            "mysql:host={$config['host']};dbname={$config['name']};charset=utf8mb4",
            $config['user'],
            $config['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $tables = SchemaParser::fromPdo($pdo, $config['name']);
    } catch (PDOException $e) {
        fwrite(STDERR, "DB error: {$e->getMessage()}<br />");
        exit(1);
    }
} else {
    print('STDERR'. "Unknown source: {$source}<br />");
    exit(1);
}

if (empty($tables)) {
    print('STDERR'. "No tables found. Check your schema source.<br />");
    exit(1);
}

echo "Tables : " . implode(', ', array_keys($tables)) . "<br /><br />";
echo "Generating...<br />";

// ---------------------------------------------------------------------------
// Generate
// ---------------------------------------------------------------------------

$gen = new CrudGenerator($tables, $config, $outputDir, $mode);
$gen->run();