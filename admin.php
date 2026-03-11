<?php
require_once __DIR__ . '/includes/bootstrap.php';

const SGK_ADMIN_SESSION_KEY = 'sgk_admin_authenticated';

function sgk_admin_hash_password(string $salt, string $password): string
{
    return hash('sha512', $salt . $password);
}

function sgk_read_csv_table(string $path): array
{
    if (!is_file($path)) {
        return [
            'headers' => [],
            'rows' => [],
            'error' => 'Datoteka s prijavami še ne obstaja.',
        ];
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return [
            'headers' => [],
            'rows' => [],
            'error' => 'Datoteke s prijavami ni bilo mogoče odpreti.',
        ];
    }

    $rawRows = [];
    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        if ($row === [null] || $row === []) {
            continue;
        }
        $rawRows[] = $row;
    }
    fclose($handle);

    if ($rawRows === []) {
        return [
            'headers' => [],
            'rows' => [],
            'error' => null,
        ];
    }

    $headers = array_map(
        static fn ($value): string => trim((string) $value),
        array_shift($rawRows)
    );

    $maxColumns = count($headers);
    foreach ($rawRows as $row) {
        $maxColumns = max($maxColumns, count($row));
    }

    for ($i = count($headers); $i < $maxColumns; $i++) {
        $headers[] = 'extra_' . ($i + 1);
    }

    $rows = [];
    foreach ($rawRows as $row) {
        $row = array_pad($row, $maxColumns, '');
        $assoc = [];
        foreach ($headers as $index => $header) {
            $assoc[$header] = trim((string) ($row[$index] ?? ''));
        }
        $rows[] = $assoc;
    }

    return [
        'headers' => $headers,
        'rows' => $rows,
        'error' => null,
    ];
}

function sgk_format_admin_label(string $column): string
{
    $labels = [
        'submitted_at' => 'Oddano',
        'first_name' => 'Ime',
        'last_name' => 'Priimek',
        'institution' => 'Ustanova',
        'address' => 'Naslov',
        'invoice_same' => 'Račun enak',
        'invoice_name' => 'Naziv za račun',
        'invoice_address' => 'Naslov za račun',
        'invoice_post' => 'Pošta za račun',
        'invoice_country' => 'Država za račun',
        'vat_payer' => 'Davčni zavezanec',
        'vat_id' => 'ID za DDV',
        'email' => 'E-mail',
        'postal_address' => 'Poštni naslov',
        'phone' => 'Telefon',
        'registration_type' => 'Kotizacija',
        'mid_excursion' => 'Medkongresna ekskurzija',
        'post_excursion' => 'Pokongresna ekskurzija',
        'photo_contest' => 'Foto natečaj',
        'payment_method' => 'Način plačila',
        'shirt_gender' => 'Model majice',
        'shirt_size' => 'Velikost majice',
        'diet_vegetarian' => 'Vegetarijanska prehrana',
        'diet_lactose' => 'Brez laktoze',
        'diet_gluten' => 'Brez glutena',
        'diet_none' => 'Brez omejitev',
        'diet_other' => 'Drugo prehrana',
        'presentation_type' => 'Predstavitev',
        'notes' => 'Opombe',
        'total_eur' => 'Skupaj EUR',
        'upn_qr_included' => 'UPN QR',
        'contact_name' => 'Kontaktna oseba',
        'title' => 'Naslov prispevka',
        'authors' => 'Avtorji',
        'institutions' => 'Institucije',
        'keywords' => 'Ključne besede',
        'keyword_count' => 'Št. ključnih besed',
        'abstract_text' => 'Povzetek',
        'abstract_word_count' => 'Št. besed',
    ];

    return $labels[$column] ?? ucwords(str_replace('_', ' ', $column));
}

function sgk_admin_is_boolean_column(string $column): bool
{
    return in_array($column, [
        'invoice_same',
        'vat_payer',
        'post_excursion',
        'photo_contest',
        'diet_vegetarian',
        'diet_lactose',
        'diet_gluten',
        'diet_none',
        'upn_qr_included',
    ], true);
}

function sgk_format_admin_datetime(string $value): ?string
{
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    return date('d.m.Y H:i:s', $timestamp);
}

function sgk_format_admin_value(string $column, string $value): array
{
    if ($value === '') {
        return [
            'html' => '—',
            'class' => 'is-empty',
        ];
    }

    if ($column === 'submitted_at') {
        $formatted = sgk_format_admin_datetime($value);
        if ($formatted !== null) {
            return [
                'html' => e($formatted),
                'class' => 'is-date',
            ];
        }
    }

    if (sgk_admin_is_boolean_column($column)) {
        $truthy = in_array(strtolower($value), ['1', 'true', 'yes', 'da'], true);

        return [
            'html' => $truthy
                ? '<span class="admin-bool admin-bool-yes" aria-label="Da">☑</span>'
                : '<span class="admin-bool admin-bool-no" aria-label="Ne">✕</span>',
            'class' => 'is-boolean',
        ];
    }

    return [
        'html' => e($value),
        'class' => '',
    ];
}

function sgk_admin_row_full_name(array $row): string
{
    $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    if ($fullName !== '') {
        return $fullName;
    }

    return trim((string) ($row['contact_name'] ?? ''));
}

function sgk_admin_summary_value(array $row, string $key): string
{
    if ($key === 'name') {
        return sgk_admin_row_full_name($row);
    }

    if ($key === 'submitted_at') {
        return sgk_format_admin_datetime((string) ($row['submitted_at'] ?? '')) ?? '';
    }

    return trim((string) ($row[$key] ?? ''));
}

function sgk_admin_preview_value(string $column, string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if ($column === 'submitted_at') {
        return sgk_format_admin_datetime($value) ?? $value;
    }

    if (sgk_admin_is_boolean_column($column)) {
        return in_array(strtolower($value), ['1', 'true', 'yes', 'da'], true) ? 'Da' : 'Ne';
    }

    return $value;
}

function sgk_admin_detail_sections(array $row): array
{
    $sections = [];

    $basic = [
        'Ime' => sgk_admin_row_full_name($row),
        'E-mail' => sgk_admin_preview_value('email', (string) ($row['email'] ?? '')),
        'Telefon' => sgk_admin_preview_value('phone', (string) ($row['phone'] ?? '')),
        'Ustanova' => sgk_admin_preview_value('institution', (string) ($row['institution'] ?? '')),
        'Naslov' => sgk_admin_preview_value('address', (string) ($row['address'] ?? '')),
        'Oddano' => sgk_admin_preview_value('submitted_at', (string) ($row['submitted_at'] ?? '')),
    ];
    $basic = array_filter($basic, static fn (string $value): bool => trim($value) !== '');
    if ($basic !== []) {
        $sections[] = ['title' => 'Osnovni podatki', 'items' => $basic];
    }

    $registration = [
        'Kotizacija' => sgk_admin_preview_value('registration_type', (string) ($row['registration_type'] ?? '')),
        'Način plačila' => sgk_admin_preview_value('payment_method', (string) ($row['payment_method'] ?? '')),
        'Predstavitev' => sgk_admin_preview_value('presentation_type', (string) ($row['presentation_type'] ?? '')),
        'Medkongresna ekskurzija' => sgk_admin_preview_value('mid_excursion', (string) ($row['mid_excursion'] ?? '')),
        'Pokongresna ekskurzija' => sgk_admin_preview_value('post_excursion', (string) ($row['post_excursion'] ?? '')),
        'Foto natečaj' => sgk_admin_preview_value('photo_contest', (string) ($row['photo_contest'] ?? '')),
        'Skupaj EUR' => sgk_admin_preview_value('total_eur', (string) ($row['total_eur'] ?? '')),
    ];
    $registration = array_filter($registration, static fn (string $value): bool => trim($value) !== '');
    if ($registration !== []) {
        $sections[] = ['title' => 'Registracija', 'items' => $registration];
    }

    $abstract = [
        'Naslov prispevka' => sgk_admin_preview_value('title', (string) ($row['title'] ?? '')),
        'Avtorji' => sgk_admin_preview_value('authors', (string) ($row['authors'] ?? '')),
        'Institucije' => sgk_admin_preview_value('institutions', (string) ($row['institutions'] ?? '')),
        'Ključne besede' => sgk_admin_preview_value('keywords', (string) ($row['keywords'] ?? '')),
        'Št. ključnih besed' => sgk_admin_preview_value('keyword_count', (string) ($row['keyword_count'] ?? '')),
        'Povzetek' => sgk_admin_preview_value('abstract_text', (string) ($row['abstract_text'] ?? '')),
        'Št. besed' => sgk_admin_preview_value('abstract_word_count', (string) ($row['abstract_word_count'] ?? '')),
    ];
    $abstract = array_filter($abstract, static fn (string $value): bool => trim($value) !== '');
    if ($abstract !== []) {
        $sections[] = ['title' => 'Povzetek', 'items' => $abstract];
    }

    $invoice = [
        'Račun enak' => sgk_admin_preview_value('invoice_same', (string) ($row['invoice_same'] ?? '')),
        'Naziv za račun' => sgk_admin_preview_value('invoice_name', (string) ($row['invoice_name'] ?? '')),
        'Naslov za račun' => sgk_admin_preview_value('invoice_address', (string) ($row['invoice_address'] ?? '')),
        'Pošta za račun' => sgk_admin_preview_value('invoice_post', (string) ($row['invoice_post'] ?? '')),
        'Država za račun' => sgk_admin_preview_value('invoice_country', (string) ($row['invoice_country'] ?? '')),
        'Davčni zavezanec' => sgk_admin_preview_value('vat_payer', (string) ($row['vat_payer'] ?? '')),
        'ID za DDV' => sgk_admin_preview_value('vat_id', (string) ($row['vat_id'] ?? '')),
    ];
    $invoice = array_filter($invoice, static fn (string $value): bool => trim($value) !== '');
    if ($invoice !== []) {
        $sections[] = ['title' => 'Račun', 'items' => $invoice];
    }

    $extras = [
        'Model majice' => sgk_admin_preview_value('shirt_gender', (string) ($row['shirt_gender'] ?? '')),
        'Velikost majice' => sgk_admin_preview_value('shirt_size', (string) ($row['shirt_size'] ?? '')),
        'Vegetarijanska prehrana' => sgk_admin_preview_value('diet_vegetarian', (string) ($row['diet_vegetarian'] ?? '')),
        'Brez laktoze' => sgk_admin_preview_value('diet_lactose', (string) ($row['diet_lactose'] ?? '')),
        'Brez glutena' => sgk_admin_preview_value('diet_gluten', (string) ($row['diet_gluten'] ?? '')),
        'Brez omejitev' => sgk_admin_preview_value('diet_none', (string) ($row['diet_none'] ?? '')),
        'Drugo prehrana' => sgk_admin_preview_value('diet_other', (string) ($row['diet_other'] ?? '')),
        'Opombe' => sgk_admin_preview_value('notes', (string) ($row['notes'] ?? '')),
        'UPN QR' => sgk_admin_preview_value('upn_qr_included', (string) ($row['upn_qr_included'] ?? '')),
    ];
    $extras = array_filter($extras, static fn (string $value): bool => trim($value) !== '');
    if ($extras !== []) {
        $sections[] = ['title' => 'Dodatno', 'items' => $extras];
    }

    return $sections;
}

$loginError = '';
$adminSalt = trim((string) (getenv('SGK_ADMIN_SALT') ?: ''));
$adminHash = trim((string) (getenv('SGK_ADMIN_HASH') ?: ''));
$authConfigured = $adminSalt !== '' && $adminHash !== '';
$turnstileConfigured = sgk_turnstile_is_configured();
$turnstileSiteKey = sgk_turnstile_site_key();
$isAuthenticated = !empty($_SESSION[SGK_ADMIN_SESSION_KEY]);

if (!$authConfigured) {
    unset($_SESSION[SGK_ADMIN_SESSION_KEY]);
    $isAuthenticated = false;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = trim((string) ($_POST['action'] ?? 'login'));

    if ($action === 'logout') {
        unset($_SESSION[SGK_ADMIN_SESSION_KEY]);
        $isAuthenticated = false;
    } else {
        $password = (string) ($_POST['password'] ?? '');
        if (!$authConfigured) {
            $loginError = 'Admin prijava ni konfigurirana v .env.';
        } else {
            $turnstile = sgk_turnstile_verify($_POST['cf-turnstile-response'] ?? '', $_SERVER['REMOTE_ADDR'] ?? null);
            if (!$turnstile['success']) {
                $loginError = $turnstile['error'];
            } elseif (hash_equals($adminHash, sgk_admin_hash_password($adminSalt, $password))) {
                $_SESSION[SGK_ADMIN_SESSION_KEY] = true;
                $isAuthenticated = true;
            } else {
                unset($_SESSION[SGK_ADMIN_SESSION_KEY]);
                $isAuthenticated = false;
                $loginError = 'Napačno geslo.';
            }
        }
    }
}

$csvPath = __DIR__ . '/.form/submissions.csv';

if ($isAuthenticated && (($_GET['download'] ?? '') === 'csv')) {
    if (is_file($csvPath)) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="registracija.csv"');
        header('Content-Length: ' . (string) filesize($csvPath));
        readfile($csvPath);
        exit;
    }
}

$table = $isAuthenticated
    ? sgk_read_csv_table($csvPath)
    : ['headers' => [], 'rows' => [], 'error' => null];

$rowCount = count($table['rows']);
?>
<!doctype html>
<html lang="sl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin | 7. Slovenski geološki kongres</title>
  <link rel="icon" type="image/x-icon" href="favicon.ico">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=EB+Garamond:wght@500;600&family=Geist:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="styles.css">
  <?php if ($turnstileConfigured): ?>
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
  <?php endif; ?>
  <style>
    body.admin-page {
      min-height: 100vh;
      background:
        radial-gradient(circle at top left, rgba(13, 90, 114, 0.13), transparent 30%),
        radial-gradient(circle at top right, rgba(183, 205, 190, 0.28), transparent 24%),
        #eef2ef;
    }

    .admin-shell {
      width: 100%;
      margin: 0 auto;
      padding: 12px 0 18px;
    }

    .admin-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      margin-bottom: 0.45rem;
      padding: 0 16px;
    }

    .admin-kicker {
      margin: 0 0 0.18rem;
      color: var(--accent);
      text-transform: uppercase;
      letter-spacing: 0.1em;
      font-size: 0.7rem;
      font-weight: 700;
    }

    .admin-title {
      margin: 0;
      font-size: clamp(1.35rem, 2vw, 1.9rem);
      line-height: 1.02;
    }

    .admin-card {
      background: rgba(255, 255, 255, 0.92);
      border: 1px solid rgba(17, 33, 40, 0.1);
      border-radius: 20px;
      box-shadow: 0 24px 55px rgba(10, 31, 38, 0.1);
      backdrop-filter: blur(8px);
    }

    .admin-login {
      width: min(440px, 100%);
      margin: 10vh auto 0;
      padding: 1.4rem;
    }

    .admin-login form {
      display: grid;
      gap: 0.9rem;
    }

    .admin-turnstile {
      width: 100%;
      min-height: 66px;
    }

    .admin-turnstile .cf-turnstile {
      width: 100%;
    }

    .admin-login input[type="password"] {
      width: 100%;
      border: 1px solid #cfd9de;
      border-radius: 10px;
      padding: 0.72rem 0.8rem;
      font: inherit;
    }

    .admin-toolbar {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      flex-wrap: wrap;
    }

    .admin-stat {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 44px;
      padding: 0.8rem 1.2rem;
      border: 1px solid rgba(13, 90, 114, 0.18);
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.78);
      color: #123b49;
      font-size: 0.95rem;
      font-weight: 700;
      line-height: 1;
    }

    .admin-table-card {
      overflow: hidden;
      padding: 0;
      border-radius: 0;
      border-left: 0;
      border-right: 0;
      box-shadow: none;
      backdrop-filter: none;
    }

    .admin-split {
      display: grid;
      grid-template-columns: minmax(640px, 1fr) minmax(260px, 320px);
      min-height: calc(100vh - 150px);
    }

    .admin-table-wrap {
      overflow: auto;
      margin: 0;
      max-height: calc(100vh - 150px);
      border-right: 1px solid rgba(17, 33, 40, 0.08);
    }

    .admin-table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      min-width: 100%;
      font-size: 0.93rem;
      margin: 0;
    }

    .admin-table th,
    .admin-table td {
      padding: 0.78rem 0.8rem;
      border-bottom: 1px solid rgba(17, 33, 40, 0.08);
      text-align: left;
      vertical-align: top;
    }

    .admin-table th {
      position: sticky;
      top: 0;
      z-index: 1;
      background: #f5f9f8;
      color: #35515c;
      font-size: 0.76rem;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      white-space: nowrap;
    }

    .admin-table td {
      background: rgba(255, 255, 255, 0.92);
      color: #13272f;
      white-space: normal;
      overflow-wrap: anywhere;
    }

    .admin-table tbody tr {
      cursor: pointer;
    }

    .admin-table tbody tr.is-active td {
      background: rgba(13, 90, 114, 0.10);
    }

    .admin-table tbody tr:nth-child(even) td {
      background: rgba(246, 250, 249, 0.96);
    }

    .admin-table tbody tr.is-active:nth-child(even) td {
      background: rgba(13, 90, 114, 0.12);
    }

    .admin-table td.is-empty {
      color: #7b8d95;
    }

    .admin-table td.is-boolean {
      text-align: center;
    }

    .admin-bool {
      display: inline-block;
      min-width: 1.25rem;
      font-size: 1rem;
      font-weight: 700;
      line-height: 1;
    }

    .admin-bool-yes {
      color: #1d6b3a;
    }

    .admin-bool-no {
      color: #8f2e2e;
    }

    .admin-inline-form {
      margin: 0;
      display: flex;
    }

    .admin-alert {
      margin: 0 0 1rem;
      padding: 0.85rem 1rem;
      border-radius: 12px;
      border: 1px solid transparent;
    }

    .admin-alert.error {
      background: #fff1f1;
      border-color: #e8c2c2;
      color: #842828;
    }

    .admin-alert.info {
      background: #eef7fb;
      border-color: #c8dfe8;
      color: #204a58;
    }

    .admin-empty {
      padding: 1.4rem 1.2rem;
      color: #43606a;
    }

    .admin-preview {
      padding: 0.75rem 0.8rem 0.9rem;
      overflow: auto;
      background:
        radial-gradient(circle at top right, rgba(13, 90, 114, 0.06), transparent 28%),
        rgba(255, 253, 248, 0.92);
    }

    .admin-preview-header {
      margin-bottom: 0.65rem;
      padding-bottom: 0.55rem;
      border-bottom: 1px solid rgba(17, 33, 40, 0.08);
    }

    .admin-preview-kicker {
      margin: 0 0 0.2rem;
      color: var(--accent);
      text-transform: uppercase;
      letter-spacing: 0.08em;
      font-size: 0.66rem;
      font-weight: 700;
    }

    .admin-preview-title {
      margin: 0;
      font-size: 1.02rem;
      line-height: 1.12;
      color: #13272f;
    }

    .admin-preview-subtitle {
      margin: 0.2rem 0 0;
      color: #4a6169;
      font-size: 0.82rem;
    }

    .admin-preview-section {
      padding: 10px;
    }

    .admin-preview-section + .admin-preview-section {
      margin-top: 0.2rem;
    }

    .admin-preview-section-title {
      margin: 0 0 0.12rem;
      color: var(--accent);
      text-transform: uppercase;
      letter-spacing: 0.08em;
      font-size: 0.68rem;
      font-weight: 700;
    }

    .admin-preview-list {
      display: grid;
      gap: 1px;
      background: rgba(17, 33, 40, 0.08);
      border: 1px solid rgba(17, 33, 40, 0.08);
      border-radius: 8px;
      overflow: hidden;
    }

    .admin-preview-row {
      display: grid;
      grid-template-columns: 110px minmax(0, 1fr);
      gap: 0.45rem;
      align-items: start;
      padding: 0.34rem 0.45rem;
      background: rgba(255, 255, 255, 0.9);
    }

    .admin-preview-label {
      color: #45606a;
      font-size: 0.63rem;
      letter-spacing: 0.07em;
      text-transform: uppercase;
      font-weight: 700;
      line-height: 1.25;
    }

    .admin-preview-value {
      color: #13272f;
      font-size: 0.84rem;
      line-height: 1.28;
      white-space: pre-wrap;
      overflow-wrap: anywhere;
    }

    @media (max-width: 760px) {
      .admin-shell {
        padding-top: 14px;
      }

      .admin-bar {
        flex-direction: column;
        align-items: flex-start;
      }

      .admin-split {
        grid-template-columns: 1fr;
      }

      .admin-table-wrap {
        border-right: 0;
        border-bottom: 1px solid rgba(17, 33, 40, 0.08);
        max-height: 48vh;
      }

      .admin-preview-row {
        grid-template-columns: 1fr;
        gap: 0.12rem;
      }
    }
  </style>
</head>
<body class="admin-page">
  <main class="admin-shell">
    <?php if (!$isAuthenticated): ?>
      <section class="admin-card admin-login">
        <p class="admin-kicker">Admin</p>
        <h1 class="admin-title">Prijave</h1>
        <p class="admin-subtitle">Dostop do administrativnega pregleda prijav je zaščiten z geslom.</p>
        <?php if ($loginError !== ''): ?>
          <div class="admin-alert error"><?= e($loginError) ?></div>
        <?php endif; ?>
        <?php if (!$turnstileConfigured): ?>
          <div class="admin-alert info">Turnstile ni konfiguriran v <code>.env</code>.</div>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="action" value="login">
          <label>
            <input type="password" name="password" autocomplete="current-password" required placeholder="Geslo">
          </label>
          <?php if ($turnstileConfigured): ?>
            <div class="admin-turnstile">
              <div class="cf-turnstile" data-sitekey="<?= e($turnstileSiteKey) ?>" data-theme="light" data-size="flexible"></div>
            </div>
          <?php endif; ?>
          <button type="submit" class="btn btn-primary">Prijava</button>
        </form>
      </section>
    <?php else: ?>
      <section class="admin-bar">
        <div>
          <p class="admin-kicker">Admin</p>
          <h1 class="admin-title">Pregled vnosov</h1>
        </div>
        <div class="admin-toolbar">
          <span class="admin-stat"><?= e((string) $rowCount) ?> prijav</span>
          <a class="btn btn-secondary" href="/">Nazaj na domačo stran</a>
          <a class="btn btn-secondary" href="/admin?download=csv">Prenesi CSV</a>
          <form method="post" class="admin-inline-form">
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="btn btn-secondary">Odjava</button>
          </form>
        </div>
      </section>

      <section class="admin-card admin-table-card">

        <?php if ($table['error'] !== null): ?>
          <div class="admin-alert info"><?= e($table['error']) ?></div>
        <?php elseif ($table['headers'] === []): ?>
          <div class="admin-empty">V datoteki trenutno ni podatkov.</div>
        <?php else: ?>
          <div class="admin-split">
            <div class="admin-table-wrap">
              <table class="admin-table" aria-label="Seznam prijav">
                <thead>
                  <tr>
                    <th>Ime</th>
                    <th>Oddano</th>
                    <th>Ustanova</th>
                    <th>Način plačila</th>
                    <th>Kontakt</th>
                    <th>Predstavitev</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($table['rows'] as $index => $row): ?>
                    <?php
                      $detailPayload = [
                          'name' => sgk_admin_row_full_name($row),
                          'subtitle' => trim((string) ($row['email'] ?? '')),
                          'sections' => sgk_admin_detail_sections($row),
                      ];
                    ?>
                    <tr class="<?= $index === 0 ? 'is-active' : '' ?>" data-preview='<?= e(json_encode($detailPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') ?>'>
                      <td><?= e(sgk_admin_summary_value($row, 'name') ?: '—') ?></td>
                      <td><?= e(sgk_admin_summary_value($row, 'submitted_at') ?: '—') ?></td>
                      <td><?= e(sgk_admin_summary_value($row, 'institution') ?: '—') ?></td>
                      <td><?= e(sgk_admin_summary_value($row, 'payment_method') ?: '—') ?></td>
                      <td><?= e(trim((sgk_admin_summary_value($row, 'email') ?: '') . ((sgk_admin_summary_value($row, 'phone') ?: '') !== '' ? ' / ' . sgk_admin_summary_value($row, 'phone') : '')) ?: '—') ?></td>
                      <td><?= e(sgk_admin_summary_value($row, 'presentation_type') ?: '—') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <aside class="admin-preview" id="admin-preview" aria-live="polite"></aside>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </main>
<?php if ($isAuthenticated && $table['error'] === null && $table['headers'] !== []): ?>
<script>
(function () {
  const rows = Array.from(document.querySelectorAll('.admin-table tbody tr[data-preview]'));
  const preview = document.getElementById('admin-preview');
  if (!rows.length || !preview) return;

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function renderPreview(payload) {
    const name = payload && payload.name ? payload.name : 'Izbrana prijava';
    const subtitle = payload && payload.subtitle ? payload.subtitle : '';
    const sections = payload && Array.isArray(payload.sections) ? payload.sections : [];

    let html = '';
    html += '<header class="admin-preview-header">';
    html += '<p class="admin-preview-kicker">Predogled prijave</p>';
    html += '<h2 class="admin-preview-title">' + escapeHtml(name) + '</h2>';
    if (subtitle) {
      html += '<p class="admin-preview-subtitle">' + escapeHtml(subtitle) + '</p>';
    }
    html += '</header>';

    sections.forEach(function (section) {
      const items = Array.isArray(section.items)
        ? section.items
        : Object.entries(section.items || {});

      if (!items.length) return;

      html += '<section class="admin-preview-section">';
      html += '<h3 class="admin-preview-section-title">' + escapeHtml(section.title || '') + '</h3>';
      html += '<div class="admin-preview-list">';

      items.forEach(function (item) {
        const label = Array.isArray(item) ? item[0] : '';
        const value = Array.isArray(item) ? item[1] : '';
        if (!String(value || '').trim()) return;

        html += '<div class="admin-preview-row">';
        html += '<div class="admin-preview-label">' + escapeHtml(label) + '</div>';
        html += '<div class="admin-preview-value">' + escapeHtml(value) + '</div>';
        html += '</div>';
      });

      html += '</div>';
      html += '</section>';
    });

    preview.innerHTML = html;
  }

  rows.forEach(function (row) {
    row.addEventListener('click', function () {
      rows.forEach(function (item) {
        item.classList.remove('is-active');
      });
      row.classList.add('is-active');

      try {
        renderPreview(JSON.parse(row.dataset.preview || '{}'));
      } catch (error) {
        renderPreview({});
      }
    });
  });

  rows[0].click();
})();
</script>
<?php endif; ?>
</body>
</html>
