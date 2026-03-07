<?php
require_once __DIR__ . '/bootstrap.php';

$pageTitle = $pageTitle ?? '7. Slovenski geološki kongres';
$activePage = $activePage ?? 'home';

$menu = [
    'home' => ['label' => 'Domov', 'href' => '/'],
    'pomembni-datumi' => ['label' => 'Pomembni datumi', 'href' => '/pomembni-datumi'],
    'povzetki' => ['label' => 'Povzetki in predstavitve', 'href' => '/povzetki'],
    'registracija' => ['label' => 'Registracija', 'href' => '/registracija'],
    'program' => ['label' => 'Program', 'href' => '/program'],
    'ekskurzije' => ['label' => 'Ekskurzije', 'href' => '/ekskurzije'],
    'prizorisce' => ['label' => 'Prizorišče', 'href' => '/prizorisce'],
    'sponzorji' => ['label' => 'Sponzorji', 'href' => '/sponzorji'],
];
?>
<!doctype html>
<html lang="sl">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta property="og:type" content="website" />
  <meta property="og:title" content="<?= e($pageTitle) ?>" />
  <meta property="og:description" content="7. Slovenski geološki kongres · 1.-3. oktober 2026 · Lipica, Hotel Maestoso." />
  <meta property="og:image" content="complete_header.webp" />
  <meta property="og:image:alt" content="7. Slovenski geološki kongres" />
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:title" content="<?= e($pageTitle) ?>" />
  <meta name="twitter:description" content="7. Slovenski geološki kongres · 1.-3. oktober 2026 · Lipica, Hotel Maestoso." />
  <meta name="twitter:image" content="complete_header.webp" />
  <title><?= e($pageTitle) ?></title>
  <link rel="icon" type="image/x-icon" href="favicon.ico" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=EB+Garamond:wght@400;500;600;700&family=Geist:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
<header class="hero">
  <div class="hero-shell hero-fullbleed">
    <img class="hero-bg" src="bg.webp" alt="Geološka tekstura in grafični elementi kongresa" />
    <div class="hero-fade"></div>
    <img class="hero-brand left" src="sgk_logo.svg" alt="7. Slovenski geološki kongres" />
    <div class="hero-brand-cluster" aria-label="Organizatorja kongresa">
      <img class="hero-brand zrc-logo" src="zrc.svg" alt="ZRC SAZU" />
      <img class="hero-brand sgd-logo" src="sgd.svg" alt="Slovensko geološko društvo" />
    </div>
    <a class="hero-title-link" href="/" aria-label="Nazaj na vstopno stran">
      <img class="hero-title" src="header.svg" alt="7. Slovenski geološki kongres" />
    </a>
    <div class="hero-meta left">Lipica, Hotel Maestoso</div>
    <div class="hero-meta right">1. - 3. 10. 2026</div>
  </div>

  <div class="container">
    <nav class="main-menu" id="main-menu" aria-label="Glavni meni">
      <button class="menu-toggle" type="button" aria-label="Odpri meni" aria-expanded="false" aria-controls="main-menu-links">
        <span class="menu-toggle-bar"></span>
        <span class="menu-toggle-bar"></span>
        <span class="menu-toggle-bar"></span>
      </button>
      <div class="main-menu-links" id="main-menu-links">
        <?php foreach ($menu as $key => $item): ?>
          <a class="menu-link <?= $key === $activePage ? 'is-active' : '' ?>" href="<?= e($item['href']) ?>"><?= e($item['label']) ?></a>
        <?php endforeach; ?>
      </div>
    </nav>
  </div>
</header>
<script>
  (function () {
    const nav = document.getElementById('main-menu');
    if (!nav) return;
    const toggle = nav.querySelector('.menu-toggle');
    if (!toggle) return;

    toggle.addEventListener('click', function () {
      const isOpen = nav.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    nav.querySelectorAll('.menu-link').forEach(function (link) {
      link.addEventListener('click', function () {
        nav.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
      });
    });

    window.addEventListener('resize', function () {
      if (window.innerWidth > 920) {
        nav.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
      }
    });
  })();
</script>
<main>
