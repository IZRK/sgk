<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/mail.php';

$turnstileConfigured = sgk_turnstile_is_configured();
$turnstileSiteKey = sgk_turnstile_site_key();

$registrationPrices = [
    'redna-zgodnja' => 350.00,
    'redna-pozna' => 450.00,
    'redna-zgodnja-sgd' => 300.00,
    'redna-pozna-sgd' => 400.00,
    'studentska-zgodnja' => 200.00,
    'studentska-pozna' => 250.00,
];

$registrationLabels = [
    'redna-zgodnja' => 'Redna zgodnja',
    'redna-pozna' => 'Redna pozna',
    'redna-zgodnja-sgd' => 'Redna zgodnja za člane SGD',
    'redna-pozna-sgd' => 'Redna pozna za člane SGD',
    'studentska-zgodnja' => 'Študentska/upokojenska zgodnja',
    'studentska-pozna' => 'Študentska/upokojenska pozna',
];

function parseImageDataUrl(string $value): ?array
{
    $value = trim($value);
    if ($value === '' || !preg_match('#^data:(image/(png|jpeg|gif));base64,(.+)$#s', $value, $matches)) {
        return null;
    }

    $binary = base64_decode($matches[3], true);
    if ($binary === false || $binary === '' || strlen($binary) > 2_500_000) {
        return null;
    }

    return [
        'mime' => $matches[1],
        'extension' => $matches[2] === 'jpeg' ? 'jpg' : $matches[2],
        'content' => $binary,
    ];
}

function deriveInvoiceFields(array $formData): array
{
    $fullName = trim($formData['first_name'] . ' ' . $formData['last_name']);
    $invoiceName = trim($formData['institution']) !== ''
        ? trim($formData['institution'])
        : $fullName;

    return [
        'invoice_name' => $invoiceName,
        'invoice_address' => trim($formData['address']),
    ];
}

$formData = [
    'first_name' => '',
    'last_name' => '',
    'institution' => '',
    'address' => '',
    'invoice_same' => '1',
    'invoice_name' => '',
    'invoice_address' => '',
    'invoice_post' => '',
    'invoice_country' => '',
    'vat_payer' => '',
    'vat_id' => '',
    'email' => '',
    'phone' => '',
    'registration_type' => '',
    'mid_excursion' => 'none',
    'post_excursion' => '',
    'photo_contest' => '',
    'payment_method' => '',
    'shirt_gender' => '',
    'shirt_size' => '',
    'diet_vegetarian' => '',
    'diet_lactose' => '',
    'diet_gluten' => '',
    'diet_none' => '',
    'diet_other' => '',
    'presentation_type' => '',
    'notes' => '',
    'upn_qr_image' => '',
];

$errors = [];
$success = '';
$total = 0.0;

function saveSubmissionToCsv(string $csvPath, array $row): bool
{
    $dir = dirname($csvPath);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }

    $isNewFile = !is_file($csvPath) || filesize($csvPath) === 0;
    $handle = fopen($csvPath, 'ab');
    if ($handle === false) {
        return false;
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return false;
    }

    $headers = array_keys($row);
    if ($isNewFile) {
        if (fputcsv($handle, $headers, ';') === false) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return false;
        }
    }

    $ok = fputcsv($handle, array_values($row), ';') !== false;
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return $ok;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    foreach ($formData as $key => $value) {
        $formData[$key] = isset($_POST[$key]) ? trim((string) $_POST[$key]) : '';
    }

    $turnstile = sgk_turnstile_verify($_POST['cf-turnstile-response'] ?? '', $_SERVER['REMOTE_ADDR'] ?? null);
    if (!$turnstile['success']) {
        $errors[] = $turnstile['error'];
    }

    if ($formData['invoice_same'] === '1') {
        $formData = array_merge($formData, deriveInvoiceFields($formData));
    }

    if ($formData['vat_payer'] !== '1') {
        $formData['vat_id'] = '';
    }

    if ($formData['first_name'] === '') {
        $errors[] = 'Vnesite ime.';
    }
    if ($formData['last_name'] === '') {
        $errors[] = 'Vnesite priimek.';
    }
    if (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Vnesite veljaven e-mail naslov.';
    }
    if (!array_key_exists($formData['registration_type'], $registrationPrices)) {
        $errors[] = 'Izberite vrsto kotizacije.';
    }
    if ($formData['payment_method'] === '') {
        $errors[] = 'Izberite način plačila.';
    }
    if ($formData['presentation_type'] === '') {
        $errors[] = 'Izberite obliko predstavitve.';
    }

    if (array_key_exists($formData['registration_type'], $registrationPrices)) {
        $total += $registrationPrices[$formData['registration_type']];
    }

    if ($formData['mid_excursion'] === 'kobilarna') {
        $total += 16.0;
    }

    if ($formData['post_excursion'] === '1') {
        $total += 40.0;
    }

    if (empty($errors)) {
        $configuredRecipients = getenv('FORM_RECIPIENTS') ?: 'astrid.svara@zrc-sazu.si,zan@krejzi.si';
        $configuredRecipientList = preg_split('/[\s,;|]+/', $configuredRecipients) ?: [];
        $recipients = array_values(array_unique(array_filter(array_merge(
            [$formData['email']],
            array_map('trim', $configuredRecipientList)
        ))));
        $senderName = getenv('SENDER') ?: 'ZRC SAZU';
        $qrImage = null;
        $qrImageCid = null;

        if ($formData['payment_method'] === 'bančno nakazilo') {
            $qrImage = parseImageDataUrl($formData['upn_qr_image']);
            if ($qrImage !== null) {
                $qrImageCid = 'upn_qr_' . md5($formData['email'] . '|' . number_format($total, 2, '.', ''));
            }
        }

        $message = '<p>Nova prijava na 7. SGK.</p>';
        $message .= '<table style="border-collapse:collapse;width:100%;">';
        $rows = [
            'Ime' => $formData['first_name'],
            'Priimek' => $formData['last_name'],
            'Ustanova' => $formData['institution'],
            'Naslov' => $formData['address'],
            'E-mail' => $formData['email'],
            'Telefon' => $formData['phone'],
            'Kotizacija' => $registrationLabels[$formData['registration_type']] ?? '',
            'Medkongresna ekskurzija' => $formData['mid_excursion'],
            'Pokongresna ekskurzija' => $formData['post_excursion'] === '1' ? 'Da' : 'Ne',
            'Fotografski natečaj' => $formData['photo_contest'] === '1' ? 'Da' : 'Ne',
            'Način plačila' => $formData['payment_method'],
            'Majica' => trim($formData['shirt_gender'] . ' ' . $formData['shirt_size']),
            'Prehrana - vegetarijanec' => $formData['diet_vegetarian'] === '1' ? 'Da' : 'Ne',
            'Prehrana - laktoza' => $formData['diet_lactose'] === '1' ? 'Da' : 'Ne',
            'Prehrana - gluten' => $formData['diet_gluten'] === '1' ? 'Da' : 'Ne',
            'Prehrana - nič od naštetega' => $formData['diet_none'] === '1' ? 'Da' : 'Ne',
            'Prehrana - drugo' => $formData['diet_other'],
            'Predstavitev' => $formData['presentation_type'],
            'Opombe' => $formData['notes'],
            'Skupaj za plačilo (EUR)' => number_format($total, 2, ',', '.'),
        ];

        foreach ($rows as $label => $value) {
            $message .= '<tr>';
            $message .= '<td style="border:1px solid #d4dee3;padding:8px;font-weight:700;">' . e($label) . '</td>';
            $message .= '<td style="border:1px solid #d4dee3;padding:8px;">' . nl2br(e((string) $value)) . '</td>';
            $message .= '</tr>';
        }
        $message .= '</table>';

        if ($qrImageCid !== null) {
            $message .= '<p style="margin-top:24px;text-align:center;"><strong>UPN QR za plačilo</strong></p>';
            $message .= '<p style="margin:0;text-align:center;"><img src="cid:' . e($qrImageCid) . '" alt="UPN QR za plačilo 7. SGK" style="display:inline-block;width:250px;max-width:100%;height:auto;background:#ffffff;"></p>';
        }

        $csvRow = [
            'submitted_at' => date('c'),
            'first_name' => $formData['first_name'],
            'last_name' => $formData['last_name'],
            'institution' => $formData['institution'],
            'address' => $formData['address'],
            'invoice_same' => $formData['invoice_same'] === '1' ? '1' : '0',
            'invoice_name' => $formData['invoice_name'],
            'invoice_address' => $formData['invoice_address'],
            'invoice_post' => $formData['invoice_post'],
            'invoice_country' => $formData['invoice_country'],
            'vat_payer' => $formData['vat_payer'] === '1' ? '1' : '0',
            'vat_id' => $formData['vat_id'],
            'email' => $formData['email'],
            'phone' => $formData['phone'],
            'registration_type' => $formData['registration_type'],
            'mid_excursion' => $formData['mid_excursion'],
            'post_excursion' => $formData['post_excursion'] === '1' ? '1' : '0',
            'photo_contest' => $formData['photo_contest'] === '1' ? '1' : '0',
            'payment_method' => $formData['payment_method'],
            'shirt_gender' => $formData['shirt_gender'],
            'shirt_size' => $formData['shirt_size'],
            'diet_vegetarian' => $formData['diet_vegetarian'] === '1' ? '1' : '0',
            'diet_lactose' => $formData['diet_lactose'] === '1' ? '1' : '0',
            'diet_gluten' => $formData['diet_gluten'] === '1' ? '1' : '0',
            'diet_none' => $formData['diet_none'] === '1' ? '1' : '0',
            'diet_other' => $formData['diet_other'],
            'presentation_type' => $formData['presentation_type'],
            'notes' => $formData['notes'],
            'total_eur' => number_format($total, 2, '.', ''),
            'upn_qr_included' => $qrImageCid !== null ? '1' : '0',
        ];
        $csvPath = __DIR__ . '/.form/submissions.csv';
        $csvSaved = saveSubmissionToCsv($csvPath, $csvRow);
        if (!$csvSaved) {
            $errors[] = 'Prijave ni bilo mogoče shraniti. Poskusite znova.';
        }

        if (empty($errors)) {
            $subject = '7. SGK prijava - ' . $formData['first_name'] . ' ' . $formData['last_name'];
            $inlineImages = [];
            if ($qrImage !== null && $qrImageCid !== null) {
                $inlineImages[] = [
                    'cid' => $qrImageCid,
                    'content' => $qrImage['content'],
                    'type' => $qrImage['mime'],
                    'name' => 'upn-qr-' . date('Ymd-His') . '.' . $qrImage['extension'],
                ];
            }

            $sent = mail::send(
                $recipients,
                $subject,
                $message,
                $senderName,
                'https://i.imgur.com/Rhe0NrC.png',
                'https://izrk.github.io/monitoring/',
                $inlineImages
            );

            if ($sent) {
                $success = 'Prijava je bila uspešno poslana. Predračun prejmete na e-mail naslov.';
            } else {
                $errors[] = 'Pošiljanje ni uspelo. Preverite SMTP nastavitve v .env.';
            }
        }
    }
}

$pageTitle = 'Registracija | 7. Slovenski geološki kongres';
$activePage = 'registracija';
require __DIR__ . '/includes/header.php';
?>
<section>
  <div class="container page-flow">
    <h2>Registracija</h2>

    <?php if ($success !== ''): ?>
      <div class="form-alert form-alert-success">
        <p><?= e($success) ?></p>
      </div>
    <?php else: ?>
      <article class="panel">
        <h3>Kotizacija</h3>
        <table class="fees-table">
          <thead>
            <tr><th>Vrsta kotizacije</th><th>Cena (EUR) z DDV</th><th>Opomba</th></tr>
          </thead>
          <tbody>
            <tr><td>Redna zgodnja</td><td>350,00</td><td></td></tr>
            <tr><td>Redna pozna</td><td>450,00</td><td></td></tr>
            <tr><td>Redna zgodnja za člane SGD*</td><td>300,00</td><td>* članarina za 2026: <a href="https://www.slovenskogeoloskodrustvo.si/clanstvo/" target="_blank" rel="noreferrer">povezava</a></td></tr>
            <tr><td>Redna pozna za člane SGD*</td><td>400,00</td><td>* članarina za 2026: <a href="https://www.slovenskogeoloskodrustvo.si/clanstvo/" target="_blank" rel="noreferrer">povezava</a></td></tr>
            <tr><td>Študentska/upokojenska** zgodnja</td><td>200,00</td><td>** z ustreznim dokazilom</td></tr>
            <tr><td>Študentska/upokojenska** pozna</td><td>250,00</td><td>** z ustreznim dokazilom</td></tr>
          </tbody>
        </table>
        <p>Stroški namestitve niso vključeni v kotizacijo.</p>
      </article>

      <?php if (!empty($errors)): ?>
        <div class="form-alert form-alert-error">
          <?php foreach ($errors as $error): ?>
            <p><?= e($error) ?></p>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" class="registration-form" id="registration-form">
      <h3>Osebni podatki</h3>
      <div class="form-grid">
        <label>Ime*
          <input type="text" name="first_name" value="<?= e($formData['first_name']) ?>" required>
        </label>
        <label>Priimek*
          <input type="text" name="last_name" value="<?= e($formData['last_name']) ?>" required>
        </label>
        <label>Ustanova
          <input type="text" name="institution" value="<?= e($formData['institution']) ?>">
        </label>
        <label>Naslov
          <input type="text" name="address" value="<?= e($formData['address']) ?>">
        </label>
      </div>

      <h3>Podatki za račun</h3>
      <label class="inline-check"><input type="checkbox" name="invoice_same" value="1" <?= $formData['invoice_same'] === '1' ? 'checked' : '' ?>> Enako kot zgoraj</label>
      <div class="form-grid">
        <label>Ime / Naziv / Ustanova
          <input type="text" name="invoice_name" value="<?= e($formData['invoice_name']) ?>">
        </label>
        <label>Naslov
          <input type="text" name="invoice_address" value="<?= e($formData['invoice_address']) ?>">
        </label>
        <label>Pošta
          <input type="text" name="invoice_post" value="<?= e($formData['invoice_post']) ?>">
        </label>
        <label>Država
          <input type="text" name="invoice_country" value="<?= e($formData['invoice_country']) ?>">
        </label>
      </div>
      <div class="form-grid">
        <label class="inline-check"><input type="checkbox" name="vat_payer" value="1" <?= $formData['vat_payer'] === '1' ? 'checked' : '' ?>> Davčni zavezanec</label>
        <label>ID za DDV
          <input type="text" name="vat_id" value="<?= e($formData['vat_id']) ?>">
        </label>
      </div>

      <h3>Kontaktni podatki</h3>
      <div class="form-grid">
        <label>E-mail*
          <input type="email" name="email" value="<?= e($formData['email']) ?>" required>
        </label>
        <label>Telefonska številka
          <input type="text" name="phone" value="<?= e($formData['phone']) ?>">
        </label>
      </div>

      <h3>Kotizacija in dodatki</h3>
      <div class="form-grid">
        <label>Vrsta kotizacije*
          <select name="registration_type" id="registration_type" required>
            <option value="">Izberite</option>
            <option value="redna-zgodnja" data-price="350" <?= $formData['registration_type'] === 'redna-zgodnja' ? 'selected' : '' ?>>Redna zgodnja (350,00)</option>
            <option value="redna-pozna" data-price="450" <?= $formData['registration_type'] === 'redna-pozna' ? 'selected' : '' ?>>Redna pozna (450,00)</option>
            <option value="redna-zgodnja-sgd" data-price="300" <?= $formData['registration_type'] === 'redna-zgodnja-sgd' ? 'selected' : '' ?>>Redna zgodnja za člane SGD (300,00)</option>
            <option value="redna-pozna-sgd" data-price="400" <?= $formData['registration_type'] === 'redna-pozna-sgd' ? 'selected' : '' ?>>Redna pozna za člane SGD (400,00)</option>
            <option value="studentska-zgodnja" data-price="200" <?= $formData['registration_type'] === 'studentska-zgodnja' ? 'selected' : '' ?>>Študentska/upokojenska zgodnja (200,00)</option>
            <option value="studentska-pozna" data-price="250" <?= $formData['registration_type'] === 'studentska-pozna' ? 'selected' : '' ?>>Študentska/upokojenska pozna (250,00)</option>
          </select>
        </label>

        <label>Način plačila*
          <select name="payment_method" required>
            <option value="">Izberite</option>
            <option value="bančno nakazilo" <?= $formData['payment_method'] === 'bančno nakazilo' ? 'selected' : '' ?>>Bančno nakazilo</option>
            <option value="naročilnica - plačilo po računu" <?= $formData['payment_method'] === 'naročilnica - plačilo po računu' ? 'selected' : '' ?>>Naročilnica - plačilo po računu</option>
            <option value="drugo" <?= $formData['payment_method'] === 'drugo' ? 'selected' : '' ?>>Drugo</option>
          </select>
        </label>
      </div>

      <fieldset>
        <legend>Medkongresna ekskurzija (1. 10. 2026)</legend>
        <label class="inline-check"><input type="radio" name="mid_excursion" value="none" data-price="0" <?= $formData['mid_excursion'] === 'none' ? 'checked' : '' ?>> Brez izbire (0,00)</label>
        <label class="inline-check"><input type="radio" name="mid_excursion" value="kobilarna" data-price="16" <?= $formData['mid_excursion'] === 'kobilarna' ? 'checked' : '' ?>> Ogled kobilarne Lipica (16,00)</label>
        <label class="inline-check"><input type="radio" name="mid_excursion" value="kamnolom" data-price="0" <?= $formData['mid_excursion'] === 'kamnolom' ? 'checked' : '' ?>> Ogled kamnoloma Lipica (0,00)</label>
      </fieldset>

      <div class="form-grid">
        <label class="inline-check"><input type="checkbox" name="post_excursion" value="1" data-price="40" <?= $formData['post_excursion'] === '1' ? 'checked' : '' ?>> Pokongresna ekskurzija (3. 10. 2026) - 40,00</label>
        <label class="inline-check"><input type="checkbox" name="photo_contest" value="1" data-price="0" <?= $formData['photo_contest'] === '1' ? 'checked' : '' ?>> Sodelovanje na fotografskem natečaju (0,00)</label>
      </div>

      <h3>Kongresna majica</h3>
      <div class="form-grid">
        <label>Model
          <select name="shirt_gender">
            <option value="">Izberite</option>
            <option value="Ženska" <?= $formData['shirt_gender'] === 'Ženska' ? 'selected' : '' ?>>Ženska</option>
            <option value="Moška" <?= $formData['shirt_gender'] === 'Moška' ? 'selected' : '' ?>>Moška</option>
          </select>
        </label>
        <label>Velikost
          <select name="shirt_size">
            <option value="">Izberite</option>
            <option value="XS" <?= $formData['shirt_size'] === 'XS' ? 'selected' : '' ?>>XS</option>
            <option value="S" <?= $formData['shirt_size'] === 'S' ? 'selected' : '' ?>>S</option>
            <option value="M" <?= $formData['shirt_size'] === 'M' ? 'selected' : '' ?>>M</option>
            <option value="L" <?= $formData['shirt_size'] === 'L' ? 'selected' : '' ?>>L</option>
            <option value="XL" <?= $formData['shirt_size'] === 'XL' ? 'selected' : '' ?>>XL</option>
            <option value="XXL" <?= $formData['shirt_size'] === 'XXL' ? 'selected' : '' ?>>XXL</option>
          </select>
        </label>
      </div>

      <h3>Posebne prehranske restrikcije</h3>
      <div class="form-grid">
        <label class="inline-check"><input type="checkbox" name="diet_vegetarian" value="1" <?= $formData['diet_vegetarian'] === '1' ? 'checked' : '' ?>> Vegetarijanec</label>
        <label class="inline-check"><input type="checkbox" name="diet_lactose" value="1" <?= $formData['diet_lactose'] === '1' ? 'checked' : '' ?>> Alergija na laktozo</label>
        <label class="inline-check"><input type="checkbox" name="diet_gluten" value="1" <?= $formData['diet_gluten'] === '1' ? 'checked' : '' ?>> Alergija na gluten</label>
        <label class="inline-check"><input type="checkbox" name="diet_none" value="1" <?= $formData['diet_none'] === '1' ? 'checked' : '' ?>> Nič od naštetega</label>
      </div>
      <label>Drugo
        <input type="text" name="diet_other" value="<?= e($formData['diet_other']) ?>">
      </label>

      <h3>Predstavitev*</h3>
      <label class="inline-check"><input type="radio" name="presentation_type" value="Predavanje" <?= $formData['presentation_type'] === 'Predavanje' ? 'checked' : '' ?> required> Predavanje</label>
      <label class="inline-check"><input type="radio" name="presentation_type" value="Plakat" <?= $formData['presentation_type'] === 'Plakat' ? 'checked' : '' ?>> Plakat</label>
      <label class="inline-check"><input type="radio" name="presentation_type" value="Brez predstavitve" <?= $formData['presentation_type'] === 'Brez predstavitve' ? 'checked' : '' ?>> Brez predstavitve</label>

      <label>Opombe
        <textarea name="notes" rows="4"><?= e($formData['notes']) ?></textarea>
      </label>

      <input type="hidden" name="upn_qr_image" id="upn_qr_image" value="<?= e($formData['upn_qr_image']) ?>">

      <div class="total-row">
          Skupaj za plačilo:
          &nbsp;
          <strong id="total-display"><?= e(number_format($total, 2, ',', '.')) ?> EUR</strong>
      </div>

      <article class="panel payment-qr-panel" id="payment-qr-panel" hidden>
        <div class="payment-qr-copy">
          <h3>UPN QR za SEPA plačilo</h3>
          <p>Če izberete bančno nakazilo, lahko plačilo izvedete s skenom QR kode na zaslonu. Ista slika se ob oddaji obrazca priloži tudi v e-mail prijave.</p>
          <p class="payment-qr-note" id="payment-qr-note"></p>
        </div>
        <div class="payment-qr-visual">
          <div class="payment-qr-loader" id="payment-qr-loader" hidden aria-hidden="true">
            <span class="payment-qr-spinner"></span>
          </div>
          <img id="payment-qr-image" alt="UPN QR za plačilo kotizacije 7. SGK" hidden>
        </div>
      </article>

      <?php if ($turnstileConfigured): ?>
        <div class="turnstile-wrap">
          <div class="cf-turnstile" data-sitekey="<?= e($turnstileSiteKey) ?>" data-theme="light"></div>
        </div>
      <?php endif; ?>

      <button type="submit" class="btn btn-primary">Pošlji prijavo</button>
      </form>

      <article class="panel">
        <h3>Podatki za plačilo</h3>
        <p>ZRC SAZU, Novi trg 2, 1000 Ljubljana<br>
        ID za DDV: SI38048183<br>
        TRR: SI56 0110 0603 0347 346<br>
        Sklic: SI99 7SGK2026<br>
        Namen: 7SGK / Ime in Priimek udeleženca</p>
        <p>Plačilo kotizacije mora biti izvršeno v roku 30 dni. Udeleženci prejmejo predračun na elektronski naslov.</p>
        <p>Rezervacije nočitev: <a href="https://www.lipica.org/sl/prijava-na-kongres/" target="_blank" rel="noreferrer">https://www.lipica.org/sl/prijava-na-kongres/</a> (geslo: <strong>lipica1580</strong>)</p>
      </article>
    <?php endif; ?>
  </div>
</section>
<?php if ($success === ''): ?>
<?php if ($turnstileConfigured): ?>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>
<script>
(function () {
  const form = document.getElementById('registration-form');
  if (!form) return;
  const totalDisplay = document.getElementById('total-display');
  const paymentMethod = form.querySelector('select[name="payment_method"]');
  const qrPanel = document.getElementById('payment-qr-panel');
  const qrImage = document.getElementById('payment-qr-image');
  const qrLoader = document.getElementById('payment-qr-loader');
  const qrNote = document.getElementById('payment-qr-note');
  const qrInput = document.getElementById('upn_qr_image');

  const paymentRecipient = {
    prejemnik: 'ZRC SAZU',
    prnaslov: 'Novi trg 2',
    prposta: '1000 Ljubljana',
    trr: 'SI56011006030347346',
    ref: 'SI997SGK2026',
    koda: 'GDSV'
  };
  let qrDependenciesPromise = null;
  let qrRenderToken = 0;

  function formatEur(value) {
    return value.toFixed(2).replace('.', ',') + ' EUR';
  }

  function truncate(value, maxLength) {
    return String(value || '').trim().substring(0, maxLength);
  }

  function getFieldValue(name) {
    const field = form.elements[name];
    return field ? String(field.value || '') : '';
  }

  function hideQrImage() {
    qrImage.hidden = true;
    qrImage.removeAttribute('src');
    qrInput.value = '';
  }

  function setQrLoading(isLoading) {
    if (!qrLoader) {
      return;
    }

    qrLoader.hidden = !isLoading;
    qrLoader.setAttribute('aria-hidden', isLoading ? 'false' : 'true');
  }

  function setQrNote(message) {
    qrNote.textContent = message;
    qrNote.hidden = !message;
  }

  function splitAddress(value) {
    const normalized = String(value || '')
      .replace(/\r/g, '\n')
      .split('\n')
      .map((part) => part.trim())
      .filter(Boolean)
      .join(', ');

    if (!normalized) {
      return { address: '', post: '' };
    }

    const parts = normalized.split(',').map((part) => part.trim()).filter(Boolean);
    if (parts.length > 1) {
      return {
        address: truncate(parts[0], 33),
        post: truncate(parts.slice(1).join(', '), 33)
      };
    }

    return {
      address: truncate(normalized, 33),
      post: ''
    };
  }

  function deriveInvoiceFields() {
    const fullName = truncate([
      getFieldValue('first_name'),
      getFieldValue('last_name')
    ].filter(Boolean).join(' '), 255);

    return {
      invoice_name: getFieldValue('institution').trim() || fullName,
      invoice_address: getFieldValue('address')
    };
  }

  function getInvoiceFields() {
    return ['invoice_name', 'invoice_address']
      .map(function (name) {
        return form.elements[name];
      })
      .filter(Boolean);
  }

  function syncInvoiceFields() {
    const sameInvoice = !!(form.elements.invoice_same && form.elements.invoice_same.checked);
    const invoiceFields = getInvoiceFields();

    if (sameInvoice) {
      const derived = deriveInvoiceFields();

      invoiceFields.forEach(function (field) {
        if (field.dataset.manualValue === undefined) {
          field.dataset.manualValue = field.value;
        }

        field.value = derived[field.name] || '';
        field.disabled = true;
      });

      return;
    }

    invoiceFields.forEach(function (field) {
      field.disabled = false;
      if (field.dataset.manualValue !== undefined) {
        field.value = field.dataset.manualValue;
        delete field.dataset.manualValue;
      }
    });
  }

  function syncVatIdField() {
    const vatPayer = !!(form.elements.vat_payer && form.elements.vat_payer.checked);
    const vatIdField = form.elements.vat_id;
    if (!vatIdField) {
      return;
    }

    vatIdField.disabled = !vatPayer;
    if (!vatPayer) {
      vatIdField.value = '';
    }
  }

  function resolvePayerDetails() {
    const fullName = truncate([
      getFieldValue('first_name'),
      getFieldValue('last_name')
    ].filter(Boolean).join(' '), 33);

    if (form.elements.invoice_same && form.elements.invoice_same.checked) {
      const personalAddress = splitAddress(getFieldValue('address'));
      return {
        name: fullName,
        naslov: personalAddress.address,
        posta: personalAddress.post
      };
    }

    return {
      name: truncate(getFieldValue('invoice_name') || fullName, 33),
      naslov: truncate(getFieldValue('invoice_address'), 33),
      posta: truncate(getFieldValue('invoice_post'), 33)
    };
  }

  function calculateTotal() {
    let total = 0;
    const registrationField = form.elements.registration_type;
    if (registrationField && registrationField.selectedIndex >= 0) {
      const selectedRegistration = registrationField.options[registrationField.selectedIndex];
      total += Number(selectedRegistration.getAttribute('data-price') || 0);
    }

    const selectedMid = form.querySelector('input[name="mid_excursion"]:checked');
    if (selectedMid) {
      total += Number(selectedMid.getAttribute('data-price') || 0);
    }

    ['post_excursion', 'photo_contest'].forEach((name) => {
      const field = form.elements[name];
      if (field && field.checked) {
        total += Number(field.getAttribute('data-price') || 0);
      }
    });

    totalDisplay.textContent = formatEur(total);
    return total;
  }

  function buildPurpose(participantName) {
    return truncate(participantName ? `7SGK / ${participantName}` : '7SGK kotizacija', 42);
  }

  function buildQrPayload(total) {
    const payer = resolvePayerDetails();
    const participantName = truncate([
      getFieldValue('first_name'),
      getFieldValue('last_name')
    ].filter(Boolean).join(' '), 33);

    const fields = [
      'UPNQR',
      '',
      '',
      '',
      '',
      payer.name,
      payer.naslov,
      payer.posta,
      String(Math.round(total * 100)).padStart(11, '0'),
      '',
      '',
      paymentRecipient.koda,
      buildPurpose(participantName),
      '',
      paymentRecipient.trr,
      paymentRecipient.ref,
      paymentRecipient.prejemnik,
      paymentRecipient.prnaslov,
      paymentRecipient.prposta
    ];

    fields.push(String(19 + fields.reduce((sum, value) => sum + value.length, 0)).padStart(3, '0'));
    return fields.join('\n');
  }

  function buildQrNote(total) {
    void total;
    return '';
  }

  function loadQrDependencies() {
    if (qrDependenciesPromise) {
      return qrDependenciesPromise;
    }

    qrDependenciesPromise = Promise.all([
      Promise.resolve().then(function () {
        const dynamicImport = new Function('url', 'return import(url);');
        return dynamicImport('https://esm.sh/@nuintun/qrcode@5.0.2?bundle');
      }),
      Promise.resolve().then(function () {
        const dynamicImport = new Function('url', 'return import(url);');
        return dynamicImport('https://esm.sh/iconv-lite@0.6.3?bundle');
      })
    ]).then(function (modules) {
      const qr = modules[0];
      const iconv = modules[1];

      if (!qr || !qr.Byte || !qr.Charset || !qr.Encoder) {
        throw new Error('QR knjižnica nima pričakovanih izvozov.');
      }

      if (!iconv || typeof iconv.encode !== 'function') {
        throw new Error('Knjižnica za kodiranje znakov ni na voljo.');
      }

      return { qr: qr, iconv: iconv };
    });

    return qrDependenciesPromise;
  }

  function renderQr() {
    syncInvoiceFields();
    syncVatIdField();

    const total = calculateTotal();
    const shouldShow = paymentMethod && paymentMethod.value === 'bančno nakazilo' && total > 0;
    const note = buildQrNote(total);

    qrPanel.hidden = !shouldShow;
    setQrNote(note);

    if (!shouldShow) {
      setQrLoading(false);
      hideQrImage();
      return;
    }

    const currentToken = ++qrRenderToken;
    setQrNote('');
    setQrLoading(true);

    loadQrDependencies()
      .then(function (deps) {
        if (currentToken !== qrRenderToken) {
          return;
        }

        const qr = deps.qr;
        const iconv = deps.iconv;
        const encoder = new qr.Encoder({
          level: 'H',
          encode: function (content, charset) {
            const bytes = iconv.encode(content, charset.label);
            return bytes instanceof Uint8Array ? bytes : Uint8Array.from(bytes);
          }
        });
        const encoded = encoder.encode(new qr.Byte(buildQrPayload(total), qr.Charset.ISO_8859_2));
        const moduleSize = Math.max(4, Math.floor(240 / encoded.size));
        const margin = moduleSize * 4;
        const dataUrl = encoded.toDataURL(moduleSize, { margin: margin });
        if (currentToken !== qrRenderToken) {
          return;
        }
        hideQrImage();

        const previewImage = new Image();
        previewImage.onload = function () {
          if (currentToken !== qrRenderToken) {
            return;
          }

          qrImage.src = dataUrl;
          qrImage.hidden = false;
          qrInput.value = dataUrl;
          setQrLoading(false);
          setQrNote(note);
        };
        previewImage.onerror = function () {
          if (currentToken !== qrRenderToken) {
            return;
          }

          setQrLoading(false);
          hideQrImage();
          setQrNote('QR slike ni bilo mogoče naložiti.');
        };
        previewImage.src = dataUrl;
      })
      .catch(function (error) {
        if (currentToken !== qrRenderToken) {
          return;
        }

        setQrLoading(false);
        hideQrImage();
        qrPanel.hidden = false;
        setQrNote('QR kode ni bilo mogoče ustvariti. Preverite povezavo do CDN knjižnice ali osvežite stran.');
        if (window.console && typeof window.console.error === 'function') {
          window.console.error(error);
        }
      });
  }

  form.addEventListener('change', renderQr);
  form.addEventListener('input', renderQr);
  window.addEventListener('pageshow', function () {
    renderQr();
  });
  syncInvoiceFields();
  syncVatIdField();
  renderQr();
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
