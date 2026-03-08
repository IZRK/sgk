<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function sgk_load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);

        if ($value !== '' && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function sgk_turnstile_site_key(): string
{
    return trim((string) (getenv('TURNSTILE_SITE_KEY') ?: ''));
}

function sgk_turnstile_secret_key(): string
{
    return trim((string) (getenv('TURNSTILE_SECRET_KEY') ?: ''));
}

function sgk_turnstile_is_configured(): bool
{
    return sgk_turnstile_site_key() !== '' && sgk_turnstile_secret_key() !== '';
}

function sgk_turnstile_verify(?string $response, ?string $remoteIp = null): array
{
    if (!sgk_turnstile_is_configured()) {
        return [
            'success' => false,
            'error' => 'Turnstile ni konfiguriran v .env.',
            'codes' => ['not-configured'],
        ];
    }

    $response = trim((string) $response);
    if ($response === '') {
        return [
            'success' => false,
            'error' => 'Potrdite varnostno preverjanje.',
            'codes' => ['missing-input-response'],
        ];
    }

    $payload = http_build_query([
        'secret' => sgk_turnstile_secret_key(),
        'response' => $response,
        'remoteip' => trim((string) ($remoteIp ?? '')),
    ]);

    $result = null;

    if (function_exists('curl_init')) {
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $body = curl_exec($ch);
        if (is_string($body)) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $result = $decoded;
            }
        }
        curl_close($ch);
    }

    if ($result === null) {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $payload,
                'timeout' => 10,
            ],
        ]);
        $body = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $context);
        if (is_string($body)) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $result = $decoded;
            }
        }
    }

    if (!is_array($result)) {
        return [
            'success' => false,
            'error' => 'Varnostnega preverjanja ni bilo mogoče potrditi.',
            'codes' => ['verification-failed'],
        ];
    }

    $success = !empty($result['success']);
    $codes = array_values(array_map('strval', $result['error-codes'] ?? []));

    return [
        'success' => $success,
        'error' => $success ? '' : 'Varnostno preverjanje ni uspelo.',
        'codes' => $codes,
    ];
}

sgk_load_env(__DIR__ . '/../.env');

$autoloadFile = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoloadFile)) {
    require_once $autoloadFile;
}
