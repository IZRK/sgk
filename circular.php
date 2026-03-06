<?php
$file = __DIR__ . '/assets/7SGK_prvo_obvestilo_20.2.2026.pdf';

if (!is_file($file) || !is_readable($file)) {
    http_response_code(404);
    exit('Datoteka ni na voljo.');
}

if (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Description: File Transfer');
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="7SGK_prvo_obvestilo_20.2.2026.pdf"');
header('Content-Length: ' . (string) filesize($file));
header('Cache-Control: private, must-revalidate');
header('Pragma: public');
header('Expires: 0');

readfile($file);
exit;
