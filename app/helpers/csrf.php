<?php

declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function csrf_field(): string
{
    $token = csrf_token();
    return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_verify(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $posted = $_POST['_csrf_token'] ?? '';
    $session = $_SESSION['_csrf_token'] ?? '';
    if (!is_string($posted) || !is_string($session) || $posted === '' || !hash_equals($session, $posted)) {
        http_response_code(419);
        echo 'CSRF token mismatch.';
        exit;
    }
}

