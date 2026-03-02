<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/bootstrap.php';

class mail
{
    public static function send(
        $to,
        $subject,
        $message = 'No message provided',
        $fromName = 'Inštitut za raziskovanje krasa',
        $logoImageUrl = 'https://i.imgur.com/Rhe0NrC.png',
        $logoLink = 'https://izrk.github.io/monitoring/',
        array $inlineImages = [],
        $footerHtml = null
    ) {
        $mail = new PHPMailer();
        $mail->isSMTP();
        $mail->Host = getenv('SMTPHOST') ?: '';
        $mail->SMTPAuth = true;
        $mail->Username = getenv('SMTPUSER') ?: '';
        $mail->Password = getenv('SMTPPASS') ?: '';
        $mail->SMTPSecure = false;
        $mail->SMTPAutoTLS = false;
        $mail->CharSet = 'UTF-8';
        $mail->Port = intval(getenv('SMTPPORT') ?: '25');

        $mail->setFrom('izrk.monitoring@zrc-sazu.si', $fromName);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;

        $templatePath = __DIR__ . '/../html/email.html';
        $html = is_file($templatePath)
            ? file_get_contents($templatePath)
            : '<html><body><h2>{{TITLE}}</h2><div>{{CONTENT}}</div><hr><small>{{FOOTER}}</small></body></html>';

        if (!empty($logoImageUrl)) {
            $logoData = @file_get_contents($logoImageUrl);
            if ($logoData !== false) {
                $logoCid = 'mainlogo_' . md5($logoImageUrl);
                $mail->addStringEmbeddedImage($logoData, $logoCid, 'logo.png', 'base64', 'image/png');
                $html = str_replace('src="https://i.imgur.com/Rhe0NrC.png"', 'src="cid:' . $logoCid . '"', $html);
                $html = str_replace('href="https://izrk.github.io/monitoring/"', 'href="' . htmlspecialchars($logoLink, ENT_QUOTES, 'UTF-8') . '"', $html);
            }
        }

        foreach ($inlineImages as $img) {
            if (empty($img['cid']) || empty($img['url'])) {
                continue;
            }
            $cid = $img['cid'];
            $url = $img['url'];
            $type = isset($img['type']) ? $img['type'] : 'image/png';
            $data = @file_get_contents($url);
            if ($data === false) {
                continue;
            }
            $name = isset($img['name']) ? $img['name'] : basename((string) parse_url($url, PHP_URL_PATH));
            $mail->addStringEmbeddedImage($data, $cid, $name, 'base64', $type);
        }

        $html = str_replace('{{TITLE}}', htmlspecialchars((string) $subject, ENT_QUOTES, 'UTF-8'), $html);
        $html = str_replace('{{CONTENT}}', (string) $message, $html);

        $footer = $footerHtml === null
            ? 'Sent on ' . date('r')
            : $footerHtml;

        $html = str_replace('{{FOOTER}}', $footer, $html);

        $mail->Body = $html;
        $mail->AltBody = strip_tags((string) $message);

        return $mail->send();
    }
}
