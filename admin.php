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

$tabs = [
    'registracija' => [
        'label' => 'Registracija',
        'path' => __DIR__ . '/.form/submissions.csv',
    ],
    'povzetki' => [
        'label' => 'Povzetki',
        'path' => __DIR__ . '/.form/povzetki.csv',
    ],
];

$activeTab = trim((string) ($_GET['tab'] ?? 'registracija'));
if (!array_key_exists($activeTab, $tabs)) {
    $activeTab = 'registracija';
}

if ($isAuthenticated && (($_GET['download'] ?? '') === 'csv')) {
    $csvPath = $tabs[$activeTab]['path'];
    if (is_file($csvPath)) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $activeTab . '.csv"');
        header('Content-Length: ' . (string) filesize($csvPath));
        readfile($csvPath);
        exit;
    }
}

$table = $isAuthenticated
    ? sgk_read_csv_table($tabs[$activeTab]['path'])
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

    .admin-table-wrap {
      overflow: auto;
      margin: 0;
      max-height: calc(100vh - 150px);
    }

    .admin-table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      min-width: 1200px;
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

    .admin-table tbody tr:nth-child(even) td {
      background: rgba(246, 250, 249, 0.96);
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

    @media (max-width: 760px) {
      .admin-shell {
        padding-top: 14px;
      }

      .admin-bar {
        flex-direction: column;
        align-items: flex-start;
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
          <span class="admin-stat"><?= e($tabs[$activeTab]['label']) ?>: <?= e((string) $rowCount) ?> vnosov</span>
          <a class="btn btn-secondary" href="/">Nazaj na domačo stran</a>
          <a class="btn btn-secondary" href="/admin?tab=<?= e($activeTab) ?>&download=csv">Prenesi CSV</a>
          <form method="post" class="admin-inline-form">
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="btn btn-secondary">Odjava</button>
          </form>
        </div>
      </section>

      <nav class="admin-tabs" aria-label="Admin zavihki">
        <?php foreach ($tabs as $tabKey => $tab): ?>
          <a class="admin-tab <?= $tabKey === $activeTab ? 'is-active' : '' ?>" href="/admin?tab=<?= e($tabKey) ?>"><?= e($tab['label']) ?></a>
        <?php endforeach; ?>
      </nav>

      <section class="admin-card admin-table-card">

        <?php if ($table['error'] !== null): ?>
          <div class="admin-alert info"><?= e($table['error']) ?></div>
        <?php elseif ($table['headers'] === []): ?>
          <div class="admin-empty">V datoteki trenutno ni podatkov.</div>
        <?php else: ?>
          <div class="admin-table-wrap">
            <table class="admin-table">
              <thead>
                <tr>
                  <?php foreach ($table['headers'] as $header): ?>
                    <th><?= e(sgk_format_admin_label($header)) ?></th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($table['rows'] as $row): ?>
                  <tr>
                    <?php foreach ($table['headers'] as $header): ?>
                      <?php $value = $row[$header] ?? ''; ?>
                      <?php $formatted = sgk_format_admin_value($header, $value); ?>
                      <td class="<?= e(trim($formatted['class'])) ?>"><?= $formatted['html'] ?></td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
