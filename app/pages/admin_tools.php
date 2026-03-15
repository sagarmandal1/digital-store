<?php

declare(strict_types=1);

auth_require_login();
csrf_verify();

if (!app_is_super_admin()) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

if (!app_db_initialized()) {
    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
    ?>
    <h1 class="h3">Admin Tools</h1>
    <div class="alert alert-warning">Initialize the database first.</div>
    <?php
    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php';
    exit;
}

$action = (string)($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'download_db') {
    $dbPath = app_db_path();
    if (!is_file($dbPath)) {
        http_response_code(404);
        echo 'DB not found';
        exit;
    }

    $filename = 'backup_' . date('Ymd_His') . '.sqlite';
    header('Content-Type: application/x-sqlite3');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string)filesize($dbPath));
    readfile($dbPath);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'restore_db') {
    $confirm = (string)($_POST['confirm'] ?? '') === '1';
    if (!$confirm) {
        flash_set('danger', 'Please confirm restore.');
        header('Location: index.php?page=admin_tools');
        exit;
    }

    $file = $_FILES['db_file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash_set('danger', 'Invalid database upload.');
        header('Location: index.php?page=admin_tools');
        exit;
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    $fp = @fopen($tmp, 'rb');
    $header = $fp ? (string)fread($fp, 16) : '';
    if (is_resource($fp)) {
        fclose($fp);
    }
    if (!str_starts_with($header, 'SQLite format 3')) {
        flash_set('danger', 'Uploaded file is not a valid SQLite database.');
        header('Location: index.php?page=admin_tools');
        exit;
    }

    $dbPath = app_db_path();
    $dbDir = dirname($dbPath);
    if (!is_dir($dbDir)) {
        @mkdir($dbDir, 0777, true);
    }

    $backupPath = $dbDir . DIRECTORY_SEPARATOR . 'backup_before_restore_' . date('Ymd_His') . '.sqlite';
    $newPath = $dbDir . DIRECTORY_SEPARATOR . 'restore_' . date('Ymd_His') . '.sqlite';
    if (!move_uploaded_file($tmp, $newPath)) {
        flash_set('danger', 'Failed to save uploaded database.');
        header('Location: index.php?page=admin_tools');
        exit;
    }

    $pdo = null;
    if (is_file($dbPath)) {
        @rename($dbPath, $backupPath);
    }
    $ok = @rename($newPath, $dbPath);
    if (!$ok) {
        if (is_file($backupPath)) {
            @rename($backupPath, $dbPath);
        }
        @unlink($newPath);
        flash_set('danger', 'Restore failed.');
        header('Location: index.php?page=admin_tools');
        exit;
    }

    flash_set('success', 'Database restored. Please refresh the app.');
    header('Location: index.php?page=admin_tools');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'test_email') {
    $to = trim((string)($_POST['to_email'] ?? ''));
    if ($to === '') {
        flash_set('danger', 'Email is required.');
        header('Location: index.php?page=admin_tools');
        exit;
    }
    $ok = app_send_mail($to, 'Test Email', '<p>This is a test email.</p>');
    flash_set($ok ? 'success' : 'danger', $ok ? 'Test email sent.' : 'Failed to send test email. Check SMTP settings.');
    header('Location: index.php?page=admin_tools');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'test_whatsapp') {
    $number = trim((string)($_POST['wa_number'] ?? ''));
    $message = trim((string)($_POST['wa_message'] ?? ''));
    if ($number === '' || $message === '') {
        flash_set('danger', 'Number and message are required.');
        header('Location: index.php?page=admin_tools');
        exit;
    }
    $res = whatsapp_send_text($number, $message);
    if (($res['ok'] ?? false) === true) {
        flash_set('success', 'Test WhatsApp message sent.');
    } else {
        $msg = (string)($res['error'] ?? '');
        if ($msg === '') {
            $msg = (string)($res['raw'] ?? 'WhatsApp send failed.');
        }
        flash_set('danger', $msg);
    }
    header('Location: index.php?page=admin_tools');
    exit;
}

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_registration') {
    $enabled = isset($_POST['public_registration_enabled']) ? '1' : '0';
    $code = trim((string)($_POST['public_registration_code'] ?? ''));
    try {
        $stmt = $pdo->prepare("INSERT INTO settings (key, value, admin_id) VALUES (:key, :value, 0)
            ON CONFLICT(key, admin_id) DO UPDATE SET value=excluded.value");
        $stmt->execute([':key' => 'public_registration_enabled', ':value' => $enabled]);
        $stmt->execute([':key' => 'public_registration_code', ':value' => $code]);
        app_audit_log('update', 'registration', 0, ['enabled' => $enabled === '1']);
        flash_set('success', 'Registration settings saved.');
    } catch (Throwable $e) {
        flash_set('danger', 'Failed to save registration settings.');
    }
    header('Location: index.php?page=admin_tools');
    exit;
}

$registrationEnabled = true;
$registrationCode = '';
try {
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = :key AND admin_id = 0 LIMIT 1');
    $stmt->execute([':key' => 'public_registration_enabled']);
    $v = $stmt->fetchColumn();
    $registrationEnabled = $v === false ? true : ((string)$v !== '0');
    $stmt->execute([':key' => 'public_registration_code']);
    $c = $stmt->fetchColumn();
    $registrationCode = $c === false ? '' : (string)$c;
} catch (Throwable $e) {
    $registrationEnabled = true;
    $registrationCode = '';
}

$adminEmail = (string)($_SESSION['admin_email'] ?? '');

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h3 mb-0">Admin Tools</h1>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="fw-semibold mb-2">Database Backup</div>
                <form method="post" action="index.php?page=admin_tools">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="download_db">
                    <button class="btn btn-outline-primary" type="submit">Download Backup</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="fw-semibold mb-2">Database Restore</div>
                <form method="post" action="index.php?page=admin_tools" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="restore_db">
                    <div class="mb-2">
                        <input class="form-control" type="file" name="db_file" accept=".sqlite,.db" required>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="confirm" id="confirmRestore" value="1" required>
                        <label class="form-check-label" for="confirmRestore">I understand this will replace the current database</label>
                    </div>
                    <button class="btn btn-outline-danger" type="submit">Restore Backup</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="fw-semibold mb-2">Test Email (SMTP)</div>
                <form method="post" action="index.php?page=admin_tools" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="test_email">
                    <div class="col-12">
                        <input class="form-control" name="to_email" type="email" value="<?= e($adminEmail) ?>" placeholder="name@example.com" required>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-outline-secondary" type="submit">Send Test Email</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="fw-semibold mb-2">Test WhatsApp</div>
                <form method="post" action="index.php?page=admin_tools" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="test_whatsapp">
                    <div class="col-12">
                        <input class="form-control" name="wa_number" placeholder="8801XXXXXXXXX" required>
                    </div>
                    <div class="col-12">
                        <textarea class="form-control" name="wa_message" rows="3" placeholder="Test message" required>This is a test WhatsApp message.</textarea>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-outline-success" type="submit">Send Test Message</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="fw-semibold mb-2">Registration</div>
                <form method="post" action="index.php?page=admin_tools" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_registration">
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="regEnabled" name="public_registration_enabled" <?= $registrationEnabled ? 'checked' : '' ?>>
                            <label class="form-check-label" for="regEnabled">Enable public registration</label>
                        </div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <label class="form-label">Registration Code (optional)</label>
                        <input class="form-control" name="public_registration_code" value="<?= e($registrationCode) ?>" placeholder="Leave empty for open registration">
                        <div class="form-text">If set, users must enter this code on Register page.</div>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-outline-primary" type="submit">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
