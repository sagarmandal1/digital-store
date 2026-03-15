<?php

declare(strict_types=1);

if (auth_is_logged_in()) {
    header('Location: index.php?page=dashboard');
    exit;
}

csrf_verify();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rl = app_rate_limit_allow(app_rate_limit_key('forgot'), 5, 600);
    if (!($rl['ok'] ?? true)) {
        flash_set('danger', 'Too many requests. Try again in ' . (int)($rl['retry_after'] ?? 0) . ' seconds.');
        header('Location: index.php?page=forgot');
        exit;
    }
    $email = trim((string)($_POST['email'] ?? ''));
    if ($email === '') {
        flash_set('danger', 'Email is required.');
        header('Location: index.php?page=forgot');
        exit;
    }

    if (!app_db_initialized()) {
        flash_set('danger', 'Database not initialized.');
        header('Location: index.php?page=login');
        exit;
    }

    $stmt = db()->prepare('SELECT id FROM admins WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $adminId = (int)($stmt->fetchColumn() ?: 0);

    if ($adminId > 0) {
        $resetId = auth_create_password_reset($adminId);
        if ($resetId > 0) {
            $_SESSION['reset_pending_id'] = $resetId;
            $_SESSION['reset_pending_admin_id'] = $adminId;
            $_SESSION['reset_pending_email'] = $email;
            flash_set('success', 'OTP sent to your email. Please verify to reset password.');
            header('Location: index.php?page=reset_password');
            exit;
        }
    }

    flash_set('success', 'If the email exists, a reset OTP has been sent.');
    header('Location: index.php?page=login');
    exit;
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-2">Forgot Password</h1>
                <div class="text-muted small mb-4">Enter your email to receive an OTP.</div>

                <form method="post" action="index.php?page=forgot" autocomplete="off">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input class="form-control" name="email" type="email" required>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Send OTP</button>
                </form>

                <div class="text-center mt-3">
                    <a href="index.php?page=login" class="small text-decoration-none">Back to login</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
