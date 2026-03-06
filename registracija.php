<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/mail.php';

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
];

$errors = [];
$success = '';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($formData as $key => $value) {
        $formData[$key] = isset($_POST[$key]) ? trim((string) $_POST[$key]) : '';
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

    $total = 0.0;
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
        $configuredRecipient = getenv('FORM_RECIPIENT') ?: 'astrid.svara@zrc-sazu.si';
        $recipients = array_values(array_unique([
            $configuredRecipient,
            'astrid.svara@zrc-sazu.si',
            'zan@krejzi.si',
        ]));
        $senderName = getenv('SENDER') ?: 'ZRC SAZU';

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
        ];
        $csvPath = __DIR__ . '/.form/submissions.csv';
        $csvSaved = saveSubmissionToCsv($csvPath, $csvRow);
        if (!$csvSaved) {
            $errors[] = 'Prijave ni bilo mogoče shraniti. Poskusite znova.';
        }

        if (empty($errors)) {
            $subject = '7. SGK prijava - ' . $formData['first_name'] . ' ' . $formData['last_name'];
            $sent = mail::send($recipients, $subject, $message, $senderName);

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

      <div class="total-row">Skupaj za plačilo: <strong id="total-display">0,00 EUR</strong></div>
      <button type="submit" class="btn btn-primary">Pošlji prijavo</button>
      </form>

      <article class="panel">
        <h3>Podatki za plačilo</h3>
        <p>ZRC SAZU, Novi trg 2, 1000 Ljubljana<br>
        ID za DDV: SI38048183<br>
        TRR: SI56 0110 0603 0347 346<br>
        Sklic: 7SGK2026<br>
        Namen: 7SGK / Ime in Priimek udeleženca</p>
        <p>Plačilo kotizacije mora biti izvršeno v roku 30 dni. Udeleženci prejmejo predračun na elektronski naslov.</p>
        <p>Rezervacije nočitev: <a href="https://www.lipica.org/sl/prijava-na-kongres/" target="_blank" rel="noreferrer">https://www.lipica.org/sl/prijava-na-kongres/</a> (geslo: <strong>lipica1580</strong>)</p>
      </article>
    <?php endif; ?>
  </div>
</section>
<?php if ($success === ''): ?>
<script>
(function () {
  const form = document.getElementById('registration-form');
  if (!form) return;

  function formatEur(value) {
    return value.toFixed(2).replace('.', ',') + ' EUR';
  }

  function calculateTotal() {
    let total = 0;

    const selectedRegistration = form.querySelector('#registration_type option:checked');
    if (selectedRegistration && selectedRegistration.dataset.price) {
      total += Number(selectedRegistration.dataset.price);
    }

    const selectedMid = form.querySelector('input[name="mid_excursion"]:checked');
    if (selectedMid && selectedMid.dataset.price) {
      total += Number(selectedMid.dataset.price);
    }

    ['post_excursion', 'photo_contest'].forEach((name) => {
      const field = form.querySelector(`input[name="${name}"]`);
      if (field && field.checked && field.dataset.price) {
        total += Number(field.dataset.price);
      }
    });

    document.getElementById('total-display').textContent = formatEur(total);
  }

  form.addEventListener('change', calculateTotal);
  calculateTotal();
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
