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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_global_notice') {
    $notice = trim((string)($_POST['global_notice'] ?? ''));
    try {
        $stmt = $pdo->prepare("INSERT INTO settings (key, value, admin_id) VALUES (:key, :value, 0)
            ON CONFLICT(key, admin_id) DO UPDATE SET value=excluded.value");
        $stmt->execute([':key' => 'global_notice', ':value' => $notice]);
        app_audit_log('update', 'global_notice', 0, ['enabled' => $notice !== '']);
        flash_set('success', 'Global notice updated.');
    } catch (Throwable $e) {
        flash_set('danger', 'Failed to update global notice.');
    }
    header('Location: index.php?page=admin_tools');
    exit;
}

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
$globalNotice = '';
try {
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = :key AND admin_id = 0 LIMIT 1');
    $stmt->execute([':key' => 'public_registration_enabled']);
    $v = $stmt->fetchColumn();
    $registrationEnabled = $v === false ? true : ((string)$v !== '0');
    $stmt->execute([':key' => 'public_registration_code']);
    $c = $stmt->fetchColumn();
    $registrationCode = $c === false ? '' : (string)$c;
    $stmt->execute([':key' => 'global_notice']);
    $n = $stmt->fetchColumn();
    $globalNotice = $n === false ? '' : (string)$n;
} catch (Throwable $e) {
    $registrationEnabled = true;
    $registrationCode = '';
    $globalNotice = '';
}

$adminEmail = (string)($_SESSION['admin_email'] ?? '');

$dbPath = app_db_path();
$dbSize = is_file($dbPath) ? (int)filesize($dbPath) : 0;

$health = [
    'smtp_ready' => false,
    'wa_ready' => false,
    'registration_enabled' => $registrationEnabled,
    'db_size_label' => $dbSize > 0 ? number_format($dbSize / 1024 / 1024, 2) . ' MB' : 'N/A',
    'total_users' => 0,
    'active_users' => 0,
];

try {
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = :key AND admin_id = 0 LIMIT 1');
    $stmt->execute([':key' => 'smtp_host']);
    $smtpHost = trim((string)($stmt->fetchColumn() ?: ''));
    $stmt->execute([':key' => 'smtp_user']);
    $smtpUser = trim((string)($stmt->fetchColumn() ?: ''));
    $stmt->execute([':key' => 'smtp_pass']);
    $smtpPass = trim((string)($stmt->fetchColumn() ?: ''));
    $health['smtp_ready'] = $smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '';

    $stmt->execute([':key' => 'whatsapp_base_url']);
    $waBase = trim((string)($stmt->fetchColumn() ?: ''));
    $stmt->execute([':key' => 'whatsapp_api_key']);
    $waKey = trim((string)($stmt->fetchColumn() ?: ''));
    $health['wa_ready'] = $waBase !== '' && $waKey !== '';

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE role='user'");
    $stmt->execute();
    $health['total_users'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE role='user' AND COALESCE(active,1)=1");
    $stmt->execute();
    $health['active_users'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h3 mb-0">Admin Tools</h1>
</div>

<div class="row g-3 mb-1">
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm bg-primary text-white h-100">
            <div class="card-body">
                <div class="small opacity-75">Platform Users</div>
                <div class="h4 mb-0 fw-bold"><?= (int)$health['total_users'] ?></div>
                <div class="small opacity-75 mt-1">Active: <?= (int)$health['active_users'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm <?= $health['smtp_ready'] ? 'bg-success text-white' : 'bg-danger text-white' ?> h-100">
            <div class="card-body">
                <div class="small opacity-75">SMTP Health</div>
                <div class="h5 mb-0 fw-bold"><?= $health['smtp_ready'] ? 'Configured' : 'Needs Setup' ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm <?= $health['wa_ready'] ? 'bg-success text-white' : 'bg-warning text-dark' ?> h-100">
            <div class="card-body">
                <div class="small opacity-75">WhatsApp API</div>
                <div class="h5 mb-0 fw-bold"><?= $health['wa_ready'] ? 'Configured' : 'Needs Setup' ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm bg-dark text-white h-100">
            <div class="card-body">
                <div class="small opacity-75">Database Size</div>
                <div class="h5 mb-0 fw-bold"><?= e((string)$health['db_size_label']) ?></div>
                <div class="small opacity-75 mt-1"><?= $health['registration_enabled'] ? 'Registration Enabled' : 'Registration Disabled' ?></div>
            </div>
        </div>
    </div>
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
                <div class="fw-semibold mb-2">Global Notice Banner</div>
                <form method="post" action="index.php?page=admin_tools" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_global_notice">
                    <div class="col-12 col-lg-9">
                        <input class="form-control" name="global_notice" value="<?= e($globalNotice) ?>" placeholder="Example: Scheduled maintenance tonight 11:30 PM">
                        <div class="form-text">Shown at top of all logged-in pages. Leave empty to disable.</div>
                    </div>
                    <div class="col-12 col-lg-auto">
                        <button class="btn btn-outline-primary" type="submit">Save Notice</button>
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
