<?php

declare(strict_types=1);

require_once __DIR__ . '/Schema.php';

/**
 * SchemaParser
 *
 * Two entry points:
 *   SchemaParser::fromDdl(string $ddl)        — parse a CREATE TABLE SQL string
 *   SchemaParser::fromPdo(PDO $pdo, string $db) — introspect a live MySQL/MariaDB database
 *
 * Both return array<string, TableDef> keyed by table name.
 */
class SchemaParser
{
    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Parse one or more CREATE TABLE statements from a DDL string.
     */
    public static function fromDdl(string $ddl): array
    {
        $tables = [];

        // Strip comments (-- line comments and /* block comments */)
        $ddl = preg_replace('/--[^\n]*/', '', $ddl);
        $ddl = preg_replace('/\/\*.*?\*\//s', '', $ddl);

        // Split into individual CREATE TABLE blocks
        preg_match_all(
            '/CREATE\s+TABLE\s+`?(\w+)`?\s*\((.+?)\)\s*(?:;|ENGINE[^;]*;)/si',
            $ddl,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $m) {
            $tableName = strtolower($m[1]);
            $body      = $m[2];
            $tables[$tableName] = self::parseTableBody($tableName, $body);
        }

        self::linkForeignKeys($tables);

        return $tables;
    }

    /**
     * Introspect a live MySQL / MariaDB database via PDO.
     */
    public static function fromPdo(PDO $pdo, string $dbName): array
    {
        $tables = [];

        // 1. Get all tables in the database
        $stmt = $pdo->query("SHOW TABLES FROM `{$dbName}`");
        $tableNames = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tableNames as $tableName) {
            $tableName = strtolower($tableName);
            $tables[$tableName] = self::introspectTable($pdo, $dbName, $tableName);
        }

        self::linkForeignKeys($tables);

        return $tables;
    }

    // -------------------------------------------------------------------------
    // DDL parsing helpers
    // -------------------------------------------------------------------------

    private static function parseTableBody(string $tableName, string $body): TableDef
    {
        $columns = [];
        $fkOut   = [];
        $enums   = [];
        $pk      = 'id'; // sensible default, overridden below

        // Split body into individual lines (respecting parentheses for ENUM)
        $lines = self::splitTableLines($body);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            // PRIMARY KEY (col)  — table-level constraint
            if (preg_match('/^PRIMARY\s+KEY\s*\(`?(\w+)`?\)/i', $line, $m)) {
                $pk = strtolower($m[1]);
                continue;
            }

            // FOREIGN KEY (col) REFERENCES table(col) [ON DELETE action]
            if (preg_match(
                '/^(?:CONSTRAINT\s+`?\w+`?\s+)?FOREIGN\s+KEY\s*\(`?(\w+)`?\)\s+REFERENCES\s+`?(\w+)`?\s*\(`?(\w+)`?\)(?:\s+ON\s+DELETE\s+(\w+))?/i',
                $line, $m
            )) {
                $col    = strtolower($m[1]);
                $fkOut[$col] = new FkDef(
                    column:    $col,
                    refTable:  strtolower($m[2]),
                    refColumn: strtolower($m[3]),
                    onDelete:  isset($m[4]) ? strtoupper($m[4]) : null,
                );
                continue;
            }

            // UNIQUE KEY / KEY / INDEX — skip
            if (preg_match('/^(?:UNIQUE\s+)?(?:KEY|INDEX)\s/i', $line)) {
                continue;
            }

            // Column definition: `col_name` TYPE(...) [NOT NULL] [DEFAULT x] [AUTO_INCREMENT] ...
            if (preg_match('/^`?(\w+)`?\s+(.+)$/i', $line, $m)) {
                $colName = strtolower($m[1]);
                $colDef  = self::parseColumnDef($colName, $m[2]);

                // Detect inline PRIMARY KEY
                if (preg_match('/\bPRIMARY\s+KEY\b/i', $m[2])) {
                    $pk = $colName;
                }

                // Collect ENUM values
                if ($colDef->isEnum()) {
                    $enums[$colName] = self::extractEnumValues($m[2]);
                }

                $columns[$colName] = $colDef;
            }
        }

        return new TableDef(
            table:   $tableName,
            pk:      $pk,
            columns: $columns,
            fkOut:   $fkOut,
            fkIn:    [],       // populated by linkForeignKeys()
            enums:   $enums,
        );
    }

    /**
     * Split a CREATE TABLE body into individual lines.
     * Naively splitting on commas breaks ENUM('a,b') — this tracks paren depth.
     */
    private static function splitTableLines(string $body): array
    {
        $lines = [];
        $depth = 0;
        $current = '';

        for ($i = 0, $len = strlen($body); $i < $len; $i++) {
            $ch = $body[$i];
            if ($ch === '(') $depth++;
            if ($ch === ')') $depth--;
            if ($ch === ',' && $depth === 0) {
                $lines[] = trim($current);
                $current = '';
            } else {
                $current .= $ch;
            }
        }
        if (trim($current) !== '') {
            $lines[] = trim($current);
        }

        return $lines;
    }

    /**
     * Parse a column definition fragment into a ColumnDef.
     * e.g. "VARCHAR(150) NOT NULL" or "ENUM('a','b') DEFAULT 'a'"
     */
    private static function parseColumnDef(string $name, string $def): ColumnDef
    {
        // Type (with optional length/precision)
        preg_match('/^(\w+)(?:\(([^)]+)\))?/i', $def, $tm);
        $rawType = strtolower($tm[1] ?? 'varchar');
        $typeArg = $tm[2] ?? null;

        $type   = self::normaliseType($rawType);
        $length = null;

        if ($typeArg !== null && in_array($type, ['varchar', 'char'], true)) {
            $length = (int) $typeArg;
        }

        $nullable      = !preg_match('/\bNOT\s+NULL\b/i', $def);
        $autoIncrement = (bool) preg_match('/\bAUTO_INCREMENT\b/i', $def);
        $unsigned      = (bool) preg_match('/\bUNSIGNED\b/i', $def);

        // DEFAULT value — capture quoted string or bare word / NULL
        $default = null;
        if (preg_match('/\bDEFAULT\s+(?:\'([^\']*?)\'|(\S+))/i', $def, $dm)) {
            $default = $dm[1] !== '' ? $dm[1] : ($dm[2] !== '' ? strtoupper($dm[2]) : null);
        }

        return new ColumnDef(
            name:          $name,
            type:          $type,
            nullable:      $nullable,
            autoIncrement: $autoIncrement,
            default:       $default,
            length:        $length,
            unsigned:      $unsigned,
        );
    }

    /**
     * Normalise raw MySQL type strings to a small canonical set.
     */
    private static function normaliseType(string $raw): string
    {
        return match (true) {
            in_array($raw, ['tinyint', 'smallint', 'mediumint', 'int', 'integer'], true) => 'int',
            in_array($raw, ['bigint'],                                               true) => 'bigint',
            in_array($raw, ['float', 'double', 'real'],                              true) => 'float',
            in_array($raw, ['decimal', 'numeric'],                                   true) => 'decimal',
            in_array($raw, ['char', 'varchar'],                                      true) => 'varchar',
            in_array($raw, ['text', 'tinytext', 'mediumtext', 'longtext'],           true) => 'text',
            in_array($raw, ['date'],                                                 true) => 'date',
            in_array($raw, ['datetime', 'timestamp'],                                true) => 'timestamp',
            in_array($raw, ['boolean', 'bool'],                                      true) => 'tinyint',
            in_array($raw, ['enum', 'set'],                                          true) => 'enum',
            default => $raw,
        };
    }

    /**
     * Extract values from an ENUM(...) type fragment.
     * e.g. "ENUM('pending','confirmed','cancelled')" → ['pending','confirmed','cancelled']
     */
    private static function extractEnumValues(string $def): array
    {
        if (preg_match('/ENUM\s*\((.+?)\)/i', $def, $m)) {
            preg_match_all("/'([^']+)'/", $m[1], $vals);
            return $vals[1];
        }
        return [];
    }

    // -------------------------------------------------------------------------
    // Live PDO introspection helpers
    // -------------------------------------------------------------------------

    private static function introspectTable(PDO $pdo, string $dbName, string $tableName): TableDef
    {
        $columns = [];
        $fkOut   = [];
        $enums   = [];
        $pk      = 'id';

        // 1. Column metadata from information_schema
        $stmt = $pdo->prepare("
            SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH,
                   IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_TYPE
            FROM   information_schema.COLUMNS
            WHERE  TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ORDER  BY ORDINAL_POSITION
        ");
        $stmt->execute([$dbName, $tableName]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $colName = strtolower($row['COLUMN_NAME']);
            $type    = self::normaliseType(strtolower($row['DATA_TYPE']));
            $length  = $row['CHARACTER_MAXIMUM_LENGTH'] ? (int)$row['CHARACTER_MAXIMUM_LENGTH'] : null;
            $auto    = str_contains(strtolower($row['EXTRA']), 'auto_increment');
            $default = $row['COLUMN_DEFAULT'];
            $unsigned = str_contains(strtolower($row['COLUMN_TYPE']), 'unsigned');

            // Detect PK via EXTRA
            if (str_contains(strtolower($row['EXTRA']), 'auto_increment') && $auto) {
                $pk = $colName;
            }

            // ENUM values
            if ($type === 'enum') {
                preg_match_all("/'([^']+)'/", $row['COLUMN_TYPE'], $vals);
                $enums[$colName] = $vals[1];
            }

            $columns[$colName] = new ColumnDef(
                name:          $colName,
                type:          $type,
                nullable:      $row['IS_NULLABLE'] === 'YES',
                autoIncrement: $auto,
                default:       $default,
                length:        $length,
                unsigned:      $unsigned,
            );
        }

        // 2. Detect PK properly via KEY_COLUMN_USAGE
        $stmt = $pdo->prepare("
            SELECT COLUMN_NAME
            FROM   information_schema.KEY_COLUMN_USAGE
            WHERE  TABLE_SCHEMA = ? AND TABLE_NAME = ?
              AND  CONSTRAINT_NAME = 'PRIMARY'
            LIMIT 1
        ");
        $stmt->execute([$dbName, $tableName]);
        if ($pkRow = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pk = strtolower($pkRow['COLUMN_NAME']);
        }

        // 3. Foreign keys
        $stmt = $pdo->prepare("
            SELECT kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
                   rc.DELETE_RULE
            FROM   information_schema.KEY_COLUMN_USAGE kcu
            JOIN   information_schema.REFERENTIAL_CONSTRAINTS rc
                     ON rc.CONSTRAINT_NAME    = kcu.CONSTRAINT_NAME
                    AND rc.CONSTRAINT_SCHEMA  = kcu.TABLE_SCHEMA
            WHERE  kcu.TABLE_SCHEMA = ? AND kcu.TABLE_NAME = ?
              AND  kcu.REFERENCED_TABLE_NAME IS NOT NULL
        ");
        $stmt->execute([$dbName, $tableName]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fk) {
            $col = strtolower($fk['COLUMN_NAME']);
            $fkOut[$col] = new FkDef(
                column:    $col,
                refTable:  strtolower($fk['REFERENCED_TABLE_NAME']),
                refColumn: strtolower($fk['REFERENCED_COLUMN_NAME']),
                onDelete:  $fk['DELETE_RULE'] ?? null,
            );
        }

        return new TableDef(
            table:   $tableName,
            pk:      $pk,
            columns: $columns,
            fkOut:   $fkOut,
            fkIn:    [],
            enums:   $enums,
        );
    }

    // -------------------------------------------------------------------------
    // Second-pass: build reverse FK map (fk_in) on every table
    // -------------------------------------------------------------------------

    /**
     * After all tables are parsed, walk every table's fkOut and register
     * the reverse pointer on the referenced table's fkIn.
     *
     * Result: $tables['users']->fkIn === ['bookings' => [FkDef(user_id→users.id)], ...]
     * This drives nested route generation: GET /users/{id}/bookings
     *
     * @param array<string, TableDef> $tables  passed by reference
     */
    private static function linkForeignKeys(array &$tables): void
    {
        // Collect reverse pointers
        $fkIn = []; // refTable => [ originTable => FkDef[] ]

        foreach ($tables as $originTable => $tableDef) {
            foreach ($tableDef->fkOut as $col => $fk) {
                $fkIn[$fk->refTable][$originTable][] = $fk;
            }
        }

        // Rebuild TableDef instances with populated fkIn
        // (readonly classes require full reconstruction)
        foreach ($tables as $name => $tableDef) {
            $tables[$name] = new TableDef(
                table:   $tableDef->table,
                pk:      $tableDef->pk,
                columns: $tableDef->columns,
                fkOut:   $tableDef->fkOut,
                fkIn:    $fkIn[$name] ?? [],
                enums:   $tableDef->enums,
            );
        }
    }
}