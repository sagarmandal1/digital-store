<?php

declare(strict_types=1);

auth_require_login();

csrf_verify();

if (!app_db_initialized()) {
    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
    ?>
    <h1 class="h3">Settings</h1>
    <div class="alert alert-warning">Initialize the database first.</div>
    <?php
    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php';
    exit;
}

$pdo = db();
$adminId = (int)($_SESSION['admin_id'] ?? 0);
$isSuperAdmin = app_is_super_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = trim((string)($_POST['action'] ?? ''));
    if ($postAction === 'regenerate_ext_token') {
        $token = bin2hex(random_bytes(24));
        $hash = password_hash($token, PASSWORD_DEFAULT);
        $last4 = substr($token, -4);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO settings (key, value, admin_id) VALUES (:key, :value, :aid) ON CONFLICT(key, admin_id) DO UPDATE SET value=excluded.value");
            $stmt->execute([':key' => 'ext_api_token_hash', ':value' => $hash, ':aid' => $adminId]);
            $stmt->execute([':key' => 'ext_api_token_last4', ':value' => $last4, ':aid' => $adminId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $_SESSION['ext_api_token_plain'] = $token;
        flash_set('success', 'Extension token generated. Copy it now.');
        header('Location: index.php?page=settings');
        exit;
    }

    $companyName = trim((string)($_POST['company_name'] ?? ''));
    $currency = trim((string)($_POST['currency_symbol'] ?? ''));
    $waBaseUrl = trim((string)($_POST['whatsapp_base_url'] ?? ''));
    $waApiKey = trim((string)($_POST['whatsapp_api_key'] ?? ''));
    $waSslVerify = (string)($_POST['whatsapp_ssl_verify'] ?? '1') !== '0';
    $waCaBundlePath = trim((string)($_POST['whatsapp_ca_bundle_path'] ?? ''));

    $smtpHost = '';
    $smtpPort = 587;
    $smtpUser = '';
    $smtpPass = '';
    $smtpEncryption = 'tls';
    if ($isSuperAdmin) {
        $smtpHost = trim((string)($_POST['smtp_host'] ?? ''));
        $smtpPort = (int)($_POST['smtp_port'] ?? 587);
        $smtpUser = trim((string)($_POST['smtp_user'] ?? ''));
        $smtpPass = (string)($_POST['smtp_pass'] ?? '');
        $smtpEncryption = trim((string)($_POST['smtp_encryption'] ?? 'tls'));
        if ($smtpPass === '') {
            $smtpPass = smtp_setting_for_admin(0, 'smtp_pass', '');
        }
    }

    // Handle Logo Upload
    $companyLogo = app_setting('company_logo', '');
    if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($ext, $allowed)) {
            $uploadDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $newFileName = 'logo_' . time() . '.' . $ext;
            $dest = $uploadDir . DIRECTORY_SEPARATOR . $newFileName;
            if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $dest)) {
                // Remove old logo if it exists
                if ($companyLogo !== '' && str_starts_with($companyLogo, 'uploads/')) {
                    $oldPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $companyLogo);
                    if (is_file($oldPath)) unlink($oldPath);
                }
                $companyLogo = 'uploads/' . $newFileName;
            }
        }
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO settings (key, value, admin_id) VALUES (:key, :value, :aid) ON CONFLICT(key, admin_id) DO UPDATE SET value=excluded.value");
        $stmt->execute([':key' => 'company_name', ':value' => $companyName === '' ? 'My Digital Store' : $companyName, ':aid' => $adminId]);
        $stmt->execute([':key' => 'company_logo', ':value' => $companyLogo, ':aid' => $adminId]);
        $stmt->execute([':key' => 'currency_symbol', ':value' => $currency, ':aid' => $adminId]);
        $stmt->execute([':key' => 'whatsapp_base_url', ':value' => $waBaseUrl === '' ? 'https://wafastapi.com' : $waBaseUrl, ':aid' => $adminId]);
        $stmt->execute([':key' => 'whatsapp_api_key', ':value' => $waApiKey, ':aid' => $adminId]);
        $stmt->execute([':key' => 'whatsapp_ssl_verify', ':value' => $waSslVerify ? '1' : '0', ':aid' => $adminId]);
        $stmt->execute([':key' => 'whatsapp_ca_bundle_path', ':value' => $waCaBundlePath, ':aid' => $adminId]);

        if ($isSuperAdmin) {
            $pdo->prepare("DELETE FROM settings WHERE admin_id <> 0 AND key IN ('smtp_host','smtp_port','smtp_user','smtp_pass','smtp_encryption')")->execute();
            $stmt->execute([':key' => 'smtp_host', ':value' => $smtpHost, ':aid' => 0]);
            $stmt->execute([':key' => 'smtp_port', ':value' => (string)$smtpPort, ':aid' => 0]);
            $stmt->execute([':key' => 'smtp_user', ':value' => $smtpUser, ':aid' => 0]);
            $stmt->execute([':key' => 'smtp_pass', ':value' => $smtpPass, ':aid' => 0]);
            $stmt->execute([':key' => 'smtp_encryption', ':value' => $smtpEncryption, ':aid' => 0]);
        }
        
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    app_audit_log('update', 'settings', 0, ['smtp_changed' => $isSuperAdmin]);
    flash_set('success', 'Settings saved.');
    header('Location: index.php?page=settings');
    exit;
}

$companyName = app_setting('company_name', 'My Digital Store');
$companyLogo = app_setting('company_logo', '');
$currency = app_setting('currency_symbol', '');
$waBaseUrl = app_setting('whatsapp_base_url', 'https://wafastapi.com');
$waApiKey = app_setting('whatsapp_api_key', '');
$waSslVerify = app_setting('whatsapp_ssl_verify', '1') !== '0';
$waCaBundlePath = app_setting('whatsapp_ca_bundle_path', '');
$extTokenLast4 = app_setting('ext_api_token_last4', '');
$extTokenPlain = (string)($_SESSION['ext_api_token_plain'] ?? '');
unset($_SESSION['ext_api_token_plain']);

$smtpHost = $isSuperAdmin ? smtp_setting_for_admin(0, 'smtp_host', 'smtp.gmail.com') : '';
$smtpPort = $isSuperAdmin ? (int)smtp_setting_for_admin(0, 'smtp_port', '587') : 587;
$smtpUser = $isSuperAdmin ? smtp_setting_for_admin(0, 'smtp_user', '') : '';
$smtpEncryption = $isSuperAdmin ? smtp_setting_for_admin(0, 'smtp_encryption', 'tls') : 'tls';

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="h3 mb-3">Settings</h1>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="post" action="index.php?page=settings" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label">Company Name</label>
                    <input class="form-control" name="company_name" value="<?= e($companyName) ?>">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">Company Logo</label>
                    <div class="d-flex align-items-center gap-3">
                        <?php if ($companyLogo !== ''): ?>
                            <div class="border rounded p-1 bg-light">
                                <img src="<?= e($companyLogo) ?>" alt="Logo" style="height: 40px; width: auto;">
                            </div>
                        <?php endif; ?>
                        <input class="form-control" type="file" name="logo_file" accept="image/*">
                    </div>
                    <div class="form-text">JPG, PNG, GIF, WebP are allowed. Recommended size: 200x60px.</div>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">Currency Symbol</label>
                    <input class="form-control" name="currency_symbol" value="<?= e($currency) ?>" placeholder="$">
                </div>
                <div class="col-12">
                    <hr>
                    <div class="fw-semibold mb-2">WhatsApp API</div>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">Base URL</label>
                    <input class="form-control" name="whatsapp_base_url" value="<?= e($waBaseUrl) ?>" placeholder="https://wafastapi.com">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">X-API-Key (API Key)</label>
                    <input class="form-control" name="whatsapp_api_key" value="<?= e($waApiKey) ?>" placeholder="your-api-key-here">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">SSL Verify</label>
                    <select class="form-select" name="whatsapp_ssl_verify">
                        <option value="1" <?= $waSslVerify ? 'selected' : '' ?>>Enabled (recommended)</option>
                        <option value="0" <?= !$waSslVerify ? 'selected' : '' ?>>Disabled</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">CA Bundle Path (optional)</label>
                    <input class="form-control" name="whatsapp_ca_bundle_path" value="<?= e($waCaBundlePath) ?>" placeholder="C:\path\to\cacert.pem">
                </div>

                <div class="col-12">
                    <hr>
                    <div class="fw-semibold mb-2">WhatsApp Web Extension</div>
                </div>
                <div class="col-12 col-lg-8">
                    <div class="alert alert-secondary mb-0">
                        <div class="fw-semibold">API Token</div>
                        <div class="small">Use this token in the Chrome extension (it works even if third-party cookies are blocked).</div>
                        <div class="small mt-1">Current token: <?= $extTokenLast4 !== '' ? '••••' . e($extTokenLast4) : 'Not generated' ?></div>
                        <?php if ($extTokenPlain !== ''): ?>
                            <div class="small mt-2">New token (copy now): <span class="fw-semibold"><?= e($extTokenPlain) ?></span></div>
                        <?php endif; ?>
                        <div class="mt-2">
                            <button class="btn btn-sm btn-outline-primary" type="submit" name="action" value="regenerate_ext_token">Generate New Token</button>
                        </div>
                    </div>
                </div>
                
                <div class="col-12">
                    <hr>
                    <div class="fw-semibold mb-2">SMTP Settings (for OTP & Email)</div>
                </div>
                <?php if ($isSuperAdmin): ?>
                    <div class="col-12">
                        <div class="alert alert-info mb-0">
                            This SMTP is used globally for sending OTP and emails to all users.
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label">SMTP Host</label>
                        <input class="form-control" name="smtp_host" value="<?= e($smtpHost) ?>" placeholder="smtp.gmail.com">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">SMTP Port</label>
                        <input class="form-control" name="smtp_port" type="number" value="<?= $smtpPort ?>" placeholder="587">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Encryption</label>
                        <select class="form-select" name="smtp_encryption">
                            <option value="tls" <?= $smtpEncryption === 'tls' ? 'selected' : '' ?>>TLS</option>
                            <option value="ssl" <?= $smtpEncryption === 'ssl' ? 'selected' : '' ?>>SSL</option>
                            <option value="none" <?= $smtpEncryption === 'none' ? 'selected' : '' ?>>None</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label">SMTP User (Email)</label>
                        <input class="form-control" name="smtp_user" value="<?= e($smtpUser) ?>" placeholder="example@gmail.com">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label">SMTP Password / App Password</label>
                        <input class="form-control" name="smtp_pass" type="password" value="" placeholder="your-app-password">
                        <div class="form-text">For Gmail, use an <a href="https://myaccount.google.com/apppasswords" target="_blank">App Password</a>.</div>
                    </div>
                <?php else: ?>
                    <div class="col-12">
                        <div class="alert alert-secondary mb-0">
                            SMTP settings are managed by the admin. You cannot change SMTP from this account.
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2 mt-4">
                <button class="btn btn-success px-4" type="submit">Save Settings</button>
            </div>
        </form>
    </div>
</div>

<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
