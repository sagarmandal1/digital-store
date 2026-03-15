<?php

declare(strict_types=1);

auth_require_login();
csrf_verify();

$pdo = db();
$adminId = (int)($_SESSION['admin_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    
    if ($action === 'update_profile') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        
        if ($name === '' || $email === '') {
            flash_set('danger', 'Name and Email are required.');
        } else {
            try {
                $stmt = $pdo->prepare('UPDATE admins SET name = :name, email = :email WHERE id = :id');
                $stmt->execute([':name' => $name, ':email' => $email, ':id' => $adminId]);
                $_SESSION['admin_name'] = $name;
                $_SESSION['admin_email'] = $email;
                flash_set('success', 'Profile updated.');
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    flash_set('danger', 'This email is already in use.');
                } else {
                    flash_set('danger', 'Profile update failed.');
                }
            }
        }
    } elseif ($action === 'change_password') {
        $old = (string)($_POST['old_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        
        $stmt = $pdo->prepare('SELECT password_hash FROM admins WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $adminId]);
        $hash = (string)$stmt->fetchColumn();
        
        if (!password_verify($old, $hash)) {
            flash_set('danger', 'Current password is incorrect.');
        } elseif ($new !== $confirm) {
            flash_set('danger', 'New passwords do not match.');
        } elseif (strlen($new) < 6) {
            flash_set('danger', 'New password must be at least 6 characters.');
        } else {
            $newHash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('UPDATE admins SET password_hash = :hash WHERE id = :id');
            $stmt->execute([':hash' => $newHash, ':id' => $adminId]);
            flash_set('success', 'Password changed successfully.');
        }
    } elseif ($action === 'update_2fa') {
        $enabled = isset($_POST['enable_2fa']) ? '1' : '0';
        try {
            $stmt = $pdo->prepare("INSERT INTO settings (key, value, admin_id) VALUES (:key, :value, :aid) ON CONFLICT(key, admin_id) DO UPDATE SET value=excluded.value");
            $stmt->execute([':key' => 'two_factor_email_enabled', ':value' => $enabled, ':aid' => $adminId]);
            flash_set('success', $enabled === '1' ? '2FA enabled.' : '2FA disabled.');
        } catch (Throwable $e) {
            try {
                $stmt = $pdo->prepare('UPDATE settings SET value = :value WHERE key = :key AND admin_id = :aid');
                $stmt->execute([':key' => 'two_factor_email_enabled', ':value' => $enabled, ':aid' => $adminId]);
                flash_set('success', $enabled === '1' ? '2FA enabled.' : '2FA disabled.');
            } catch (Throwable $e2) {
                flash_set('danger', '2FA update failed.');
            }
        }
    }
    
    header('Location: index.php?page=profile');
    exit;
}

$stmt = $pdo->prepare('SELECT name, email FROM admins WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $adminId]);
$admin = $stmt->fetch();
$isPrimaryAdmin = $adminId === 1;
$twoFactorEnabled = $isPrimaryAdmin ? false : true;
$smtpConfigured = app_setting('smtp_user', '') !== '' && app_setting('smtp_pass', '') !== '';

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="row g-4">
    <div class="col-12 col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-4">
                <h4 class="fw-bold mb-4">Edit Profile</h4>
                <form method="post" action="index.php?page=profile">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_profile">
                    <div class="mb-3">
                        <label class="form-label small text-uppercase fw-bold">Full Name</label>
                        <input type="text" class="form-control" name="name" value="<?= e((string)$admin['name']) ?>" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label small text-uppercase fw-bold">Email Address</label>
                        <input type="email" class="form-control" name="email" value="<?= e((string)$admin['email']) ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary px-4 py-2 fw-bold">Update Profile</button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-12 col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-4">
                <h4 class="fw-bold mb-4">Change Password</h4>
                <form method="post" action="index.php?page=profile">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_password">
                    <div class="mb-3">
                        <label class="form-label small text-uppercase fw-bold">Current Password</label>
                        <input type="password" class="form-control" name="old_password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-uppercase fw-bold">New Password</label>
                        <input type="password" class="form-control" name="new_password" required minlength="6">
                    </div>
                    <div class="mb-4">
                        <label class="form-label small text-uppercase fw-bold">Confirm New Password</label>
                        <input type="password" class="form-control" name="confirm_password" required minlength="6">
                    </div>
                    <button type="submit" class="btn btn-outline-danger px-4 py-2 fw-bold">Change Password</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card shadow-sm border-0 bg-light-subtle">
            <div class="card-body p-4">
                <div class="d-flex align-items-center">
                    <div class="bg-primary-subtle text-primary p-3 rounded-circle me-3">
                        <i class="bi bi-shield-check fs-3"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h5 class="fw-bold mb-1">Two-Factor Authentication (2FA)</h5>
                        <p class="text-muted small mb-0">
                            Status:
                            <span class="fw-semibold">
                                <?= $isPrimaryAdmin ? 'Disabled (Admin)' : 'Required (All Users)' ?>
                            </span>
                            <?php if (!$smtpConfigured): ?>
                                <span class="ms-2">(Admin must configure SMTP in Settings)</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <div class="mt-3">
                    <form method="post" action="index.php?page=profile" class="d-flex align-items-center justify-content-between">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_2fa">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" role="switch" id="enable2fa" name="enable_2fa" <?= $isPrimaryAdmin ? '' : 'checked' ?> disabled>
                            <label class="form-check-label" for="enable2fa">Require OTP on login</label>
                        </div>
                        <button class="btn btn-primary" type="submit" disabled>Save</button>
                    </form>
                    <?php if (!$smtpConfigured): ?>
                        <div class="small mt-2">
                            <a href="index.php?page=settings" class="text-decoration-none">Go to Settings → SMTP</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
