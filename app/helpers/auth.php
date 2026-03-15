<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'db.php';

function auth_is_logged_in(): bool
{
    return isset($_SESSION['admin_id']) && is_int($_SESSION['admin_id']);
}

function auth_require_login(): void
{
    if (!auth_is_logged_in()) {
        header('Location: index.php?page=login');
        exit;
    }

    try {
        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        if ($adminId > 0) {
            $stmt = db()->prepare('SELECT COALESCE(active, 1) FROM admins WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $adminId]);
            $active = (int)($stmt->fetchColumn() ?: 1);
            if ($active !== 1) {
                auth_logout();
                flash_set('danger', 'Account is disabled. Contact admin.');
                header('Location: index.php?page=login');
                exit;
            }
        }
    } catch (Throwable $e) {
    }
}

function auth_login(string $email, string $password): bool
{
    $stmt = db()->prepare('SELECT id, password_hash, name, email, COALESCE(active, 1) AS active FROM admins WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $admin = $stmt->fetch();

    if (!$admin) {
        return false;
    }

    if ((int)($admin['active'] ?? 1) !== 1) {
        $_SESSION['auth_error'] = 'Account is disabled. Contact admin.';
        return false;
    }

    if (!password_verify($password, (string)$admin['password_hash'])) {
        return false;
    }

    $adminId = (int)$admin['id'];

    if (auth_otp_required_for_admin($adminId)) {
        if (!auth_smtp_config_available($adminId)) {
            $_SESSION['otp_error'] = 'OTP is required. Admin must configure SMTP in Settings.';
            return false;
        }

        $otpId = auth_create_and_send_login_otp($adminId, (string)($admin['email'] ?? ''), (string)($admin['name'] ?? ''));
        if ($otpId > 0) {
            $_SESSION['otp_pending_id'] = $otpId;
            $_SESSION['otp_pending_admin_id'] = $adminId;
            $_SESSION['otp_pending_email'] = (string)($admin['email'] ?? '');
            $_SESSION['otp_pending_name'] = (string)($admin['name'] ?? '');
            return false;
        }
        $_SESSION['otp_error'] = 'OTP could not be sent. Check SMTP settings.';
        return false;
    }

    auth_finalize_login($adminId, (string)($admin['name'] ?? ''), (string)($admin['email'] ?? ''));

    session_regenerate_id(true);

    return true;
}

function auth_finalize_login(int $adminId, string $name, string $email): void
{
    $_SESSION['admin_id'] = $adminId;
    $_SESSION['admin_name'] = $name;
    $_SESSION['admin_email'] = $email;
}

function auth_otp_required_for_admin(int $adminId): bool
{
    return $adminId !== 1;
}

function auth_smtp_config_available(int $adminId): bool
{
    $stmt = db()->prepare('SELECT value FROM settings WHERE key = :key AND admin_id = 0 LIMIT 1');
    $stmt->execute([':key' => 'smtp_user']);
    $smtpUser = (string)($stmt->fetchColumn() ?: '');
    $stmt->execute([':key' => 'smtp_pass']);
    $smtpPass = (string)($stmt->fetchColumn() ?: '');
    return $smtpUser !== '' && $smtpPass !== '';
}

function auth_ensure_otp_table(): void
{
    db()->exec('CREATE TABLE IF NOT EXISTS admin_login_otps (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_id INTEGER NOT NULL,
        otp_hash TEXT NOT NULL,
        expires_at INTEGER NOT NULL,
        attempts INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL,
        last_sent_at INTEGER NOT NULL
    )');
    db()->exec('CREATE INDEX IF NOT EXISTS idx_admin_login_otps_admin ON admin_login_otps(admin_id)');
}

function auth_create_and_send_login_otp(int $adminId, string $email, string $name): int
{
    if ($email === '') {
        return 0;
    }

    auth_ensure_otp_table();

    $code = (string)random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $now = time();
    $expiresAt = $now + 300;

    $stmt = db()->prepare('INSERT INTO admin_login_otps (admin_id, otp_hash, expires_at, attempts, created_at, last_sent_at) VALUES (:aid, :hash, :exp, 0, :now, :now)');
    $stmt->execute([':aid' => $adminId, ':hash' => $hash, ':exp' => $expiresAt, ':now' => $now]);
    $otpId = (int)db()->lastInsertId();

    $subject = 'Your Login OTP';
    $safeName = e($name);
    $safeCode = e($code);
    $body = "<p>Hello {$safeName},</p><p>Your OTP code is: <b>{$safeCode}</b></p><p>This code will expire in 5 minutes.</p>";

    $sent = app_send_mail_for_admin($adminId, $email, $subject, $body);
    if (!$sent) {
        db()->prepare('DELETE FROM admin_login_otps WHERE id = :id')->execute([':id' => $otpId]);
        return 0;
    }

    return $otpId;
}

function auth_get_pending_otp(): array
{
    $otpId = (int)($_SESSION['otp_pending_id'] ?? 0);
    $adminId = (int)($_SESSION['otp_pending_admin_id'] ?? 0);
    if ($otpId <= 0 || $adminId <= 0) {
        return [];
    }
    auth_ensure_otp_table();
    $stmt = db()->prepare('SELECT * FROM admin_login_otps WHERE id = :id AND admin_id = :aid LIMIT 1');
    $stmt->execute([':id' => $otpId, ':aid' => $adminId]);
    $row = $stmt->fetch();
    return $row ?: [];
}

function auth_verify_pending_otp(string $otp): bool
{
    $otp = trim($otp);
    if ($otp === '' || strlen($otp) > 10) {
        return false;
    }

    $pending = auth_get_pending_otp();
    if (!$pending) {
        return false;
    }

    $otpId = (int)$pending['id'];
    $adminId = (int)$pending['admin_id'];
    $expiresAt = (int)$pending['expires_at'];
    $attempts = (int)$pending['attempts'];
    $hash = (string)$pending['otp_hash'];

    if (time() > $expiresAt || $attempts >= 5) {
        return false;
    }

    $ok = password_verify($otp, $hash);
    if (!$ok) {
        db()->prepare('UPDATE admin_login_otps SET attempts = attempts + 1 WHERE id = :id')->execute([':id' => $otpId]);
        return false;
    }

    $stmt = db()->prepare('SELECT id, name, email, COALESCE(active, 1) AS active FROM admins WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $adminId]);
    $admin = $stmt->fetch();
    if (!$admin) {
        return false;
    }
    if ((int)($admin['active'] ?? 1) !== 1) {
        return false;
    }

    auth_finalize_login((int)$admin['id'], (string)($admin['name'] ?? ''), (string)($admin['email'] ?? ''));
    session_regenerate_id(true);

    db()->prepare('DELETE FROM admin_login_otps WHERE id = :id')->execute([':id' => $otpId]);
    unset($_SESSION['otp_pending_id'], $_SESSION['otp_pending_admin_id'], $_SESSION['otp_pending_email'], $_SESSION['otp_pending_name']);

    return true;
}

function auth_resend_pending_otp(): bool
{
    $pending = auth_get_pending_otp();
    if (!$pending) {
        return false;
    }

    $otpId = (int)$pending['id'];
    $adminId = (int)$pending['admin_id'];
    $lastSentAt = (int)$pending['last_sent_at'];

    if (time() - $lastSentAt < 60) {
        return false;
    }

    $stmt = db()->prepare('SELECT name, email, COALESCE(active, 1) AS active FROM admins WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $adminId]);
    $admin = $stmt->fetch();
    if (!$admin) {
        return false;
    }
    if ((int)($admin['active'] ?? 1) !== 1) {
        return false;
    }

    $email = (string)($admin['email'] ?? '');
    if ($email === '') {
        return false;
    }

    $code = (string)random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $now = time();
    $expiresAt = $now + 300;

    $stmt = db()->prepare('UPDATE admin_login_otps SET otp_hash = :hash, expires_at = :exp, attempts = 0, last_sent_at = :now WHERE id = :id AND admin_id = :aid');
    $stmt->execute([':hash' => $hash, ':exp' => $expiresAt, ':now' => $now, ':id' => $otpId, ':aid' => $adminId]);

    $subject = 'Your Login OTP';
    $safeName = e((string)($admin['name'] ?? ''));
    $safeCode = e($code);
    $body = "<p>Hello {$safeName},</p><p>Your OTP code is: <b>{$safeCode}</b></p><p>This code will expire in 5 minutes.</p>";

    return app_send_mail_for_admin($adminId, $email, $subject, $body);
}

function auth_ensure_password_reset_table(): void
{
    db()->exec('CREATE TABLE IF NOT EXISTS admin_password_resets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_id INTEGER NOT NULL,
        otp_hash TEXT NOT NULL,
        expires_at INTEGER NOT NULL,
        attempts INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL,
        last_sent_at INTEGER NOT NULL
    )');
    db()->exec('CREATE INDEX IF NOT EXISTS idx_admin_password_resets_admin ON admin_password_resets(admin_id)');
}

function auth_create_password_reset(int $adminId): int
{
    auth_ensure_password_reset_table();

    $stmt = db()->prepare('SELECT name, email, COALESCE(active, 1) AS active FROM admins WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $adminId]);
    $admin = $stmt->fetch();
    if (!$admin) {
        return 0;
    }
    if ((int)($admin['active'] ?? 1) !== 1) {
        return 0;
    }

    $code = (string)random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $now = time();
    $expiresAt = $now + 600;

    $stmt = db()->prepare('INSERT INTO admin_password_resets (admin_id, otp_hash, expires_at, attempts, created_at, last_sent_at) VALUES (:aid, :hash, :exp, 0, :now, :now)');
    $stmt->execute([':aid' => $adminId, ':hash' => $hash, ':exp' => $expiresAt, ':now' => $now]);
    $resetId = (int)db()->lastInsertId();

    $email = (string)($admin['email'] ?? '');
    if ($email === '') {
        db()->prepare('DELETE FROM admin_password_resets WHERE id = :id')->execute([':id' => $resetId]);
        return 0;
    }

    $subject = 'Password Reset OTP';
    $safeName = e((string)($admin['name'] ?? ''));
    $safeCode = e($code);
    $body = "<p>Hello {$safeName},</p><p>Your password reset OTP is: <b>{$safeCode}</b></p><p>This code will expire in 10 minutes.</p>";

    $sent = app_send_mail_for_admin($adminId, $email, $subject, $body);
    if (!$sent) {
        db()->prepare('DELETE FROM admin_password_resets WHERE id = :id')->execute([':id' => $resetId]);
        return 0;
    }

    return $resetId;
}

function auth_get_pending_password_reset(): array
{
    $resetId = (int)($_SESSION['reset_pending_id'] ?? 0);
    $adminId = (int)($_SESSION['reset_pending_admin_id'] ?? 0);
    if ($resetId <= 0 || $adminId <= 0) {
        return [];
    }
    auth_ensure_password_reset_table();
    $stmt = db()->prepare('SELECT * FROM admin_password_resets WHERE id = :id AND admin_id = :aid LIMIT 1');
    $stmt->execute([':id' => $resetId, ':aid' => $adminId]);
    $row = $stmt->fetch();
    return $row ?: [];
}

function auth_verify_pending_password_reset(string $otp, string $newPassword): bool
{
    $otp = trim($otp);
    if ($otp === '' || strlen($otp) > 10) {
        return false;
    }
    if (strlen($newPassword) < 6) {
        return false;
    }

    $pending = auth_get_pending_password_reset();
    if (!$pending) {
        return false;
    }

    $resetId = (int)$pending['id'];
    $adminId = (int)$pending['admin_id'];
    $expiresAt = (int)$pending['expires_at'];
    $attempts = (int)$pending['attempts'];
    $hash = (string)$pending['otp_hash'];

    if (time() > $expiresAt || $attempts >= 5) {
        return false;
    }

    $ok = password_verify($otp, $hash);
    if (!$ok) {
        db()->prepare('UPDATE admin_password_resets SET attempts = attempts + 1 WHERE id = :id')->execute([':id' => $resetId]);
        return false;
    }

    $stmt = db()->prepare('SELECT COALESCE(active, 1) AS active FROM admins WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $adminId]);
    $active = (int)($stmt->fetchColumn() ?: 1);
    if ($active !== 1) {
        return false;
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = db()->prepare('UPDATE admins SET password_hash = :hash WHERE id = :id');
    $stmt->execute([':hash' => $newHash, ':id' => $adminId]);

    db()->prepare('DELETE FROM admin_password_resets WHERE id = :id')->execute([':id' => $resetId]);
    unset($_SESSION['reset_pending_id'], $_SESSION['reset_pending_admin_id'], $_SESSION['reset_pending_email']);

    return true;
}

function auth_resend_pending_password_reset(): bool
{
    $pending = auth_get_pending_password_reset();
    if (!$pending) {
        return false;
    }

    $resetId = (int)$pending['id'];
    $adminId = (int)$pending['admin_id'];
    $lastSentAt = (int)$pending['last_sent_at'];

    if (time() - $lastSentAt < 60) {
        return false;
    }

    $stmt = db()->prepare('SELECT name, email, COALESCE(active, 1) AS active FROM admins WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $adminId]);
    $admin = $stmt->fetch();
    if (!$admin) {
        return false;
    }
    if ((int)($admin['active'] ?? 1) !== 1) {
        return false;
    }

    $email = (string)($admin['email'] ?? '');
    if ($email === '') {
        return false;
    }

    $code = (string)random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $now = time();
    $expiresAt = $now + 600;

    $stmt = db()->prepare('UPDATE admin_password_resets SET otp_hash = :hash, expires_at = :exp, attempts = 0, last_sent_at = :now WHERE id = :id AND admin_id = :aid');
    $stmt->execute([':hash' => $hash, ':exp' => $expiresAt, ':now' => $now, ':id' => $resetId, ':aid' => $adminId]);

    $subject = 'Password Reset OTP';
    $safeName = e((string)($admin['name'] ?? ''));
    $safeCode = e($code);
    $body = "<p>Hello {$safeName},</p><p>Your password reset OTP is: <b>{$safeCode}</b></p><p>This code will expire in 10 minutes.</p>";

    return app_send_mail_for_admin($adminId, $email, $subject, $body);
}

function auth_logout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }

    session_destroy();
}
