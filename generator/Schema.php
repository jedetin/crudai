<?php

declare(strict_types=1);

/**
 * Pure data structs — Internal Representation (IR).
 * No logic here; just typed containers the Generator reads.
 */

readonly class ColumnDef
{
    public function __construct(
        public string  $name,
        public string  $type,          // normalised: varchar, int, bigint, decimal, text,
                                       //             timestamp, date, enum, tinyint
        public bool    $nullable,
        public bool    $autoIncrement,
        public ?string $default,       // raw default string, or null
        public ?int    $length,        // varchar/char length; null for others
        public bool    $unsigned,
    ) {}

    public function isTimestamp(): bool
    {
        return $this->type === 'timestamp';
    }

    public function isEnum(): bool
    {
        return $this->type === 'enum';
    }

    /**
     * Columns the generator should exclude from INSERT / UPDATE payloads:
     * auto-increment PKs, created_at with CURRENT_TIMESTAMP default, updated_at.
     */
    public function isAutoManaged(): bool
    {
        return $this->autoIncrement
            || ($this->name === 'created_at' && $this->default === 'CURRENT_TIMESTAMP')
            || $this->name === 'updated_at';
    }
}


readonly class FkDef
{
    public function __construct(
        public string  $column,      // local column  e.g. user_id
        public string  $refTable,    // referenced table  e.g. users
        public string  $refColumn,   // referenced column  e.g. id
        public ?string $onDelete,    // CASCADE | SET NULL | RESTRICT | null
    ) {}
}


readonly class TableDef
{
    /**
     * @param array<string, ColumnDef> $columns
     * @param array<string, FkDef>     $fkOut   FKs this table owns  (col → FkDef)
     * @param array<string, FkDef[]>   $fkIn    Reverse: tables pointing here  (refTable → FkDef[])
     * @param array<string, string[]>  $enums   ENUM allowed values  (col → values)
     */
    public function __construct(
        public string $table,
        public string $pk,
        public array  $columns,
        public array  $fkOut,
        public array  $fkIn,
        public array  $enums,
    ) {}

    /** Every column name — used for SELECT * equivalent. */
    public function selectableColumns(): array
    {
        return array_keys($this->columns);
    }

    /** Columns accepted in POST body (excludes auto-managed). */
    public function insertableColumns(): array
    {
        return array_values(array_filter(
            $this->columns,
            fn(ColumnDef $c) => !$c->isAutoManaged()
        ));
    }

    /** Columns accepted in PUT body (excludes PK + auto-managed). */
    public function updatableColumns(): array
    {
        return array_values(array_filter(
            $this->columns,
            fn(ColumnDef $c) => !$c->isAutoManaged() && $c->name !== $this->pk
        ));
    }
}