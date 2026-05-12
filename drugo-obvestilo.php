<?php
$files = glob(__DIR__ . '/assets/7SGK_drugo_obvestilo*.pdf') ?: [];
sort($files, SORT_NATURAL);
$file = end($files) ?: '';

if ($file === '' || !is_file($file) || !is_readable($file)) {
    http_response_code(404);
    exit('Datoteka ni na voljo.');
}

if (ob_get_level() > 0) {
    ob_end_clean();
}

$filename = basename($file);

header('Content-Description: File Transfer');
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . (string) filesize($file));
header('Cache-Control: private, must-revalidate');
header('Pragma: public');
header('Expires: 0');

readfile($file);
exit;
