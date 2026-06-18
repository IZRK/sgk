<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/mail.php';

function usage() {
	echo "Usage: php scripts/send-edit-submission-links.php [--send] [--csv=/path/submissions.csv] [--only=email] [--limit=N]\n";
	echo "Defaults to dry-run. Use --send to send real e-mails.\n";
}

function option_value($name, $default = null) {
	global $argv;
	$prefix = '--' . $name . '=';
	foreach ($argv as $arg) {
		if (str_starts_with($arg, $prefix)) {
			return substr($arg, strlen($prefix));
		}
	}

	return $default;
}

function has_flag($name) {
	global $argv;
	return in_array('--' . $name, $argv, true);
}

function sgk_edit_salt() {
	return trim((string)(getenv('SGK_EDIT_SALT') ?: getenv('SGK_ADMIN_SALT') ?: ''));
}

function sgk_edit_key($submittedAt, $email) {
	return hash('md5', mb_strtolower(trim((string)$email), 'UTF-8') . '|' . trim((string)$submittedAt) . '|' . sgk_edit_salt());
}

function sgk_edit_url($submittedAt, $email) {
	$baseUrl = rtrim(trim((string)(getenv('SGK_SITE_URL') ?: 'https://sgk.zrc-sazu.si')), '/');
	return $baseUrl . '/registracija?edit=' . sgk_edit_key($submittedAt, $email);
}

function read_submission_rows($csvPath) {
	if (!is_file($csvPath)) {
		throw new RuntimeException('CSV file does not exist: ' . $csvPath);
	}

	$handle = fopen($csvPath, 'rb');
	if ($handle === false) {
		throw new RuntimeException('CSV file cannot be opened: ' . $csvPath);
	}

	$headers = fgetcsv($handle, 0, ';');
	if ($headers === false) {
		fclose($handle);
		return [];
	}

	$headers = array_map(static fn($value) => trim((string)$value), $headers);
	$rows = [];
	while (($row = fgetcsv($handle, 0, ';')) !== false) {
		if ($row === [null] || $row === []) {
			continue;
		}

		$row = array_pad($row, count($headers), '');
		$assoc = [];
		foreach ($headers as $index => $header) {
			$assoc[$header] = sgk_csv_decode_value($header, trim((string)($row[$index] ?? '')));
		}
		$rows[] = $assoc;
	}
	fclose($handle);

	return $rows;
}

function has_presentation($presentationType) {
	return in_array(trim((string)$presentationType), ['Predavanje', 'Plakat'], true);
}

function build_message($editUrl, $hasPresentation) {
	$text = "Pozdravljeni vsi, ki se boste udeležili 7. slovenskega geološkega kongresa med 1. in 3. oktobrom v Lipici.\n\n"
		. "Možnost urejanja/oddaje povzetka preko spletne strani je na voljo samo udeležencem, ki so pri registraciji izbrali predstavitev.\n\n";

	if ($hasPresentation) {
		$text .= "Ker ste se že registrirali na 7. SGK in ste oddali prispevek oziroma ste zapisali, da ga boste oddali naknadno (rok je 20. 7.), lahko prispevek oddate/uredite na naslednji povezavi:\n"
			. $editUrl . "\n\n"
			. "Prosim, da v izogib ponovni registraciji preko obrazca na spletni strani ali posamičnemu dopisovanju z uredniškim odborom raje izberete urejanje/pošiljanje povzetka preko povezave.\n\n";
	} else {
		$text .= "Ker ste pri registraciji izbrali možnost brez predstavitve, urejanje preko spletne strani ni mogoče. Za morebitne spremembe prosim pišite Astrid na e-mail astrid.svara@zrc-sazu.si.\n\n";
	}

	$text .= "Hvala za razumevanje in lep pozdrav.\n\n"
		. "Dr. Astrid Švara, asistentka z doktoratom\n"
		. "Inštitut za raziskovanje krasa\n"
		. "ZRC SAZU, Titov trg 2, 6230 Postojna, T 05 700 19 16, www.izrk.zrc-sazu.si/sl, www.zrc-sazu.si";

	return nl2br(e($text));
}

if (has_flag('help') || has_flag('h')) {
	usage();
	exit(0);
}

$csvPath = option_value('csv', __DIR__ . '/../.form/submissions.csv');
$send = has_flag('send');
$only = mb_strtolower(trim((string)option_value('only', '')), 'UTF-8');
$limit = option_value('limit', null);
$limit = $limit === null ? null : max(0, (int)$limit);

$rows = read_submission_rows($csvPath);
$recipients = [];
foreach ($rows as $row) {
	$email = trim((string)($row['email'] ?? ''));
	$submittedAt = trim((string)($row['submitted_at'] ?? ''));
	if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $submittedAt === '') {
		continue;
	}

	$key = mb_strtolower($email, 'UTF-8');
	if ($only !== '' && $key !== $only) {
		continue;
	}

	$presentationType = trim((string)($row['presentation_type'] ?? ''));
	$recipients[$key] = [
		'email'             => $email,
		'submitted_at'      => $submittedAt,
		'first_name'        => trim((string)($row['first_name'] ?? '')),
		'last_name'         => trim((string)($row['last_name'] ?? '')),
		'presentation_type' => $presentationType,
	];
}

if ($limit !== null) {
	$recipients = array_slice($recipients, 0, $limit, true);
}

$needsEditSalt = false;
foreach ($recipients as $recipient) {
	if (has_presentation($recipient['presentation_type'])) {
		$needsEditSalt = true;
		break;
	}
}

if ($needsEditSalt && sgk_edit_salt() === '') {
	fwrite(STDERR, "SGK_EDIT_SALT or SGK_ADMIN_SALT must be configured before edit links can be generated.\n");
	exit(1);
}

$subject = '7. SGK - urejanje/oddaja povzetka';
$senderName = 'IZRK (7. SGK)';
$senderEmail = 'astrid.svara@zrc-sazu.si';
$ccRecipients = ['astrid.svara@zrc-sazu.si', 'zan@krejzi.si'];
$sent = 0;
$failed = 0;

foreach ($recipients as $recipient) {
	$hasPresentation = has_presentation($recipient['presentation_type']);
	$editUrl = $hasPresentation ? sgk_edit_url($recipient['submitted_at'], $recipient['email']) : '';
	$name = trim($recipient['first_name'] . ' ' . $recipient['last_name']);

	if (!$send) {
		echo '[DRY-RUN] ' . $recipient['email'] . ($name !== '' ? ' (' . $name . ')' : '') .
			' [' . ($hasPresentation ? 'edit-link' : 'contact-astrid') . ']' .
			($editUrl !== '' ? ' -> ' . $editUrl : '') . PHP_EOL;
		continue;
	}

	$ok = mail::send(
		[$recipient['email']],
		$subject,
		build_message($editUrl, $hasPresentation),
		$senderName,
		'https://i.imgur.com/Rhe0NrC.png',
		'https://sgk.zrc-sazu.si/',
		[],
		null,
		$senderEmail,
		$senderEmail,
		$ccRecipients
	);

	if ($ok) {
		$sent++;
		echo '[SENT] ' . $recipient['email'] . PHP_EOL;
	} else {
		$failed++;
		echo '[FAILED] ' . $recipient['email'] . PHP_EOL;
	}
}

echo 'Recipients: ' . count($recipients) . ', sent: ' . $sent . ', failed: ' . $failed . ', mode: ' . ($send ? 'send': 'dry-run') . PHP_EOL;

exit($failed > 0 ? 1 : 0);
