<?php

declare(strict_types=1);

if (auth_is_logged_in()) {
    header('Location: index.php?page=dashboard');
    exit;
}

csrf_verify();

if (!app_db_initialized()) {
    flash_set('danger', 'Database not initialized.');
    header('Location: index.php?page=login');
    exit;
}

$pdo = db();
$registrationEnabled = true;
$registrationCode = '';
try {
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = :key AND admin_id = 0 LIMIT 1');
    $stmt->execute([':key' => 'public_registration_enabled']);
    $v = $stmt->fetchColumn();
    $registrationEnabled = $v === false ? true : ((string)$v !== '0');

    $stmt->execute([':key' => 'public_registration_code']);
    $code = $stmt->fetchColumn();
    $registrationCode = $code === false ? '' : (string)$code;
} catch (Throwable $e) {
    $registrationEnabled = true;
    $registrationCode = '';
}

if (!$registrationEnabled) {
    flash_set('danger', 'Registration is disabled. Contact admin.');
    header('Location: index.php?page=login');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rl = app_rate_limit_allow(app_rate_limit_key('register'), 5, 600);
    if (!($rl['ok'] ?? true)) {
        flash_set('danger', 'Too many requests. Try again in ' . (int)($rl['retry_after'] ?? 0) . ' seconds.');
        header('Location: index.php?page=register');
        exit;
    }

    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $postedCode = trim((string)($_POST['registration_code'] ?? ''));

    if ($name === '' || $email === '' || $password === '') {
        flash_set('danger', 'All fields are required.');
        header('Location: index.php?page=register');
        exit;
    }

    if ($registrationCode !== '' && $postedCode !== $registrationCode) {
        flash_set('danger', 'Invalid registration code.');
        header('Location: index.php?page=register');
        exit;
    }
    
    // Check if email already exists
    $stmt = $pdo->prepare('SELECT id FROM admins WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    if ($stmt->fetch()) {
        flash_set('danger', 'Email already registered.');
        header('Location: index.php?page=register');
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    
    try {
        $stmt = $pdo->prepare("INSERT INTO admins (name, email, password_hash, role) VALUES (:name, :email, :hash, 'user')");
        $stmt->execute([':name' => $name, ':email' => $email, ':hash' => $hash]);
        $newId = (int)$pdo->lastInsertId();
        try {
            $pdo->prepare("INSERT OR IGNORE INTO settings (key, value, admin_id)
                SELECT key, value, :aid FROM settings
                WHERE admin_id = 1 AND key IN (
                    'company_name','company_logo','currency_symbol',
                    'whatsapp_base_url','whatsapp_api_key','whatsapp_ssl_verify','whatsapp_ca_bundle_path'
                )")->execute([':aid' => $newId]);
        } catch (Throwable $e) {
        }
        
        flash_set('success', 'Registration successful. You can now login.');
        header('Location: index.php?page=login');
        exit;
    } catch (Throwable $e) {
        flash_set('danger', 'Registration failed: ' . $e->getMessage());
        header('Location: index.php?page=register');
        exit;
    }
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';

?>
<div class="row justify-content-center">
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-3 fw-bold">Create Account</h1>
                <p class="text-muted small mb-4">Join us to manage your store independently.</p>
                
                <form method="post" action="index.php?page=register" autocomplete="off">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Full Name</label>
                        <input class="form-control" name="name" type="text" placeholder="John Doe" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Email Address</label>
                        <input class="form-control" name="email" type="email" placeholder="name@example.com" required>
                    </div>
                    <?php if ($registrationCode !== ''): ?>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Registration Code</label>
                            <input class="form-control" name="registration_code" required>
                        </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Password</label>
                        <input class="form-control" name="password" type="password" placeholder="Min. 6 characters" required minlength="6">
                    </div>
                    <button class="btn btn-primary w-100 py-2 fw-bold" type="submit">Register Now</button>
                </form>
                
                <div class="text-center mt-4">
                    <span class="text-muted small">Already have an account?</span>
                    <a href="index.php?page=login" class="small fw-bold text-decoration-none">Login</a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
