<?php

declare(strict_types=1);

function app_client_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return $ip !== '' ? $ip : 'unknown';
}

function app_rate_limit_key(string $action, string $suffix = ''): string
{
    $parts = [$action, app_client_ip()];
    if ($suffix !== '') {
        $parts[] = strtolower(trim($suffix));
    }
    return hash('sha256', implode('|', $parts));
}

function app_migrate_rate_limits_table(): void
{
    if (!app_db_initialized()) {
        return;
    }
    try {
        db()->exec('CREATE TABLE IF NOT EXISTS rate_limits (
            key TEXT PRIMARY KEY,
            hits INTEGER NOT NULL,
            reset_at INTEGER NOT NULL
        )');
        db()->exec('CREATE INDEX IF NOT EXISTS idx_rate_limits_reset ON rate_limits(reset_at)');
    } catch (Throwable $e) {
        return;
    }
}

function app_rate_limit_allow(string $key, int $maxHits, int $windowSeconds): array
{
    $now = time();
    $maxHits = max(1, $maxHits);
    $windowSeconds = max(1, $windowSeconds);

    if (!app_db_initialized()) {
        if (!isset($_SESSION['_rl']) || !is_array($_SESSION['_rl'])) {
            $_SESSION['_rl'] = [];
        }
        $bucket = (array)($_SESSION['_rl'][$key] ?? []);
        $resetAt = (int)($bucket['reset_at'] ?? 0);
        $hits = (int)($bucket['hits'] ?? 0);
        if ($resetAt <= $now) {
            $_SESSION['_rl'][$key] = ['hits' => 1, 'reset_at' => $now + $windowSeconds];
            return ['ok' => true, 'retry_after' => 0];
        }
        if ($hits >= $maxHits) {
            return ['ok' => false, 'retry_after' => max(1, $resetAt - $now)];
        }
        $_SESSION['_rl'][$key] = ['hits' => $hits + 1, 'reset_at' => $resetAt];
        return ['ok' => true, 'retry_after' => 0];
    }

    app_migrate_rate_limits_table();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT hits, reset_at FROM rate_limits WHERE key = :k LIMIT 1');
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $hits = (int)($row['hits'] ?? 0);
        $resetAt = (int)($row['reset_at'] ?? 0);

        if ($resetAt <= $now) {
            $stmt = $pdo->prepare('INSERT INTO rate_limits (key, hits, reset_at) VALUES (:k, 1, :r)
                ON CONFLICT(key) DO UPDATE SET hits=1, reset_at=excluded.reset_at');
            $stmt->execute([':k' => $key, ':r' => $now + $windowSeconds]);
            $pdo->commit();
            return ['ok' => true, 'retry_after' => 0];
        }

        if ($hits >= $maxHits) {
            $pdo->commit();
            return ['ok' => false, 'retry_after' => max(1, $resetAt - $now)];
        }

        $stmt = $pdo->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE key = :k');
        $stmt->execute([':k' => $key]);
        $pdo->commit();
        return ['ok' => true, 'retry_after' => 0];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => true, 'retry_after' => 0];
    }
}

