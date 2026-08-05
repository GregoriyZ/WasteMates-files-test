<?php
/**
 * WasteMates enquiry handler.
 *
 * Replaces formsubmit.co: accepts the hero/contact/pricing forms, emails the
 * enquiry (with however many photos were attached) via the Resend API, and
 * pushes instant Telegram + Discord notifications to the owner so a lead
 * never sits unseen in an inbox.
 */

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

const MAX_PHOTOS = 8;
const MAX_PHOTO_BYTES = 6 * 1024 * 1024;   // 6MB per photo
const MAX_TOTAL_BYTES = 15 * 1024 * 1024;  // 15MB combined, keeps the emailed
                                            // (base64) attachment size under
                                            // common 25MB inbox limits
const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif'];

const ALLOWED_REDIRECTS = [
    'home'    => '/?sent=1',
    'contact' => '/contact/?sent=1',
    'pricing' => '/pricing/?sent=1',
];

function redirect_to(string $key): void
{
    $target = ALLOWED_REDIRECTS[$key] ?? '/contact/?sent=1';
    header('Location: ' . $target, true, 303);
    exit;
}

function clean_field(string $value): string
{
    // Strip control/newline chars (defense against header injection) and trim.
    return trim(preg_replace('/[\r\n\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /contact.html', true, 303);
    exit;
}

$redirectKey = $_POST['_redirect'] ?? 'contact.html';

// Honeypot — bots fill every field including hidden ones. Pretend success
// so we don't tip them off, but do nothing further.
if (!empty($_POST['_honey'])) {
    redirect_to($redirectKey);
}

$config = @include __DIR__ . '/config.php';
if (!is_array($config)) {
    error_log('WasteMates enquiry: config.php missing or invalid');
    redirect_to($redirectKey);
}

$fields = [
    'name'               => clean_field((string) ($_POST['name'] ?? '')),
    'mobile'             => clean_field((string) ($_POST['mobile'] ?? '')),
    'email'              => clean_field((string) ($_POST['email'] ?? '')),
    'suburb'             => clean_field((string) ($_POST['suburb'] ?? '')),
    'contact_preference' => clean_field((string) ($_POST['contact_preference'] ?? '')),
    'job'                => clean_field((string) ($_POST['job'] ?? '')),
    'details'            => trim((string) ($_POST['details'] ?? '')),
];
$source = clean_field((string) ($_POST['_source'] ?? 'WasteMates website'));

// Nothing worth acting on — don't bother sending an empty lead.
if ($fields['name'] === '' && $fields['mobile'] === '' && $fields['email'] === '') {
    redirect_to($redirectKey);
}

// ── Collect + validate photo uploads ───────────────────────────────────────

$attachments = [];   // [['tmp' => ..., 'name' => ..., 'mime' => ...], ...]
$uploadNotes = [];    // human-readable notes about skipped photos
$totalBytes = 0;

if (!empty($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $count = count($_FILES['photos']['name']);

    for ($i = 0; $i < $count; $i++) {
        if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $origName = basename((string) $_FILES['photos']['name'][$i]);

        if (count($attachments) >= MAX_PHOTOS) {
            $uploadNotes[] = "Only the first " . MAX_PHOTOS . " photos were attached (more were selected).";
            break;
        }

        if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) {
            $uploadNotes[] = "\"{$origName}\" failed to upload and was skipped.";
            continue;
        }

        $tmp = $_FILES['photos']['tmp_name'][$i];
        $size = (int) $_FILES['photos']['size'][$i];

        if ($size > MAX_PHOTO_BYTES) {
            $uploadNotes[] = "\"{$origName}\" was skipped — over the 6MB per-photo limit.";
            continue;
        }
        if ($totalBytes + $size > MAX_TOTAL_BYTES) {
            $uploadNotes[] = "\"{$origName}\" was skipped — total photo size limit reached.";
            continue;
        }

        $mime = $finfo->file($tmp) ?: '';
        if (!in_array($mime, ALLOWED_MIMES, true)) {
            $uploadNotes[] = "\"{$origName}\" was skipped — not a supported image type.";
            continue;
        }

        $totalBytes += $size;
        $attachments[] = ['tmp' => $tmp, 'name' => $origName, 'mime' => $mime];
    }
}

// ── Build the email ─────────────────────────────────────────────────────────

$subject = "New enquiry — {$source}" . ($fields['name'] !== '' ? " from {$fields['name']}" : '');

$rows = [
    'Name'     => $fields['name'],
    'Mobile'   => $fields['mobile'],
    'Email'    => $fields['email'],
    'Suburb'   => $fields['suburb'],
    'Preferred contact' => $fields['contact_preference'],
    'Job type' => $fields['job'],
];

$textLines = ["New enquiry from {$source}", ''];
$htmlRows = '';
foreach ($rows as $label => $value) {
    if ($value === '') {
        continue;
    }
    $textLines[] = "{$label}: {$value}";
    $htmlRows .= '<tr><td style="padding:4px 12px 4px 0;color:#667;font-weight:600;">' . htmlspecialchars($label) . '</td><td style="padding:4px 0;">' . htmlspecialchars($value) . '</td></tr>';
}
if ($fields['details'] !== '') {
    $textLines[] = '';
    $textLines[] = "Details: {$fields['details']}";
}
if (!empty($attachments)) {
    $textLines[] = '';
    $textLines[] = count($attachments) . ' photo(s) attached.';
}
if (!empty($uploadNotes)) {
    $textLines[] = '';
    $textLines[] = implode("\n", $uploadNotes);
}

$htmlBody = '<table cellspacing="0" cellpadding="0">' . $htmlRows . '</table>';
if ($fields['details'] !== '') {
    $htmlBody .= '<p style="margin-top:14px;"><strong>Details:</strong><br>' . nl2br(htmlspecialchars($fields['details'])) . '</p>';
}
if (!empty($attachments)) {
    $htmlBody .= '<p style="margin-top:14px;">' . count($attachments) . ' photo(s) attached to this email.</p>';
}
if (!empty($uploadNotes)) {
    $htmlBody .= '<p style="margin-top:14px;color:#a33;">' . implode('<br>', array_map('htmlspecialchars', $uploadNotes)) . '</p>';
}

// Sent over HTTPS via the Resend API rather than raw SMTP — GoDaddy shared
// hosting blocks outbound SMTP ports entirely (confirmed: every attempt
// timed out connecting to smtp.gmail.com:587), so plain SMTP can never work
// from this host regardless of credentials.
function resend_send_email(string $apiKey, string $from, string $to, ?string $replyTo, string $subject, string $html, string $text, array $attachments): array
{
    $payload = [
        'from'    => $from,
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $html,
        'text'    => $text,
    ];
    if ($replyTo !== null) {
        $payload['reply_to'] = $replyTo;
    }
    if (!empty($attachments)) {
        $payload['attachments'] = array_map(function ($a) {
            return [
                'filename' => $a['name'],
                'content'  => base64_encode((string) file_get_contents($a['tmp'])),
            ];
        }, $attachments);
    }

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['success' => $httpCode >= 200 && $httpCode < 300, 'http_code' => $httpCode, 'response' => $response];
}

$emailSent = false;
$fromAddress = $config['from_name'] ?? 'WasteMates Website';
$fromAddress .= ' <' . $config['mail_from'] . '>';
$replyTo = ($fields['email'] !== '' && filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) ? $fields['email'] : null;

$emailResult = resend_send_email(
    (string) $config['resend_api_key'],
    $fromAddress,
    $config['mail_to'],
    $replyTo,
    $subject,
    $htmlBody,
    implode("\n", $textLines),
    $attachments
);

if ($emailResult['success']) {
    $emailSent = true;
} else {
    error_log('WasteMates enquiry email failed (HTTP ' . $emailResult['http_code'] . '): ' . $emailResult['response']);
}

// ── Telegram notification ───────────────────────────────────────────────────

function telegram_send_message(string $token, string $chatId, string $text): void
{
    $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function telegram_send_photo(string $token, string $chatId, string $path, string $mime, string $caption = ''): void
{
    $ch = curl_init("https://api.telegram.org/bot{$token}/sendPhoto");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $chatId,
            'caption' => $caption,
            'photo'   => new CURLFile($path, $mime),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

if (!empty($config['telegram_bot_token']) && !empty($config['telegram_chat_id'])) {
    $token = $config['telegram_bot_token'];
    $chatId = $config['telegram_chat_id'];

    $tgLines = ['🗑 <b>New WasteMates enquiry</b> — ' . htmlspecialchars($source)];
    foreach ($rows as $label => $value) {
        if ($value === '') {
            continue;
        }
        $tgLines[] = htmlspecialchars($label) . ': ' . htmlspecialchars($value);
    }
    if ($fields['details'] !== '') {
        $tgLines[] = '';
        $tgLines[] = htmlspecialchars($fields['details']);
    }
    if (!$emailSent) {
        $tgLines[] = '';
        $tgLines[] = '⚠️ The email to info@wastemates.com.au failed to send — this Telegram message is the only record of this lead.';
    }

    telegram_send_message($token, $chatId, implode("\n", $tgLines));

    foreach ($attachments as $i => $a) {
        telegram_send_photo($token, $chatId, $a['tmp'], $a['mime'], 'Photo ' . ($i + 1) . '/' . count($attachments));
    }
}

// ── Discord notification ────────────────────────────────────────────────────

function discord_send_notification(string $webhookUrl, string $title, array $embedFields, array $attachments): void
{
    $embed = [
        'title'  => mb_substr($title, 0, 256),
        'color'  => 3066993, // green
        'fields' => $embedFields,
    ];

    $postFields = ['payload_json' => json_encode(['embeds' => [$embed]])];
    foreach (array_values($attachments) as $i => $a) {
        $postFields["files[{$i}]"] = new CURLFile($a['tmp'], $a['mime'], $a['name']);
    }

    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

if (!empty($config['discord_webhook_url'])) {
    $discordFields = [];
    foreach ($rows as $label => $value) {
        if ($value === '') {
            continue;
        }
        $discordFields[] = ['name' => $label, 'value' => mb_substr($value, 0, 1024), 'inline' => true];
    }
    if ($fields['details'] !== '') {
        $discordFields[] = ['name' => 'Details', 'value' => mb_substr($fields['details'], 0, 1024), 'inline' => false];
    }
    if (!empty($uploadNotes)) {
        $discordFields[] = ['name' => '⚠️ Upload notes', 'value' => mb_substr(implode("\n", $uploadNotes), 0, 1024), 'inline' => false];
    }
    if (!$emailSent) {
        $discordFields[] = ['name' => '⚠️ Email failed', 'value' => 'The email to info@wastemates.com.au failed to send — this Discord message is the only record of this lead.', 'inline' => false];
    }

    discord_send_notification(
        $config['discord_webhook_url'],
        '🗑 New enquiry — ' . $source . ($fields['name'] !== '' ? " from {$fields['name']}" : ''),
        $discordFields,
        $attachments
    );
}

redirect_to($redirectKey);
