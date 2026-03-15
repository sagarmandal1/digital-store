<?php

declare(strict_types=1);

if (auth_is_logged_in()) {
    header('Location: index.php?page=dashboard');
    exit;
}

csrf_verify();

if (!isset($_SESSION['otp_pending_id'], $_SESSION['otp_pending_admin_id'])) {
    flash_set('danger', 'No pending OTP verification.');
    header('Location: index.php?page=login');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'verify');

    if ($action === 'resend') {
        $rl = app_rate_limit_allow(app_rate_limit_key('otp_resend', (string)($_SESSION['otp_pending_admin_id'] ?? '')), 3, 600);
        if (!($rl['ok'] ?? true)) {
            flash_set('danger', 'Too many resend attempts. Try again in ' . (int)($rl['retry_after'] ?? 0) . ' seconds.');
            header('Location: index.php?page=otp');
            exit;
        }
        $ok = auth_resend_pending_otp();
        if ($ok) {
            flash_set('success', 'OTP resent to your email.');
        } else {
            flash_set('danger', 'Could not resend OTP yet. Please wait a moment.');
        }
        header('Location: index.php?page=otp');
        exit;
    }

    $rl = app_rate_limit_allow(app_rate_limit_key('otp_verify', (string)($_SESSION['otp_pending_admin_id'] ?? '')), 10, 600);
    if (!($rl['ok'] ?? true)) {
        flash_set('danger', 'Too many OTP attempts. Try again in ' . (int)($rl['retry_after'] ?? 0) . ' seconds.');
        header('Location: index.php?page=otp');
        exit;
    }

    $otp = trim((string)($_POST['otp'] ?? ''));
    if ($otp === '') {
        flash_set('danger', 'OTP is required.');
        header('Location: index.php?page=otp');
        exit;
    }

    $ok = auth_verify_pending_otp($otp);
    if ($ok) {
        flash_set('success', 'Login verified.');
        header('Location: index.php?page=dashboard');
        exit;
    }

    flash_set('danger', 'Invalid or expired OTP.');
    header('Location: index.php?page=otp');
    exit;
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';

$pendingEmail = (string)($_SESSION['otp_pending_email'] ?? '');
$pendingName = (string)($_SESSION['otp_pending_name'] ?? '');
?>

<div class="row justify-content-center">
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-2">OTP Verification</h1>
                <div class="text-muted small mb-4">
                    Enter the 6-digit code sent to <?= e($pendingEmail !== '' ? $pendingEmail : $pendingName) ?>.
                </div>

                <form method="post" action="index.php?page=otp" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="verify">
                    <div class="mb-3">
                        <label class="form-label">OTP Code</label>
                        <input class="form-control form-control-lg text-center" name="otp" inputmode="numeric" pattern="[0-9]*" minlength="4" maxlength="10" required>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Verify</button>
                </form>

                <form method="post" action="index.php?page=otp" class="mt-3">
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
