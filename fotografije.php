<?php
$pageTitle = 'Fotografije | 7. Slovenski geološki kongres';
$activePage = 'fotografije';
$manifestPath = __DIR__ . '/gallery/manifest.json';
$manifestData = is_file($manifestPath)
    ? json_decode((string)file_get_contents($manifestPath), true)
    : null;

$galleryGroups = [
    ['slug' => 'program-v-dvorani-capriola', 'title' => 'Program v dvorani Capriola'],
    ['slug' => 'registracije-in-ostalo', 'title' => 'Registracije in ostalo'],
    ['slug' => 'ekskurzija-v-kamnolomu-lipica-i', 'title' => 'Ekskurzija v kamnolomu Lipica I'],
    ['slug' => 'program-v-dvorani-bonavista', 'title' => 'Program v dvorani Bonavista'],
    ['slug' => 'ekskurzija-2tdk', 'title' => 'Ekskurzija 2TDK'],
    ['slug' => 'ekskurzija-v-skocjanske-jame', 'title' => 'Ekskurzija v Škocjanske jame'],
];

$photosByGroup = [];
if (is_array($manifestData['groups'] ?? null)) {
    foreach ($manifestData['groups'] as $group) {
        if (!is_array($group) || !is_string($group['slug'] ?? null) || !is_array($group['photos'] ?? null)) {
            continue;
        }
        $photosByGroup[$group['slug']] = $group['photos'];
    }
}

$galleryUrl = static function (string $groupSlug, string $fileName): string {
    return '/gallery/' . rawurlencode($groupSlug) . '/' . rawurlencode(basename($fileName));
};

$lightboxPhotos = [];
foreach ($galleryGroups as &$group) {
    $groupPhotos = [];
    foreach ($photosByGroup[$group['slug']] ?? [] as $photo) {
        if (!is_array($photo)) {
            continue;
        }
        $file = is_string($photo['file'] ?? null) ? basename($photo['file']) : '';
        $thumbnail = is_string($photo['thumbnail'] ?? null) ? basename($photo['thumbnail']) : $file;
        $photographer = is_string($photo['photographer'] ?? null) ? trim($photo['photographer']) : '';
        if ($file === '' || $photographer === '') {
            continue;
        }

        $entry = [
            'index' => count($lightboxPhotos),
            'file' => $file,
            'thumbnail' => $thumbnail,
            'photographer' => $photographer,
            'fullUrl' => $galleryUrl($group['slug'], $file),
            'thumbnailUrl' => $galleryUrl($group['slug'], $thumbnail),
            'groupTitle' => $group['title'],
        ];
        $groupPhotos[] = $entry;
        $lightboxPhotos[] = $entry;
    }
    $group['photos'] = $groupPhotos;
}
unset($group);

$totalPhotos = count($lightboxPhotos);

require __DIR__ . '/includes/header.php';
?>
<section>
  <div class="container page-flow photo-gallery-page">
    <h2>Fotografije</h2>
    <p class="lead">Fotografije s 7. slovenskega geološkega kongresa so razvrščene kronološko. Avtor je naveden ob vsaki fotografiji.</p>

    <?php if ($totalPhotos > 0): ?>
      <nav class="photo-gallery-index" aria-label="Fotografski sklopi">what
        <?php foreach ($galleryGroups as $group): ?>
          <?php if ($group['photos']): ?>
            <a href="#<?= e($group['slug']) ?>"><?= e($group['title']) ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
      </nav>

      <?php foreach ($galleryGroups as $group): ?>
        <?php if (!$group['photos']) { continue; } ?>
        <section class="photo-gallery-group" id="<?= e($group['slug']) ?>" aria-labelledby="heading-<?= e($group['slug']) ?>">
          <div class="photo-gallery-heading">
            <h3 id="heading-<?= e($group['slug']) ?>"><?= e($group['title']) ?></h3>
            <?php $count = count($group['photos']); ?>
            <span><?= $count ?> <?= $count === 1 ? 'fotografija' : ($count < 5 ? 'fotografije' : 'fotografij') ?></span>
          </div>
          <div class="photo-gallery-grid">
            <?php foreach ($group['photos'] as $photo): ?>
              <?php
                $fullImageUrl = $photo['fullUrl'];
                $thumbnailUrl = $photo['thumbnailUrl'];
                $photographer = $photo['photographer'];
              ?>
              <figure class="photo-gallery-card">
                <a class="photo-gallery-image" href="<?= e($fullImageUrl) ?>" target="_blank" rel="noopener" data-lightbox-index="<?= (int)$photo['index'] ?>" data-photographer="<?= e($photographer) ?>" data-group="<?= e($group['title']) ?>" aria-haspopup="dialog" aria-controls="photo-lightbox" aria-label="Odpri fotografijo avtorja: <?= e($photographer) ?>">
                  <img src="<?= e($thumbnailUrl) ?>" alt="" loading="lazy" decoding="async" />
                </a>
                <figcaption>
                  <span class="photo-gallery-credit">Foto: <?= e($photographer) ?></span>
                </figcaption>
              </figure>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>

      <dialog class="photo-lightbox" id="photo-lightbox" aria-label="Ogled fotografij">
        <div class="photo-lightbox-shell">
          <header class="photo-lightbox-toolbar">
            <div class="photo-lightbox-info">
              <p class="photo-lightbox-group" id="photo-lightbox-group"></p>
              <p class="photo-lightbox-credit" id="photo-lightbox-credit"></p>
            </div>
            <div class="photo-lightbox-actions">
              <span class="photo-lightbox-position" id="photo-lightbox-position" aria-live="polite"></span>
              <button class="photo-lightbox-close" type="button" aria-label="Zapri prikaz fotografije"><span aria-hidden="true">&times;</span></button>
            </div>
          </header>
          <div class="photo-lightbox-stage">
            <button class="photo-lightbox-arrow" id="photo-lightbox-previous" type="button" aria-label="Prejšnja fotografija"><span aria-hidden="true">&#8249;</span></button>
            <figure class="photo-lightbox-figure">
              <img class="photo-lightbox-image" id="photo-lightbox-image" alt="" decoding="async" />
            </figure>
            <button class="photo-lightbox-arrow" id="photo-lightbox-next" type="button" aria-label="Naslednja fotografija"><span aria-hidden="true">&#8250;</span></button>
          </div>
          <nav class="photo-lightbox-thumbnails" aria-label="Izberi fotografijo">
            <?php foreach ($lightboxPhotos as $photo): ?>
              <?php $thumbnailLabel = $photo['groupTitle'] . ', foto: ' . $photo['photographer']; ?>
              <button class="photo-lightbox-thumbnail" type="button" data-lightbox-thumbnail="<?= (int)$photo['index'] ?>" aria-label="<?= e($thumbnailLabel) ?>" title="<?= e($thumbnailLabel) ?>">
                <img src="<?= e($photo['thumbnailUrl']) ?>" alt="" loading="lazy" decoding="async" />
              </button>
            <?php endforeach; ?>
          </nav>
        </div>
      </dialog>
      <script src="<?= e(sgk_asset_url('gallery/lightbox.js')) ?>" defer></script>
    <?php else: ?>
      <p class="photo-gallery-empty" role="status">Fotografije trenutno niso na voljo.</p>
    <?php endif; ?>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
