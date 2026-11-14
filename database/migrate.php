<?php

declare(strict_types=1);

/**
 * Aplica las migraciones SQL de database/migrations en orden.
 * Uso: php database/migrate.php
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;

$host = (string) Config::get('db.host', '127.0.0.1');
$port = (int) Config::get('db.port', 3306);
$database = (string) Config::get('db.database', 'appmenu');
$charset = (string) Config::get('db.charset', 'utf8mb4');
$username = (string) Config::get('db.username', 'root');
$password = (string) Config::get('db.password', '');

try {
    $server = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=%s', $host, $port, $charset),
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    $server->exec(sprintf(
        'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        str_replace('`', '', $database),
    ));

    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset),
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ],
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            migration VARCHAR(190) NOT NULL,
            applied_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_schema_migration (migration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    );

    $applied = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $applied = array_flip(array_map('strval', $applied));

    $files = glob(__DIR__ . '/migrations/*.sql') ?: [];
    sort($files, SORT_STRING);

    if ($files === []) {
        fwrite(STDERR, "No hay migraciones en database/migrations.\n");
        exit(1);
    }

    $ran = 0;
    foreach ($files as $file) {
        $name = basename($file);
        if (isset($applied[$name])) {
            echo "· Ya aplicada: {$name}\n";
            continue;
        }

        $sql = (string) file_get_contents($file);
        $statements = splitSqlStatements($sql);

        // MySQL/MariaDB hacen commit implícito en DDL: no se envuelve en transacción.
        try {
            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }

            $insert = $pdo->prepare('INSERT INTO schema_migrations (migration, applied_at) VALUES (?, ?)');
            $insert->execute([$name, date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            fwrite(STDERR, "Error aplicando {$name}: " . $e->getMessage() . "\n");
            exit(1);
        }

        echo "✓ Aplicada: {$name}\n";
        $ran++;
    }

    echo $ran === 0
        ? "Base de datos al día ({$database}).\n"
        : "Migraciones completadas: {$ran} archivo(s) en {$database}.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'No se pudo migrar: ' . $e->getMessage() . "\n");
    exit(1);
}

/** @return array<int, string> */
function splitSqlStatements(string $sql): array
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    $statements = [];

    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
            $statements[] = $part;
        }
    }

    return $statements;
}
