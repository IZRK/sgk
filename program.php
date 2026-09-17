<?php
$pageTitle = 'Program kongresa | 7. Slovenski geološki kongres';
$activePage = 'program';
$programPdfFile = 'Program-7sgk.pdf';
$programPdfHref = '/' . rawurlencode($programPdfFile);
$programPdfPath = __DIR__ . '/' . $programPdfFile;
require __DIR__ . '/includes/header.php';
?>
<section>
  <div class="container page-flow">
    <h2>Program kongresa</h2>
    <p class="lead program-lead">Program kongresa je bil posodobljen 16. 9. 2026.</p>

    <?php if (is_file($programPdfPath)): ?>
      <iframe
        class="program-pdf-frame"
        src="<?= e($programPdfHref) ?>"
        title="Program, predavanja in plakati"
        loading="lazy">
      </iframe>
    <?php else: ?>
      <p class="form-alert">Program trenutno ni na voljo.</p>
    <?php endif; ?>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
