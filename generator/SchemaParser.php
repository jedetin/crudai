<?php

declare(strict_types=1);

require_once __DIR__ . '/Schema.php';

/**
 * SchemaParser
 *
 * Two entry points:
 *   SchemaParser::fromDdl(string $ddl)          — parse a CREATE TABLE SQL string
 *   SchemaParser::fromPdo(PDO $pdo, string $db)  — introspect a live MySQL/MariaDB database
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
     * Handles:
     *   - CREATE TABLE IF NOT EXISTS
     *   - Backtick-quoted identifiers
     *   - ENGINE / COLLATE / CHARSET trailers
     *   - SQL comments (-- line and /* block)
     *   - UNIQUE KEY / KEY / INDEX lines inside table body
     *   - Named CONSTRAINT ... FOREIGN KEY definitions
     *   - ENUM values with commas inside quotes
     *   - ON UPDATE clauses (e.g. ON UPDATE CURRENT_TIMESTAMP)
     */
    public static function fromDdl(string $ddl): array
    {
        $tables = [];

        // 1. Strip block comments /* ... */
        $ddl = preg_replace('/\/\*.*?\*\//s', '', $ddl);

        // 2. Strip line comments -- ...
        //    Must happen after block comment strip so we don't mangle block comment content
        $ddl = preg_replace('/--[^\n]*/u', '', $ddl);

        // 3. Normalise whitespace runs to single spaces, but keep newlines
        //    (newlines help the block splitter below)
        $ddl = preg_replace('/[ \t]+/', ' ', $ddl);

        // 4. Extract CREATE TABLE blocks.
        //
        //    Pattern breakdown:
        //      CREATE\s+TABLE\s+           — literal keywords with flexible spacing
        //      (?:IF\s+NOT\s+EXISTS\s+)?   — optional IF NOT EXISTS
        //      [`"]?(\w+)[`"]?             — table name, optionally backtick/quote-quoted
        //      \s*\(                       — opening paren
        //      (.+?)                       — body (non-greedy, DOTALL)
        //      \)\s*                       — closing paren
        //      [^;]*;                      — everything up to the semicolon (ENGINE=... etc.)
        //
        //    The [^;]*; end anchor is the key fix — it consumes the full trailer
        //    (ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=...) without
        //    requiring us to enumerate every possible keyword.

        preg_match_all(
            '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(\w+)[`"]?\s*\((.+?)\)\s*[^;]*;/si',
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
        $pk      = 'id';

        $lines = self::splitTableLines($body);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            // ---- Table-level PRIMARY KEY constraint -------------------------
            // PRIMARY KEY (`col`)
            if (preg_match('/^PRIMARY\s+KEY\s*\([`"]?(\w+)[`"]?\)/i', $line, $m)) {
                $pk = strtolower($m[1]);
                continue;
            }

            // ---- FOREIGN KEY (named CONSTRAINT or bare) ---------------------
            // [CONSTRAINT `name`] FOREIGN KEY (`col`) REFERENCES `table` (`col`) [ON DELETE x] [ON UPDATE y]
            if (preg_match(
                '/(?:CONSTRAINT\s+[`"]?\w+[`"]?\s+)?FOREIGN\s+KEY\s*\([`"]?(\w+)[`"]?\)\s+REFERENCES\s+[`"]?(\w+)[`"]?\s*\([`"]?(\w+)[`"]?\)(?:.*?ON\s+DELETE\s+(\w+))?/i',
                $line,
                $m
            )) {
                $col = strtolower($m[1]);
                $fkOut[$col] = new FkDef(
                    column: $col,
                    refTable: strtolower($m[2]),
                    refColumn: strtolower($m[3]),
                    onDelete: !empty($m[4]) ? strtoupper($m[4]) : null,
                );
                continue;
            }

            // ---- Skip index/key lines ---------------------------------------
            // UNIQUE KEY, KEY, INDEX (but NOT PRIMARY KEY — handled above)
            if (preg_match('/^(?:UNIQUE\s+)?(?:KEY|INDEX)\s/i', $line)) {
                continue;
            }

            // ---- Column definition ------------------------------------------
            // `col_name` TYPE(...) [modifiers...]
            if (preg_match('/^[`"]?(\w+)[`"]?\s+(.+)$/si', $line, $m)) {
                $colName = strtolower($m[1]);

                // Skip constraint-like lines that slipped through
                if (in_array(strtoupper($colName), ['PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'CONSTRAINT', 'FOREIGN', 'CHECK'], true)) {
                    continue;
                }

                $colDef = self::parseColumnDef($colName, $m[2]);

                // Inline PRIMARY KEY
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
            table: $tableName,
            pk: $pk,
            columns: $columns,
            fkOut: $fkOut,
            fkIn: [],
            enums: $enums,
        );
    }

    /**
     * Split a CREATE TABLE body into individual definition lines.
     *
     * Cannot simply split on commas — ENUM('a,b') and multi-line FOREIGN KEY
     * definitions contain commas inside parens or across newlines.
     * Tracks paren depth; only splits on commas at depth 0.
     * Also collapses internal newlines within a single logical line.
     */
    private static function splitTableLines(string $body): array
    {
        $lines   = [];
        $depth   = 0;
        $current = '';

        for ($i = 0, $len = strlen($body); $i < $len; $i++) {
            $ch = $body[$i];

            if ($ch === '(') $depth++;
            if ($ch === ')') $depth--;

            if ($ch === ',' && $depth === 0) {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    // Collapse internal newlines/extra spaces within the line
                    $lines[] = preg_replace('/\s+/', ' ', $trimmed);
                }
                $current = '';
            } else {
                $current .= $ch;
            }
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $lines[] = preg_replace('/\s+/', ' ', $trimmed);
        }

        return $lines;
    }

    /**
     * Parse a column definition fragment into a ColumnDef.
     * Handles:
     *   VARCHAR(150) NOT NULL
     *   ENUM('a','b') DEFAULT 'a'
     *   TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
     *   INT NOT NULL AUTO_INCREMENT
     *   TINYINT(1) DEFAULT '0'
     *   JSON DEFAULT NULL
     */
    private static function parseColumnDef(string $name, string $def): ColumnDef
    {
        // Type — greedy match of the first word, optionally followed by (...)
        preg_match('/^(\w+)(?:\(([^)]+)\))?/i', $def, $tm);
        $rawType = strtolower($tm[1] ?? 'varchar');
        $typeArg = $tm[2] ?? null;

        $type   = self::normaliseType($rawType);
        $length = null;

        if ($typeArg !== null && in_array($type, ['varchar', 'char'], true)) {
            // typeArg may be "100" or "10,2" for DECIMAL — only use for varchar/char
            if (ctype_digit(trim($typeArg))) {
                $length = (int) $typeArg;
            }
        }

        $nullable      = !preg_match('/\bNOT\s+NULL\b/i', $def);
        $autoIncrement = (bool) preg_match('/\bAUTO_INCREMENT\b/i', $def);
        $unsigned      = (bool) preg_match('/\bUNSIGNED\b/i', $def);

        // DEFAULT value — handles:
        //   DEFAULT 'string'
        //   DEFAULT 0  /  DEFAULT NULL  /  DEFAULT CURRENT_TIMESTAMP
        //   DEFAULT '0'  (tinyint booleans often stored this way)
        // Must NOT capture the ON UPDATE clause that can follow
        $default = null;
        if (preg_match("/\\bDEFAULT\\s+(?:'([^']*)'|(\\S+))/i", $def, $dm)) {
            if (isset($dm[1]) && $dm[1] !== '') {
                $default = $dm[1];                   // quoted string value
            } elseif (isset($dm[2]) && $dm[2] !== '') {
                $val = strtoupper($dm[2]);
                // Don't treat ON as a default value (edge case: DEFAULT x ON UPDATE y)
                $default = ($val === 'ON') ? null : $val;
            }
        }

        return new ColumnDef(
            name: $name,
            type: $type,
            nullable: $nullable,
            autoIncrement: $autoIncrement,
            default: $default,
            length: $length,
            unsigned: $unsigned,
        );
    }

    /**
     * Normalise raw MySQL type strings to a small canonical set.
     * New additions: json, year, time, blob variants.
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
            in_array($raw, ['json'],                                                 true) => 'json',
            in_array($raw, ['blob', 'tinyblob', 'mediumblob', 'longblob'],           true) => 'blob',
            in_array($raw, ['year', 'time'],                                         true) => $raw,
            default => $raw,
        };
    }

    /**
     * Extract values from an ENUM/SET type fragment.
     * e.g. "ENUM('pending','confirmed','cancelled')" → ['pending','confirmed','cancelled']
     */
    private static function extractEnumValues(string $def): array
    {
        if (preg_match('/(?:ENUM|SET)\s*\((.+?)\)/i', $def, $m)) {
            preg_match_all("/'([^']*)'/", $m[1], $vals);
            return $vals[1];
        }
        return [];
    }

    // -------------------------------------------------------------------------
    // PDO introspection helpers
    // -------------------------------------------------------------------------

    private static function introspectTable(PDO $pdo, string $dbName, string $tableName): TableDef
    {
        $columns = [];
        $fkOut   = [];
        $enums   = [];
        $pk      = 'id';

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
            $colName  = strtolower($row['COLUMN_NAME']);
            $type     = self::normaliseType(strtolower($row['DATA_TYPE']));
            $length   = $row['CHARACTER_MAXIMUM_LENGTH'] ? (int)$row['CHARACTER_MAXIMUM_LENGTH'] : null;
            $auto     = str_contains(strtolower($row['EXTRA']), 'auto_increment');
            $default  = $row['COLUMN_DEFAULT'];
            $unsigned = str_contains(strtolower($row['COLUMN_TYPE']), 'unsigned');

            if ($type === 'enum') {
                preg_match_all("/'([^']+)'/", $row['COLUMN_TYPE'], $vals);
                $enums[$colName] = $vals[1];
            }

            $columns[$colName] = new ColumnDef(
                name: $colName,
                type: $type,
                nullable: $row['IS_NULLABLE'] === 'YES',
                autoIncrement: $auto,
                default: $default,
                length: $length,
                unsigned: $unsigned,
            );
        }

        // PK via information_schema (more reliable than EXTRA)
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

        // Foreign keys
        $stmt = $pdo->prepare("
            SELECT kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
                   rc.DELETE_RULE
            FROM   information_schema.KEY_COLUMN_USAGE kcu
            JOIN   information_schema.REFERENTIAL_CONSTRAINTS rc
                     ON rc.CONSTRAINT_NAME   = kcu.CONSTRAINT_NAME
                    AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
            WHERE  kcu.TABLE_SCHEMA = ? AND kcu.TABLE_NAME = ?
              AND  kcu.REFERENCED_TABLE_NAME IS NOT NULL
        ");
        $stmt->execute([$dbName, $tableName]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fk) {
            $col = strtolower($fk['COLUMN_NAME']);
            $fkOut[$col] = new FkDef(
                column: $col,
                refTable: strtolower($fk['REFERENCED_TABLE_NAME']),
                refColumn: strtolower($fk['REFERENCED_COLUMN_NAME']),
                onDelete: $fk['DELETE_RULE'] ?? null,
            );
        }

        return new TableDef(
            table: $tableName,
            pk: $pk,
            columns: $columns,
            fkOut: $fkOut,
            fkIn: [],
            enums: $enums,
        );
    }

    // -------------------------------------------------------------------------
    // Second pass: build reverse FK map (fkIn) on every table
    // -------------------------------------------------------------------------

    /**
     * Walk every table's fkOut and register the reverse pointer on the
     * referenced table's fkIn. Must be called after all tables are parsed.
     *
     * @param array<string, TableDef> $tables passed by reference
     */
    private static function linkForeignKeys(array &$tables): void
    {
        $fkIn = [];

        foreach ($tables as $originTable => $tableDef) {
            foreach ($tableDef->fkOut as $col => $fk) {
                $fkIn[$fk->refTable][$originTable][] = $fk;
            }
        }

        // Rebuild TableDef instances (readonly requires full reconstruction)
        foreach ($tables as $name => $tableDef) {
            $tables[$name] = new TableDef(
                table: $tableDef->table,
                pk: $tableDef->pk,
                columns: $tableDef->columns,
                fkOut: $tableDef->fkOut,
                fkIn: $fkIn[$name] ?? [],
                enums: $tableDef->enums,
            );
        }
    }
}
