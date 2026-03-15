<?php

declare(strict_types=1);

function app_migrate_audit_logs_table(): void
{
    if (!app_db_initialized()) {
        return;
    }
    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_admin_id INTEGER NOT NULL,
            scope_admin_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            entity TEXT NOT NULL,
            entity_id INTEGER NOT NULL DEFAULT 0,
            meta_json TEXT NOT NULL DEFAULT '{}',
            ip TEXT NOT NULL DEFAULT '',
            user_agent TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_created ON audit_logs(created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_actor ON audit_logs(actor_admin_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_scope ON audit_logs(scope_admin_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_entity ON audit_logs(entity, entity_id)');
    } catch (Throwable $e) {
        return;
    }
}

function app_audit_log(string $action, string $entity, int $entityId = 0, array $meta = []): void
{
    if (!app_db_initialized()) {
        return;
    }
    try {
        app_migrate_audit_logs_table();
        $actorAdminId = (int)($_SESSION['admin_id'] ?? 0);
        $scopeAdminId = app_scope_admin_id();
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $createdAt = time();
        $json = json_encode($meta, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{}';
        }

        $stmt = db()->prepare('
            INSERT INTO audit_logs (actor_admin_id, scope_admin_id, action, entity, entity_id, meta_json, ip, user_agent, created_at)
            VALUES (:actor, :scope, :action, :entity, :eid, :meta, :ip, :ua, :created)
        ');
        $stmt->execute([
            ':actor' => $actorAdminId,
            ':scope' => $scopeAdminId,
            ':action' => $action,
            ':entity' => $entity,
            ':eid' => $entityId,
            ':meta' => $json,
            ':ip' => $ip,
            ':ua' => $ua,
            ':created' => $createdAt,
        ]);
    } catch (Throwable $e) {
        return;
    }
}
