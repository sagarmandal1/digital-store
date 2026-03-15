<?php

declare(strict_types=1);

function whatsapp_settings(): array
{
    $base = trim(app_setting('whatsapp_base_url', 'https://wafastapi.com'));
    if ($base === '') {
        $base = 'https://wafastapi.com';
    }
    $base = rtrim($base, '/');

    return [
        'base_url' => $base,
        'api_key' => (string)app_setting('whatsapp_api_key', ''),
        'ssl_verify' => (string)app_setting('whatsapp_ssl_verify', '1') !== '0',
        'ca_bundle_path' => (string)app_setting('whatsapp_ca_bundle_path', ''),
    ];
}

function whatsapp_settings_for_admin_id(int $adminId): array
{
    $base = trim(app_setting_for_admin_id($adminId, 'whatsapp_base_url', 'https://wafastapi.com'));
    if ($base === '') {
        $base = 'https://wafastapi.com';
    }
    $base = rtrim($base, '/');

    return [
        'base_url' => $base,
        'api_key' => (string)app_setting_for_admin_id($adminId, 'whatsapp_api_key', ''),
        'ssl_verify' => (string)app_setting_for_admin_id($adminId, 'whatsapp_ssl_verify', '1') !== '0',
        'ca_bundle_path' => (string)app_setting_for_admin_id($adminId, 'whatsapp_ca_bundle_path', ''),
    ];
}

function whatsapp_normalize_number(string $number): string
{
    $n = trim($number);
    $n = str_replace([' ', '-', '(', ')'], '', $n);
    return $n;
}

function whatsapp_post_json(string $url, array $headers, array $payload, array $options = []): array
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $sslVerify = (bool)($options['ssl_verify'] ?? true);
    $caBundlePath = trim((string)($options['ca_bundle_path'] ?? ''));
    $maxRetries = max(0, (int)($options['retries'] ?? 1));

    $attempt = 0;
    do {
        $attempt++;
        $ch = curl_init($url);

        $curlOptions = [
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
        ];
        if ($caBundlePath !== '') {
            $curlOptions[CURLOPT_CAINFO] = $caBundlePath;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPHEADER => array_merge($headers, ['Content-Type: application/json', 'Expect:']),
            CURLOPT_POSTFIELDS => $json === false ? '{}' : $json,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ] + $curlOptions);

        $body = curl_exec($ch);
        $err = curl_error($ch);
        $curlErrNo = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if ($body !== false) {
            break;
        }

        if ($attempt <= $maxRetries && in_array($curlErrNo, [52, 56], true)) {
            usleep(200000 * $attempt);
            continue;
        }
        break;
    } while (true);

    if ($body === false) {
        $message = $err !== '' ? $err : 'Request failed';
        if ($curlErrNo === 52 || $curlErrNo === 56 || stripos($message, 'Connection was reset') !== false) {
            $message .= ' (Connection was reset. This is usually a network/proxy or WhatsApp provider issue. Try again, or reduce PDF size.)';
        }
        if ($curlErrNo === 60 || stripos($message, 'certificate') !== false) {
            $message .= ' (SSL verify failed. Set WhatsApp CA Bundle Path in Settings, or disable SSL verify.)';
        }
        return ['ok' => false, 'http_code' => $code, 'error' => $message];
    }

    $decoded = json_decode($body, true);
    return [
        'ok' => $code >= 200 && $code < 300,
        'http_code' => $code,
        'raw' => $body,
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

function whatsapp_post_multipart(string $url, array $headers, array $payload, array $options = []): array
{
    $sslVerify = (bool)($options['ssl_verify'] ?? true);
    $caBundlePath = trim((string)($options['ca_bundle_path'] ?? ''));
    $maxRetries = max(0, (int)($options['retries'] ?? 1));

    $attempt = 0;
    do {
        $attempt++;
        $ch = curl_init($url);

        $curlOptions = [
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
        ];
        if ($caBundlePath !== '') {
            $curlOptions[CURLOPT_CAINFO] = $caBundlePath;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPHEADER => array_merge($headers, ['Expect:']),
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
        ] + $curlOptions);

        $body = curl_exec($ch);
        $err = curl_error($ch);
        $curlErrNo = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if ($body !== false) {
            break;
        }

        if ($attempt <= $maxRetries && in_array($curlErrNo, [52, 56], true)) {
            usleep(300000 * $attempt);
            continue;
        }
        break;
    } while (true);

    if ($body === false) {
        $message = $err !== '' ? $err : 'Request failed';
        if ($curlErrNo === 52 || $curlErrNo === 56 || stripos($message, 'Connection was reset') !== false) {
            $message .= ' (Connection was reset. This is usually a network/proxy or WhatsApp provider issue. Try again, or reduce PDF size.)';
        }
        if ($curlErrNo === 60 || stripos($message, 'certificate') !== false) {
            $message .= ' (SSL verify failed. Set WhatsApp CA Bundle Path in Settings, or disable SSL verify.)';
        }
        return ['ok' => false, 'http_code' => $code, 'error' => $message];
    }

    $decoded = json_decode($body, true);
    return [
        'ok' => $code >= 200 && $code < 300,
        'http_code' => $code,
        'raw' => $body,
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

function whatsapp_send_text(string $number, string $message): array
{
    $s = whatsapp_settings();
    if ($s['api_key'] === '') {
        return ['ok' => false, 'error' => 'WhatsApp API key is not configured.'];
    }

    $payload = [
        'number' => whatsapp_normalize_number($number),
        'message' => $message,
    ];

    $url = $s['base_url'] . '/api/whatsapp/send/text';
    return whatsapp_post_json($url, ['X-API-Key: ' . $s['api_key']], $payload, [
        'ssl_verify' => (bool)$s['ssl_verify'],
        'ca_bundle_path' => (string)$s['ca_bundle_path'],
    ]);
}

function whatsapp_send_text_for_admin_id(int $adminId, string $number, string $message): array
{
    $s = whatsapp_settings_for_admin_id($adminId);
    if ($s['api_key'] === '') {
        return ['ok' => false, 'error' => 'WhatsApp API key is not configured.'];
    }

    $payload = [
        'number' => whatsapp_normalize_number($number),
        'message' => $message,
    ];

    $url = $s['base_url'] . '/api/whatsapp/send/text';
    return whatsapp_post_json($url, ['X-API-Key: ' . $s['api_key']], $payload, [
        'ssl_verify' => (bool)$s['ssl_verify'],
        'ca_bundle_path' => (string)$s['ca_bundle_path'],
    ]);
}

function whatsapp_send_file(string $number, string $filePath, string $caption = ''): array
{
    $s = whatsapp_settings();
    if ($s['api_key'] === '') {
        return ['ok' => false, 'error' => 'WhatsApp API key is not configured.'];
    }

    if (!file_exists($filePath)) {
        return ['ok' => false, 'error' => 'File not found: ' . $filePath];
    }

    $size = @filesize($filePath);
    if (is_int($size) && $size > 10 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'PDF is too large to send (' . round($size / 1024 / 1024, 2) . ' MB). Reduce PDF size and try again.'];
    }

    // Determine filename and mimetype
    $filename = basename($filePath);
    $mimeType = 'application/pdf'; // Default to PDF for invoices

    $payload = [
        'number' => whatsapp_normalize_number($number),
        'files' => new CURLFile($filePath, $mimeType, $filename),
        'caption' => $caption,
    ];

    $url = $s['base_url'] . '/api/whatsapp/send/file';
    return whatsapp_post_multipart($url, ['X-API-Key: ' . $s['api_key']], $payload, [
        'ssl_verify' => (bool)$s['ssl_verify'],
        'ca_bundle_path' => (string)$s['ca_bundle_path'],
        'retries' => 2,
    ]);
}

function whatsapp_send_invoice(int $saleId): array
{
    $pdo = db();
    $stmt = $pdo->prepare('
        SELECT
            s.id, s.invoice_no, s.sale_date, s.total_sell,
            c.name AS customer_name, c.phone,
            COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.sale_id = s.id), 0) AS paid_amount
        FROM sales s
        JOIN customers c ON c.id = s.customer_id
        WHERE s.id = :id
        LIMIT 1
    ');
    $stmt->execute([':id' => $saleId]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['ok' => false, 'error' => 'Invoice not found.'];
    }

    $phone = (string)($row['phone'] ?? '');
    if (trim($phone) === '') {
        return ['ok' => false, 'error' => 'Customer phone is empty.'];
    }

    $invoiceNo = (string)($row['invoice_no'] ?? '');
    $saleDate = (string)($row['sale_date'] ?? '');
    $total = to_float($row['total_sell'] ?? 0);
    $paid = to_float($row['paid_amount'] ?? 0);
    $due = max(0.0, $total - $paid);
    $customerName = (string)($row['customer_name'] ?? '');

    $company = app_setting('company_name', (string)app_config('app_name', 'Company'));
    $currency = app_setting('currency_symbol', '');

    $baseUrl = trim((string)app_config('base_url', ''));
    $invoiceUrl = $baseUrl !== '' ? (rtrim($baseUrl, '/') . '/index.php?page=invoice&id=' . $saleId) : ('http://localhost:8000/?page=invoice&id=' . $saleId);

    $message =
        "Invoice: {$invoiceNo}\n" .
        "Customer: {$customerName}\n" .
        "Date: {$saleDate}\n" .
        "Total: " . money_fmt($total, $currency) . "\n" .
        "Paid: " . money_fmt($paid, $currency) . "\n" .
        "Due: " . money_fmt($due, $currency) . "\n" .
        "Invoice Link: {$invoiceUrl}\n" .
        "{$company}";

    return whatsapp_send_text($phone, $message);
}
