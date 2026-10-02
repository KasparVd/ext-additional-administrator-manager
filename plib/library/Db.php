<?php

/**
 * The extension's SQLite database, kept in its var directory like other Plesk extensions do:
 * /usr/local/psa/var/modules/additional-administrator-manager/additional-administrator-manager.sqlite3
 *
 * Schema changes are the numbered files in resources/db/migrations/ (001_name.sql, 002_name.sql, ...).
 * The number of the last applied file is kept in SQLite's user_version. Pending migrations are
 * applied when the database is first opened in a request, and by scripts/post-install.php.
 */
class Modules_AdditionalAdministratorManager_Db
{
    const MIGRATIONS_DIR = 'resources/db/migrations';

    /** @var Zend_Db_Adapter_Pdo_Sqlite|null */
    private static $adapter = null;

    private static function path()
    {
        return pm_Context::getVarDir() . pm_Context::getModuleId() . '.sqlite3';
    }

    /**
     * @return Zend_Db_Adapter_Pdo_Sqlite
     */
    public static function adapter()
    {
        if (self::$adapter === null) {
            $path = self::path();
            if (!file_exists($path)) {
                $umask = umask(0077);
                touch($path);
                umask($umask);
            }
            chmod($path, 0600);

            $adapter = new Zend_Db_Adapter_Pdo_Sqlite(['dbname' => $path]);
            $connection = $adapter->getConnection();
            $connection->exec('PRAGMA foreign_keys = ON');
            $connection->exec('PRAGMA busy_timeout = 5000');

            self::applyMigrations($adapter);
            self::$adapter = $adapter;
        }
        return self::$adapter;
    }

    /**
     * Creates or upgrades the database (see scripts/post-install.php).
     */
    public static function migrate()
    {
        self::adapter();
    }

    /**
     * Applies migrations newer than the database's user_version, each in its own transaction.
     */
    private static function applyMigrations(Zend_Db_Adapter_Pdo_Sqlite $adapter)
    {
        $current = (int)$adapter->fetchOne('PRAGMA user_version');

        $files = glob(pm_Context::getPlibDir() . self::MIGRATIONS_DIR . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);

        foreach ($files as $file) {
            $number = (int)basename($file);
            if ($number <= $current) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new pm_Exception('Unable to read database migration ' . basename($file));
            }

            // Plain PDO: Zend's query() would run only the first statement of the script.
            $connection = $adapter->getConnection();
            $connection->exec('BEGIN IMMEDIATE');
            try {
                $connection->exec($sql);
                $connection->exec('PRAGMA user_version = ' . $number);
                $connection->exec('COMMIT');
            } catch (Exception $e) {
                $connection->exec('ROLLBACK');
                throw $e;
            }
            $current = $number;
        }
    }
}
