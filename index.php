<?php
// Front controller for Nginx try_files fallback routing.
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($requestPath, $scriptDir . '/')) {
    $requestPath = substr($requestPath, strlen($scriptDir));
}

$slug = trim($requestPath, '/');
$slug = preg_replace('/\.(php|html)$/i', '', $slug ?? '') ?? '';
if ($slug === '') {
    $slug = 'index';
}

$routes = [
    'index' => null,
    'home' => null,
    'domov' => null,
    'o-kongresu' => null,
    'pomembni-datumi' => 'pomembni-datumi.php',
    'povzetki' => 'povzetki.php',
    'registracija' => 'registracija.php',
    'program' => 'program.php',
    'ekskurzije' => 'ekskurzije.php',
    'prizorisce' => 'prizorisce.php',
    'sponzorji' => 'sponzorji.php',
    'circular' => 'circular.php',
];

if (!array_key_exists($slug, $routes)) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

if ($routes[$slug] !== null) {
    require __DIR__ . '/' . $routes[$slug];
    exit;
}

$pageTitle = '7. Slovenski geološki kongres';
$activePage = 'home';
require __DIR__ . '/includes/header.php';
?>
<section>
  <div class="container home-flow">
    <h2>Dobrodošli</h2>
    <p class="lead">7. Slovenski geološki kongres je osrednji nacionalni strokovno-znanstveni forum za izmenjavo novih raziskovalnih rezultatov geologije in sorodnih ved.</p>

    <div class="cta">
      <a class="btn btn-primary" href="/circular">1. obvestilo</a>
    </div>

    <div class="cards">
      <article class="card">
        <strong>Lokacija</strong>
        <div>Lipica, Hotel Maestoso</div>
      </article>
      <article class="card">
        <strong>Termin</strong>
        <div>1.-3. oktober 2026</div>
      </article>
      <article class="card">
        <strong>Organizator</strong>
        <div>Inštitut za raziskovanje krasa ZRC SAZU</div>
      </article>
    </div>

    <article class="panel panel-spacious">
      <h3>O kongresu</h3>
      <p>Sedmi slovenski geološki kongres je posvečen izmenjavi novih raziskovalnih rezultatov, širjenju geološke znanosti in pomenu geologije za širšo družbo.</p>
      <p>Osrednji del kongresa poteka v Lipici, v Hotelu Maestoso in kongresnem centru Conversano. Ekskurzije se širijo tudi na druga območja Krasa.</p>
      <p>Kongres omogoča dialog med raziskovalci, strokovnjaki iz aplikativnega okolja, uporabniki geoloških raziskav in študenti.</p>
      <p>Uradni jezik kongresa je slovenščina. Za tuje avtorje je dovoljena oddaja prispevkov in predstavitev v angleščini.</p>
    </article>

    <div class="grid">
      <article class="panel">
        <h3>Namen kongresa</h3>
        <p>Kongres povezuje raziskovalce, strokovnjake in uporabnike geoloških znanj, s posebnim poudarkom na geologiji krasa in kraških kamnin.</p>
        <p>Predstavlja osrednji nacionalni prostor za strokovno razpravo, predstavitev aktualnih raziskav ter povezovanje različnih področij geologije in sorodnih ved.</p>
      </article>
      <article class="panel">
        <h3>Kontakt</h3>
        <ul class="timeline">
          <li><time>Kontaktna oseba</time><span>Astrid Švara</span></li>
          <li><time>E-mail</time><span><a href="mailto:astrid.svara@zrc-sazu.si">astrid.svara@zrc-sazu.si</a></span></li>
          <li><time>Telefon</time><span>05 700 19 00</span></li>
        </ul>
      </article>
    </div>

    <div class="grid">
      <article class="panel">
        <h3>Organizator</h3>
        <p>Inštitut za raziskovanje krasa ZRC SAZU<br>Titov trg 2, 6230 Postojna</p>
        <p>Kontaktna oseba: Astrid Švara (<a href="mailto:astrid.svara@zrc-sazu.si">astrid.svara@zrc-sazu.si</a>)</p>
      </article>
      <article class="panel">
        <h3>Soorganizator</h3>
        <p>Slovensko geološko društvo</p>
      </article>
    </div>

    <article class="panel panel-spacious">
      <h3>Organizacijski odbor</h3>
      <p>Astrid Švara, Nadja Zupan Hajna, Martin Knez, Bojan Otoničar, Mitja Prelovšek, Franci Gabrovšek, Cyril Mayaud, Uroš Novak, Matej Jelovčan, Tinkara Mazej, Filip Šarc, Metka Petrič, Stanka Šebela, Blaž Kogovšek.</p>
      <p><strong>Znanstveni odbor:</strong> bo objavljen naknadno.</p>
    </article>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
