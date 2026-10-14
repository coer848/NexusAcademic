<?php
/**
 * NexusAcademic - Database Manager (PDO MySQL / MariaDB)
 */

require_once __DIR__ . '/config.php';

class Database {
    private static ?PDO $instance = null;

    private static bool $schemaMigrated = false;

    /**
     * Get or initialize the PDO connection singleton
     */
    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_CHARSET
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                // If database doesn't exist yet, attempt auto-creation
                if ($e->getCode() == 1049) {
                    self::createDatabaseIfNotExists();
                    self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
                } else {
                    error_log('Database Connection Error: ' . $e->getMessage());
                    throw $e;
                }
            }
        }

        // Automatic schema self-healing: ensure phone column exists in users table
        if (!self::$schemaMigrated && self::$instance !== null) {
            self::$schemaMigrated = true;
            try {
                $checkTable = self::$instance->query("SHOW TABLES LIKE 'users'")->fetch();
                if ($checkTable) {
                    $checkCol = self::$instance->query("SHOW COLUMNS FROM `users` LIKE 'phone'")->fetch();
                    if (!$checkCol) {
                        self::$instance->exec("ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(30) DEFAULT NULL AFTER `last_name`");
                    }
                }
            } catch (Throwable $ignore) {
                // Ignore if tables are not initialized yet
            }
        }

        return self::$instance;
    }

    /**
     * Auto-creates the database if it doesn't exist
     */
    private static function createDatabaseIfNotExists(): void {
        try {
            $rawDsn = sprintf('mysql:host=%s;port=%s;charset=%s', DB_HOST, DB_PORT, DB_CHARSET);
            $pdo = new PDO($rawDsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec(sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
                DB_NAME
            ));
        } catch (PDOException $e) {
            error_log('Failed to create database: ' . $e->getMessage());
        }
    }

    /**
     * Executes a callback within a database transaction
     *
     * @param callable $fn Callback accepting PDO instance as argument
     * @return mixed Result of the callback
     * @throws Exception
     */
    public static function transaction(callable $fn) {
        $pdo = self::getConnection();
        $isTopLevel = !$pdo->inTransaction();

        if ($isTopLevel) {
            $pdo->beginTransaction();
        }

        try {
            $result = $fn($pdo);
            if ($isTopLevel) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($isTopLevel && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
