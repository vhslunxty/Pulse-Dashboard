<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;

    if ($pdo) {
        return $pdo;
    }

    $path = getenv('DB_PATH') ?: __DIR__ . '/../data/app.db';

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec('
        PRAGMA journal_mode = WAL;
        PRAGMA busy_timeout = 5000;
        CREATE TABLE IF NOT EXISTS rules (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            event TEXT NOT NULL,
            script TEXT NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL DEFAULT (strftime(\'%s\', \'now\'))
        );
        CREATE INDEX IF NOT EXISTS idx_rules_event ON rules (event, enabled);
        CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            payload TEXT NOT NULL DEFAULT \'{}\',
            created_at INTEGER NOT NULL DEFAULT (strftime(\'%s\', \'now\'))
        );
        CREATE INDEX IF NOT EXISTS idx_events_created ON events (created_at);
    ');

    return $pdo;
}
