<?php

declare(strict_types=1);

/**
 * Singleton PDO wrapper.
 * Usage:  $pdo = Database::connection();
 */
class Database
{
    private static ?PDO $instance = null;

    private function __construct() {}

    public static function connection(): PDO
    {
        if (self::$instance === null) {
            $host   = env('DB_HOST', 'mysql');
            $port   = env('DB_PORT', '3306');
            $dbname = env('DB_DATABASE', 'zk_attendance');
            $user   = env('DB_USERNAME', 'zk_user');
            $pass   = env('DB_PASSWORD', '');

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

            self::$instance = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }

        return self::$instance;
    }

    /** Reset the singleton (useful in tests). */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
