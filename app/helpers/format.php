<?php

declare(strict_types=1);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function to_float($value): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }
    return (float)$value;
}

function money_fmt(float $amount, string $currency = ''): string
{
    $formatted = number_format($amount, 2, '.', ',');
    return $currency === '' ? $formatted : $currency . ' ' . $formatted;
}

function today_iso(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d');
}
