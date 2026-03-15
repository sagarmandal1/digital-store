<?php

declare(strict_types=1);

if (auth_is_logged_in()) {
    header('Location: index.php?page=dashboard');
    exit;
}

csrf_verify();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rl = app_rate_limit_allow(app_rate_limit_key('login'), 15, 600);
    if (!($rl['ok'] ?? true)) {
        flash_set('danger', 'Too many login attempts. Try again in ' . (int)($rl['retry_after'] ?? 0) . ' seconds.');
        header('Location: index.php?page=login');
        exit;
    }

    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        flash_set('danger', 'Email and password are required.');
        header('Location: index.php?page=login');
        exit;
    }

    try {
        if (auth_login($email, $password)) {
            flash_set('success', 'Logged in successfully.');
            header('Location: index.php?page=dashboard');
            exit;
        }

        if (isset($_SESSION['otp_pending_id'])) {
            flash_set('success', 'OTP sent to your email. Please verify to continue.');
            header('Location: index.php?page=otp');
            exit;
        }

        if (isset($_SESSION['otp_error'])) {
            $msg = (string)$_SESSION['otp_error'];
            unset($_SESSION['otp_error']);
            flash_set('danger', $msg);
            header('Location: index.php?page=login');
            exit;
        }

        if (isset($_SESSION['auth_error'])) {
            $msg = (string)$_SESSION['auth_error'];
            unset($_SESSION['auth_error']);
            flash_set('danger', $msg);
            header('Location: index.php?page=login');
            exit;
        }
    } catch (Throwable $e) {
        flash_set('danger', 'Login failed. Make sure the database is initialized.');
        header('Location: index.php?page=login');
        exit;
    }

    flash_set('danger', 'Invalid credentials.');
    header('Location: index.php?page=login');
    exit;
}

$registrationEnabled = false;
try {
    if (app_db_initialized()) {
        $stmt = db()->prepare('SELECT value FROM settings WHERE key = :key AND admin_id = 0 LIMIT 1');
        $stmt->execute([':key' => 'public_registration_enabled']);
        $v = $stmt->fetchColumn();
        $registrationEnabled = $v === false ? true : ((string)$v !== '0');
    }
} catch (Throwable $e) {
    $registrationEnabled = true;
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';

?>
<div class="row justify-content-center">
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h4 mb-3">Login</h1>
                <form method="post" action="index.php?page=login" autocomplete="off">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input class="form-control" name="email" type="email" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input class="form-control" name="password" type="password" required>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Login</button>
                </form>
                <div class="text-center mt-3">
                    <a href="index.php?page=forgot" class="small text-decoration-none">Forgot password?</a>
                </div>
                <div class="text-center mt-4">
                    <span class="text-muted small">Don't have an account?</span>
                    <?php if ($registrationEnabled): ?>
                        <a href="index.php?page=register" class="small fw-bold text-decoration-none">Register</a>
                    <?php else: ?>
                        <span class="small fw-bold">Contact admin</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
