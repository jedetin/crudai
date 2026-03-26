<?php

declare(strict_types=1);

class DB
{
    private static ?PDO $pdo = null;

    public static function connect(array $cfg): void
    {
        if (self::$pdo !== null) return;

        $dsn = "mysql:host={$cfg['host']};dbname={$cfg['name']};charset=utf8mb4";
        self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    public static function get(): PDO
    {
        if (self::$pdo === null) {
            throw new RuntimeException('DB::connect() must be called before DB::get()');
        }
        return self::$pdo;
    }
}