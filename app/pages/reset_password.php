<?php

declare(strict_types=1);

if (auth_is_logged_in()) {
    header('Location: index.php?page=dashboard');
    exit;
}

csrf_verify();

if (!isset($_SESSION['reset_pending_id'], $_SESSION['reset_pending_admin_id'])) {
    flash_set('danger', 'No pending password reset.');
    header('Location: index.php?page=forgot');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'verify');

    if ($action === 'resend') {
        $rl = app_rate_limit_allow(app_rate_limit_key('reset_resend', (string)($_SESSION['reset_pending_admin_id'] ?? '')), 3, 600);
        if (!($rl['ok'] ?? true)) {
            flash_set('danger', 'Too many resend attempts. Try again in ' . (int)($rl['retry_after'] ?? 0) . ' seconds.');
            header('Location: index.php?page=reset_password');
            exit;
        }
        $ok = auth_resend_pending_password_reset();
        if ($ok) {
            flash_set('success', 'OTP resent to your email.');
        } else {
            flash_set('danger', 'Could not resend OTP yet. Please wait a moment.');
        }
        header('Location: index.php?page=reset_password');
        exit;
    }

    $rl = app_rate_limit_allow(app_rate_limit_key('reset_verify', (string)($_SESSION['reset_pending_admin_id'] ?? '')), 10, 600);
    if (!($rl['ok'] ?? true)) {
        flash_set('danger', 'Too many attempts. Try again in ' . (int)($rl['retry_after'] ?? 0) . ' seconds.');
        header('Location: index.php?page=reset_password');
        exit;
    }

    $otp = trim((string)($_POST['otp'] ?? ''));
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if ($otp === '' || $new === '' || $confirm === '') {
        flash_set('danger', 'All fields are required.');
        header('Location: index.php?page=reset_password');
        exit;
    }
    if ($new !== $confirm) {
        flash_set('danger', 'Passwords do not match.');
        header('Location: index.php?page=reset_password');
        exit;
    }
    if (strlen($new) < 6) {
        flash_set('danger', 'Password must be at least 6 characters.');
        header('Location: index.php?page=reset_password');
        exit;
    }

    $ok = auth_verify_pending_password_reset($otp, $new);
    if ($ok) {
        flash_set('success', 'Password reset successfully. Please login.');
        header('Location: index.php?page=login');
        exit;
    }

    flash_set('danger', 'Invalid or expired OTP.');
    header('Location: index.php?page=reset_password');
    exit;
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
$pendingEmail = (string)($_SESSION['reset_pending_email'] ?? '');
?>

<div class="row justify-content-center">
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-2">Reset Password</h1>
                <div class="text-muted small mb-4">Enter the OTP sent to <?= e($pendingEmail !== '' ? $pendingEmail : 'your email') ?>.</div>

                <form method="post" action="index.php?page=reset_password" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="verify">
                    <div class="mb-3">
                        <label class="form-label">OTP Code</label>
                        <input class="form-control text-center" name="otp" inputmode="numeric" pattern="[0-9]*" minlength="4" maxlength="10" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input class="form-control" name="new_password" type="password" required minlength="6">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <input class="form-control" name="confirm_password" type="password" required minlength="6">
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Reset Password</button>
                </form>

                <form method="post" action="index.php?page=reset_password" class="mt-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="resend">
                    <button class="btn btn-outline-secondary w-100" type="submit">Resend OTP</button>
                </form>

                <div class="text-center mt-3">
                    <a href="index.php?page=login" class="small text-decoration-none">Back to login</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
