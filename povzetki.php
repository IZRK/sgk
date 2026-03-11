<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/mail.php';

$turnstileConfigured = sgk_turnstile_is_configured();
$turnstileSiteKey = sgk_turnstile_site_key();
$presentationOptions = ['Predavanje', 'Plakat'];

function saveAbstractToCsv(string $csvPath, array $row): bool
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

function countAbstractWords(string $value): int
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if ($value === '') {
        return 0;
    }

    preg_match_all('/\S+/u', $value, $matches);
    return count($matches[0]);
}

function parseKeywords(string $value): array
{
    $items = preg_split('/[,;\n\r]+/u', $value) ?: [];
    return array_values(array_filter(
        array_map(static fn (string $item): string => trim($item), $items),
        static fn (string $item): bool => $item !== ''
    ));
}

$formData = [
    'contact_name' => '',
    'email' => '',
    'title' => '',
    'authors' => '',
    'institutions' => '',
    'presentation_type' => '',
    'keywords' => '',
    'abstract_text' => '',
];

$errors = [];
$success = '';
$abstractWordCount = 0;
$keywordCount = 0;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    foreach ($formData as $key => $value) {
        $formData[$key] = isset($_POST[$key]) ? trim((string) $_POST[$key]) : '';
    }

    $turnstile = sgk_turnstile_verify($_POST['cf-turnstile-response'] ?? '', $_SERVER['REMOTE_ADDR'] ?? null);
    if (!$turnstile['success']) {
        $errors[] = $turnstile['error'];
    }

    $abstractWordCount = countAbstractWords($formData['abstract_text']);
    $keywordList = parseKeywords($formData['keywords']);
    $keywordCount = count($keywordList);

    if ($formData['contact_name'] === '') {
        $errors[] = 'Vnesite kontaktno osebo.';
    }
    if (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Vnesite veljaven e-mail naslov.';
    }
    if ($formData['title'] === '') {
        $errors[] = 'Vnesite naslov prispevka.';
    }
    if ($formData['authors'] === '') {
        $errors[] = 'Vnesite avtorje.';
    }
    if ($formData['institutions'] === '') {
        $errors[] = 'Vnesite institucije.';
    }
    if (!in_array($formData['presentation_type'], $presentationOptions, true)) {
        $errors[] = 'Izberite obliko predstavitve.';
    }
    if ($formData['abstract_text'] === '') {
        $errors[] = 'Vnesite povzetek.';
    } elseif ($abstractWordCount > 300) {
        $errors[] = 'Povzetek lahko vsebuje največ 300 besed.';
    }
    if ($keywordCount === 0) {
        $errors[] = 'Vnesite ključne besede.';
    } elseif ($keywordCount > 7) {
        $errors[] = 'Vnesete lahko največ 7 ključnih besed.';
    }

    if (empty($errors)) {
        $configuredRecipients = getenv('ABSTRACT_RECIPIENTS') ?: (getenv('FORM_RECIPIENTS') ?: 'astrid.svara@zrc-sazu.si,zan@krejzi.si');
        $configuredRecipientList = preg_split('/[\s,;|]+/', $configuredRecipients) ?: [];
        $recipients = array_values(array_unique(array_filter(array_merge(
            [$formData['email']],
            array_map('trim', $configuredRecipientList)
        ))));
        $senderName = getenv('SENDER') ?: 'ZRC SAZU';

        $message = '<p>Oddan je bil nov povzetek za 7. SGK.</p>';
        $message .= '<table style="border-collapse:collapse;width:100%;">';
        $rows = [
            'Kontaktna oseba' => $formData['contact_name'],
            'E-mail' => $formData['email'],
            'Naslov prispevka' => $formData['title'],
            'Avtorji' => $formData['authors'],
            'Institucije' => $formData['institutions'],
            'Oblika predstavitve' => $formData['presentation_type'],
            'Ključne besede' => implode(', ', $keywordList),
            'Število ključnih besed' => (string) $keywordCount,
            'Povzetek' => $formData['abstract_text'],
            'Število besed' => (string) $abstractWordCount,
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
            'contact_name' => $formData['contact_name'],
            'email' => $formData['email'],
            'title' => $formData['title'],
            'authors' => $formData['authors'],
            'institutions' => $formData['institutions'],
            'presentation_type' => $formData['presentation_type'],
            'keywords' => implode(', ', $keywordList),
            'keyword_count' => (string) $keywordCount,
            'abstract_text' => $formData['abstract_text'],
            'abstract_word_count' => (string) $abstractWordCount,
        ];

        $csvSaved = saveAbstractToCsv(__DIR__ . '/.form/povzetki.csv', $csvRow);
        if (!$csvSaved) {
            $errors[] = 'Povzetka ni bilo mogoče shraniti. Poskusite znova.';
        }

        if (empty($errors)) {
            $subject = '7. SGK povzetek - ' . $formData['title'];
            $sent = mail::send(
                $recipients,
                $subject,
                $message,
                $senderName,
                'https://i.imgur.com/Rhe0NrC.png',
                'https://izrk.github.io/monitoring/'
            );

            if ($sent) {
                $success = 'Povzetek je bil uspešno poslan.';
            } else {
                $errors[] = 'Pošiljanje ni uspelo. Preverite SMTP nastavitve v .env.';
            }
        }
    }
}

$pageTitle = 'Povzetki in predstavitve | 7. Slovenski geološki kongres';
$activePage = 'povzetki';
require __DIR__ . '/includes/header.php';
?>
<section>
  <div class="container page-flow">
    <h2>Povzetki in predstavitve</h2>

    <article class="panel">
      <h3>Navodila za predstavitve in plakate</h3>
      <p>Avtorji oddajo svoje predstavitve na ključku ob registraciji, najkasneje na dan predavanja. Dolžina predavanja bo določena po prejemu vseh povzetkov, predvideni pa sta dve vzporedni sekciji, ki bosta oblikovani glede na prejete teme prispevkov.</p>
      <p>V primeru presežka predavanj bo organizator zadnje prejete povzetke za predavanja premaknil v plakatno sekcijo. Avtorji bodo o spremembi pravočasno obveščeni.</p>
      <p>Zaradi oblike razstavnega prostora je obvezen največji format plakata A0, 841 × 1189 mm, v pokončni legi. Plakati se oddajo dopoldan ob registraciji na prvi kongresni dan in odstranijo do konca drugega dne. Izobešeni bodo na panojih pred glavno dvorano »Capriola«, v času predstavitve pa morajo biti avtorji ob svojih plakatih na voljo za vprašanja in pogovor. Če plakati do konca drugega kongresnega dne niso odstranjeni, si organizator pridržuje pravico, da jih odstrani.</p>
    </article>

    <article class="panel">
      <h3>Fotografski natečaj »Geologija krasa«</h3>
      <p>V sklopu kongresa bo potekal fotografski natečaj na temo »Geologija krasa«. Fotografije naj zajemajo različne geološke oblike, kras v inženirski geologiji ter kamnine v povezavi s kulturnimi in naravovarstvenimi vidiki. Lastniki treh zmagovalnih fotografij prejmejo kvalitetno tematsko darilo.</p>
      <p>Fotografije sprejemamo do 1. septembra 2026 na <a href="mailto:cyril.mayaud@zrc-sazu.si?subject=7SGK%20%E2%80%93%20FOTOGRAFSKI%20NATE%C4%8CAJ">cyril.mayaud@zrc-sazu.si</a>, s pripisom v naslovu sporočila »7SGK – FOTOGRAFSKI NATEČAJ«.</p>
      <p>Tehnične zahteve: fotografije morajo biti v formatu <strong>.jpg</strong>, posamezna datoteka je lahko velika največ <strong>5 MB</strong>, krajša stranica pa mora imeti najmanj <strong>2000 px</strong>.</p>
    </article>

    <article class="panel">
      <h3>Prispevki v reviji Geologija</h3>
      <p>Avtorji so vabljeni k oddaji znanstvenih člankov iz kongresnih prispevkov v revijo Geologija. Rok za oddajo je 28. september 2026: <a href="https://www.geologija-revija.si/index.php/geologija/article_submission" target="_blank" rel="noreferrer">oddaja člankov</a>.</p>
    </article>

    <article class="panel panel-spacious">
      <h3>Navodila za povzetke</h3>
      <p>Povzetki morajo biti oddani najkasneje do 20. julija 2026 preko spodnjega spletnega obrazca. Uradni jezik kongresa je slovenščina, za tuje avtorje pa je dovoljena oddaja povzetka in prispevka v angleškem jeziku.</p>
      <p>Udeleženci izberejo obliko predstavitve, predavanje ali plakat, pri čemer je oblika povzetka enaka za oba tipa predstavitev. S plačilom ene kotizacije je udeleženec upravičen do enega prispevka kot prvi avtor.</p>

      <h3>Oddaja povzetka</h3>

      <?php if ($success !== ''): ?>
      <div class="form-alert form-alert-success">
        <p><?= e($success) ?></p>
      </div>
      <?php else: ?>
        <?php if (!empty($errors)): ?>
        <div class="form-alert form-alert-error">
          <?php foreach ($errors as $error): ?>
            <p><?= e($error) ?></p>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      <form method="post" class="registration-form registration-form-embedded" id="abstract-form">

        <div class="form-grid">
          <label>Kontaktna oseba
            <input type="text" name="contact_name" value="<?= e($formData['contact_name']) ?>" required>
          </label>
          <label>E-mail
            <input type="email" name="email" value="<?= e($formData['email']) ?>" required>
          </label>
        </div>

        <h3>Prispevek</h3>
        <div class="form-grid">
          <label class="form-span-full">Naslov prispevka
            <input type="text" name="title" value="<?= e($formData['title']) ?>" required>
          </label>
          <label class="form-span-full">Avtorji
            <textarea name="authors" rows="3" required><?= e($formData['authors']) ?></textarea>
            <p class="form-note">Vnesite vse avtorje v vrstnem redu, kot naj bodo navedeni v programu in zborniku.</p>
          </label>
          <label class="form-span-full">Institucije
            <textarea name="institutions" rows="3" required><?= e($formData['institutions']) ?></textarea>
          </label>
          <label>Oblika predstavitve
            <select name="presentation_type" required>
              <option value="">Izberite</option>
              <?php foreach ($presentationOptions as $option): ?>
                <option value="<?= e($option) ?>" <?= $formData['presentation_type'] === $option ? 'selected' : '' ?>><?= e($option) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Ključne besede
            <input type="text" name="keywords" id="keywords" value="<?= e($formData['keywords']) ?>" required placeholder="npr. kras, hidrogeologija, sedimentologija">
            <p class="form-note">Ločite jih z vejicami.</p>
            <p class="form-counter" id="keywords-counter"><?= e((string) $keywordCount) ?> / 7 ključnih besed</p>
          </label>
          <label class="form-span-full">Povzetek
            <textarea name="abstract_text" id="abstract_text" rows="10" required><?= e($formData['abstract_text']) ?></textarea>
            <p class="form-counter" id="abstract-counter"><?= e((string) $abstractWordCount) ?> / 300 besed</p>
          </label>
        </div>

        <?php if ($turnstileConfigured): ?>
          <div class="turnstile-wrap">
            <div class="cf-turnstile" data-sitekey="<?= e($turnstileSiteKey) ?>" data-theme="light"></div>
          </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary">Pošlji povzetek</button>
      </form>
      <?php endif; ?>
    </article>
  </div>
</section>
<?php if ($success === ''): ?>
<?php if ($turnstileConfigured): ?>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>
<script>
(function () {
  const form = document.getElementById('abstract-form');
  if (!form) return;

  const abstractField = document.getElementById('abstract_text');
  const abstractCounter = document.getElementById('abstract-counter');
  const keywordsField = document.getElementById('keywords');
  const keywordsCounter = document.getElementById('keywords-counter');

  function countWords(value) {
    const normalized = String(value || '').trim().replace(/\s+/g, ' ');
    if (!normalized) return 0;
    return normalized.split(' ').filter(Boolean).length;
  }

  function countKeywords(value) {
    return String(value || '')
      .split(/[,;\n\r]+/)
      .map(function (item) { return item.trim(); })
      .filter(Boolean)
      .length;
  }

  function renderCounters() {
    if (abstractField && abstractCounter) {
      const words = countWords(abstractField.value);
      abstractCounter.textContent = words + ' / 300 besed';
      abstractCounter.classList.toggle('is-invalid', words > 300);
    }

    if (keywordsField && keywordsCounter) {
      const items = countKeywords(keywordsField.value);
      keywordsCounter.textContent = items + ' / 7 ključnih besed';
      keywordsCounter.classList.toggle('is-invalid', items > 7);
    }
  }

  form.addEventListener('input', renderCounters);
  renderCounters();
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
