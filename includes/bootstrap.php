<?php
if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

function sgk_load_env($path) {
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

		if ($value !== '' &&
			(($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
			$value = substr($value, 1, -1);
		}

		putenv($key . '=' . $value);
		$_ENV[$key] = $value;
		$_SERVER[$key] = $value;
	}
}

function e($value) {
	return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function sgk_registration_schedule($today = null) {
	if ($today instanceof DateTimeInterface) {
		$today = DateTimeImmutable::createFromInterface($today)
			->setTimezone(new DateTimeZone('Europe/Ljubljana'))
			->format('Y-m-d');
	} elseif ($today === null) {
		$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Ljubljana')))->format('Y-m-d');
	} else {
		$today = substr((string)$today, 0, 10);
	}

	$schedule = [
		'today'                   => $today,
		'registration_submission' => [
			'start' => '2026-03-16',
			'end'   => '2026-07-20'
		],
		'registration_early'      => [
			'start' => '2026-03-16',
			'end'   => '2026-05-29'
		],
		'registration_late'       => [
			'start' => '2026-05-30',
			'end'   => '2026-07-20'
		],
		'abstract_submission'     => [
			'start' => '2026-03-16',
			'end'   => '2026-07-20'
		],
		'photo_contest'           => [
			'start' => '2026-03-16',
			'end'   => '2026-09-01'
		],
	];

	if ($today >= '2026-05-29') {
		$schedule['registration_early']['end'] = '2026-06-05';
		$schedule['registration_late']['start'] = '2026-06-06';
	}

	return $schedule;
}

function sgk_format_short_date($date) {
	$timestamp = strtotime($date . ' 00:00:00');

	if ($timestamp === false) {
		return $date;
	}

	return date('j. n.', $timestamp);
}

function sgk_format_short_date_range($start, $end) {
	return sgk_format_short_date($start) . '-' . sgk_format_short_date($end);
}

function sgk_asset_url($path) {
	$path = (string)$path;
	$absolutePath = __DIR__ . '/../' . ltrim(strtok($path, '?'), '/');

	if (!is_file($absolutePath)) {
		return $path;
	}

	$separator = str_contains($path, '?') ? '&' : '?';
	return $path . $separator . 'v=' . filemtime($absolutePath);
}

function sgk_csv_multiline_columns() {
	return [
		'authors',
		'institutions',
		'abstract_text',
		'notes',
	];
}

function sgk_csv_encode_value($column, $value) {
	$value = (string)($value ?? '');

	if (!in_array($column, sgk_csv_multiline_columns(), true)) {
		return $value;
	}

	return str_replace([
		"\r\n",
		"\r",
		"\n"
	], '\n', $value);
}

function sgk_csv_decode_value($column, $value) {
	$value = (string)($value ?? '');

	if (!in_array($column, sgk_csv_multiline_columns(), true)) {
		return $value;
	}

	return str_replace('\n', "\n", $value);
}

function sgk_turnstile_site_key() {
	return trim((string)(getenv('TURNSTILE_SITE_KEY') ?: ''));
}

function sgk_turnstile_secret_key() {
	return trim((string)(getenv('TURNSTILE_SECRET_KEY') ?: ''));
}

function sgk_turnstile_is_configured() {
	return sgk_turnstile_site_key() !== '' && sgk_turnstile_secret_key() !== '';
}

function sgk_turnstile_verify($response, $remoteIp = null) {
	if (!sgk_turnstile_is_configured()) {
		return [
			'success' => false,
			'error'   => 'Turnstile ni konfiguriran v .env.',
			'codes'   => ['not-configured'],
		];
	}

	$response = trim((string)$response);
	if ($response === '') {
		return [
			'success' => false,
			'error'   => 'Potrdite varnostno preverjanje.',
			'codes'   => ['missing-input-response'],
		];
	}

	$payload = http_build_query([
		'secret'   => sgk_turnstile_secret_key(),
		'response' => $response,
		'remoteip' => trim((string)($remoteIp ?? '')),
	]);

	$result = null;

	if (function_exists('curl_init')) {
		$ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
		curl_setopt_array($ch, [
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $payload,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 10,
			CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
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
				'method'  => 'POST',
				'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
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
			'error'   => 'Varnostnega preverjanja ni bilo mogoče potrditi.',
			'codes'   => ['verification-failed'],
		];
	}

	$success = !empty($result['success']);
	$codes = array_values(array_map('strval', $result['error-codes'] ?? []));

	return [
		'success' => $success,
		'error'   => $success ? '': 'Varnostno preverjanje ni uspelo.',
		'codes'   => $codes,
	];
}

sgk_load_env(__DIR__ . '/../.env');

$autoloadFile = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoloadFile)) {
	require_once $autoloadFile;
}
