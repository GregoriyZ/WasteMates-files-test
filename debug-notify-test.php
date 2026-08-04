<?php
// Temporary diagnostic script — exercises the real email + Discord code
// paths with a real uploaded photo and echoes back what actually happened
// instead of swallowing errors. Not linked anywhere. Delete after use.

declare(strict_types=1);
header('Content-Type: application/json');

$out = [];

$config = @include __DIR__ . '/config.php';
$out['config_loaded'] = is_array($config);
$out['config_keys_present'] = is_array($config) ? array_keys(array_filter($config, fn($v) => $v !== '')) : [];

$attachments = [];
if (!empty($_FILES['photo']) && is_array($_FILES['photo']['name'])) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    for ($i = 0; $i < count($_FILES['photo']['name']); $i++) {
        $tmp = $_FILES['photo']['tmp_name'][$i];
        if ($_FILES['photo']['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }
        $mime = $finfo->file($tmp) ?: '';
        $attachments[] = ['tmp' => $tmp, 'name' => $_FILES['photo']['name'][$i], 'mime' => $mime];
    }
}
$out['attachments_built'] = $attachments;

// ── Real email attempt, exception captured ─────────────────────────────────
require __DIR__ . '/lib/PHPMailer/Exception.php';
require __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require __DIR__ . '/lib/PHPMailer/SMTP.php';

$emailResult = ['sent' => false];
try {
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->SMTPDebug = 2;
    $debugLog = [];
    $mail->Debugoutput = function ($str) use (&$debugLog) { $debugLog[] = $str; };
    $mail->isSMTP();
    $mail->Timeout = 10;
    $mail->SMTPKeepAlive = false;
    $mail->Host = trim((string) $config['smtp_host']);
    $mail->SMTPAuth = true;
    $mail->Username = trim((string) $config['smtp_user']);
    $mail->Password = $config['smtp_pass'];
    $mail->SMTPSecure = strtolower(trim((string) ($config['smtp_secure'] ?? 'ssl'))) === 'tls'
        ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS
        : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = (int) trim((string) ($config['smtp_port'] ?? 465));
    $mail->setFrom($config['mail_from'], 'Debug test');
    $mail->addAddress($config['mail_to']);
    foreach ($attachments as $a) {
        $mail->addAttachment($a['tmp'], $a['name']);
    }
    $mail->Subject = 'DEBUG notify test — with lead details + photo';
    $mail->isHTML(true);
    $mail->Body = '<p><strong>Name:</strong> Debug Notify Test</p><p><strong>Mobile:</strong> 0400000099</p><p>' . count($attachments) . ' photo(s) attached.</p>';
    $mail->AltBody = 'Name: Debug Notify Test / Mobile: 0400000099 / ' . count($attachments) . ' photo(s) attached.';
    $mail->send();
    $emailResult['sent'] = true;
    $emailResult['smtp_log'] = $debugLog;
} catch (\Throwable $e) {
    $emailResult['error'] = $e->getMessage();
    $emailResult['smtp_log'] = $debugLog ?? [];
}
$out['email'] = $emailResult;

// ── Real Discord attempt, response captured ─────────────────────────────────

$discordResult = [];
if (!empty($config['discord_webhook_url'])) {
    $embed = [
        'title'  => 'DEBUG notify test — with lead details + photo',
        'color'  => 3066993,
        'fields' => [
            ['name' => 'Name', 'value' => 'Debug Notify Test', 'inline' => true],
            ['name' => 'Mobile', 'value' => '0400000099', 'inline' => true],
        ],
    ];
    $postFields = ['payload_json' => json_encode(['embeds' => [$embed]])];
    foreach (array_values($attachments) as $i => $a) {
        $postFields["files[{$i}]"] = new CURLFile($a['tmp'], $a['mime'], $a['name']);
    }
    $ch = curl_init($config['discord_webhook_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $discordResult['http_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $discordResult['curl_error'] = curl_error($ch);
    $discordResult['response_body'] = $response;
    curl_close($ch);
} else {
    $discordResult['skipped'] = 'no webhook url in config';
}
$out['discord'] = $discordResult;

echo json_encode($out, JSON_PRETTY_PRINT);
