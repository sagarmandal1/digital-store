<?php

declare(strict_types=1);

function app_send_mail(string $to, string $subject, string $html): bool
{
    $host = smtp_setting_for_admin(0, 'smtp_host', 'smtp.gmail.com');
    $port = (int)smtp_setting_for_admin(0, 'smtp_port', '587');
    $user = smtp_setting_for_admin(0, 'smtp_user', '');
    $pass = smtp_setting_for_admin(0, 'smtp_pass', '');
    $encryption = strtolower(smtp_setting_for_admin(0, 'smtp_encryption', 'tls'));
    $fromName = app_setting('company_name', 'Admin');

    if ($user === '' || $pass === '') {
        return false;
    }

    return smtp_send_mail($host, $port, $encryption, $user, $pass, $user, $fromName, $to, $subject, $html);
}

function app_send_mail_for_admin(int $adminId, string $to, string $subject, string $html): bool
{
    $host = smtp_setting_for_admin(0, 'smtp_host', 'smtp.gmail.com');
    $port = (int)smtp_setting_for_admin(0, 'smtp_port', '587');
    $user = smtp_setting_for_admin(0, 'smtp_user', '');
    $pass = smtp_setting_for_admin(0, 'smtp_pass', '');
    $encryption = strtolower(smtp_setting_for_admin(0, 'smtp_encryption', 'tls'));
    $fromName = smtp_setting_for_admin($adminId, 'company_name', 'Admin');

    if ($user === '' || $pass === '') {
        return false;
    }

    return smtp_send_mail($host, $port, $encryption, $user, $pass, $user, $fromName, $to, $subject, $html);
}

function smtp_setting_for_admin(int $adminId, string $key, string $default = ''): string
{
    try {
        $stmt = db()->prepare('SELECT value FROM settings WHERE key = :key AND admin_id = :aid LIMIT 1');
        $stmt->execute([':key' => $key, ':aid' => $adminId]);
        $value = $stmt->fetchColumn();
        if ($value !== false) {
            return (string)$value;
        }
        if ($adminId !== 0) {
            $stmt->execute([':key' => $key, ':aid' => 0]);
            $value = $stmt->fetchColumn();
            if ($value !== false) {
                return (string)$value;
            }
        }
    } catch (Throwable $e) {
        return $default;
    }
    return $default;
}

function smtp_send_mail(
    string $host,
    int $port,
    string $encryption,
    string $username,
    string $password,
    string $fromEmail,
    string $fromName,
    string $toEmail,
    string $subject,
    string $html
): bool {
    $remote = ($encryption === 'ssl') ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ],
    ]);

    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
    if (!is_resource($fp)) {
        return false;
    }

    stream_set_timeout($fp, 20);

    $code = smtp_expect($fp, [220]);
    if ($code === null) {
        fclose($fp);
        return false;
    }

    $localName = 'localhost';
    if (!smtp_cmd($fp, "EHLO {$localName}", [250])) {
        fclose($fp);
        return false;
    }

    if ($encryption === 'tls') {
        if (!smtp_cmd($fp, "STARTTLS", [220])) {
            fclose($fp);
            return false;
        }
        $cryptoOk = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($cryptoOk !== true) {
            fclose($fp);
            return false;
        }
        if (!smtp_cmd($fp, "EHLO {$localName}", [250])) {
            fclose($fp);
            return false;
        }
    }

    if (!smtp_cmd($fp, "AUTH LOGIN", [334])) {
        fclose($fp);
        return false;
    }
    if (!smtp_cmd($fp, base64_encode($username), [334])) {
        fclose($fp);
        return false;
    }
    if (!smtp_cmd($fp, base64_encode($password), [235])) {
        fclose($fp);
        return false;
    }

    if (!smtp_cmd($fp, "MAIL FROM:<{$fromEmail}>", [250])) {
        fclose($fp);
        return false;
    }
    if (!smtp_cmd($fp, "RCPT TO:<{$toEmail}>", [250, 251])) {
        fclose($fp);
        return false;
    }
    if (!smtp_cmd($fp, "DATA", [354])) {
        fclose($fp);
        return false;
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $fromHeader = $fromName !== '' ? ('"' . str_replace('"', '', $fromName) . '" <' . $fromEmail . '>') : $fromEmail;

    $headers = [
        "From: {$fromHeader}",
        "To: <{$toEmail}>",
        "Subject: {$encodedSubject}",
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8",
        "Content-Transfer-Encoding: 8bit",
    ];

    $message = implode("\r\n", $headers) . "\r\n\r\n" . $html . "\r\n";
    $message = str_replace(["\r\n.\r\n", "\n.\n"], ["\r\n..\r\n", "\n..\n"], $message);

    fwrite($fp, $message . "\r\n.\r\n");

    $dataCode = smtp_expect($fp, [250]);
    smtp_cmd($fp, "QUIT", [221, 250]);
    fclose($fp);

    return $dataCode === 250;
}

function smtp_cmd($fp, string $command, array $expectedCodes): bool
{
    fwrite($fp, $command . "\r\n");
    $code = smtp_expect($fp, $expectedCodes);
    return $code !== null;
}

function smtp_expect($fp, array $expectedCodes): ?int
{
    $lastLine = '';
    while (!feof($fp)) {
        $line = fgets($fp, 2048);
        if ($line === false) {
            return null;
        }
        $lastLine = rtrim($line, "\r\n");
        if (strlen($lastLine) < 4) {
            continue;
        }
        if ($lastLine[3] !== '-') {
            break;
        }
    }

    if (strlen($lastLine) < 3) {
        return null;
    }
    $code = (int)substr($lastLine, 0, 3);
    return in_array($code, $expectedCodes, true) ? $code : null;
}
