<?php
/**
 * Shared enquiry-notification logic used by both send-enquiry.php (the
 * on-site form backend) and a2a.php (the A2A JSON-RPC skill). Kept in one
 * place so the two entry points can never drift on how a lead actually gets
 * delivered to the team.
 */

declare(strict_types=1);

function clean_field(string $value): string
{
    // Strip control/newline chars (defense against header injection) and trim.
    return trim(preg_replace('/[\r\n\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value));
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

function telegram_send_message(string $token, string $chatId, string $text): bool
{
    $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $httpCode >= 200 && $httpCode < 300;
}

function telegram_send_photo(string $token, string $chatId, string $path, string $mime, string $caption = ''): bool
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
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $httpCode >= 200 && $httpCode < 300;
}

function discord_send_notification(string $webhookUrl, string $title, array $embedFields, array $attachments): bool
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
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $httpCode >= 200 && $httpCode < 300;
}

/**
 * Fans an enquiry out to email/Telegram/Discord. $fields uses the same key
 * names as the on-site form: name, mobile, email, suburb,
 * contact_preference, job, details. Returns per-channel delivery outcome so
 * callers that can report back (like the A2A skill) know whether the lead
 * actually reached the team, rather than assuming success.
 */
function wm_send_enquiry_notifications(array $config, array $fields, string $source, array $attachments = [], array $uploadNotes = []): array
{
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

    $fromAddress = ($config['from_name'] ?? 'WasteMates Website') . ' <' . $config['mail_from'] . '>';
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
    $emailSent = $emailResult['success'];
    if (!$emailSent) {
        error_log('WasteMates enquiry email failed (HTTP ' . $emailResult['http_code'] . '): ' . $emailResult['response']);
    }

    // ── Telegram notification ───────────────────────────────────────────────
    $telegramSent = false;
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
            $tgLines[] = '⚠️ The email to ' . $config['mail_to'] . ' failed to send — this Telegram message is the only record of this lead.';
        }

        $telegramSent = telegram_send_message($token, $chatId, implode("\n", $tgLines));

        foreach ($attachments as $i => $a) {
            telegram_send_photo($token, $chatId, $a['tmp'], $a['mime'], 'Photo ' . ($i + 1) . '/' . count($attachments));
        }
    }

    // ── Discord notification ────────────────────────────────────────────────
    $discordSent = false;
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
            $discordFields[] = ['name' => '⚠️ Email failed', 'value' => 'The email to ' . $config['mail_to'] . ' failed to send — this Discord message is the only record of this lead.', 'inline' => false];
        }

        $discordSent = discord_send_notification(
            $config['discord_webhook_url'],
            '🗑 New enquiry — ' . $source . ($fields['name'] !== '' ? " from {$fields['name']}" : ''),
            $discordFields,
            $attachments
        );
    }

    return [
        'email_sent'    => $emailSent,
        'telegram_sent' => $telegramSent,
        'discord_sent'  => $discordSent,
    ];
}
