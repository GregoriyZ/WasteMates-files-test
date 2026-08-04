<?php
// Temporary diagnostic script — calls the real Resend API with the live
// config and echoes back the raw response. Not linked anywhere, delete
// after use.

declare(strict_types=1);
header('Content-Type: application/json');

$config = @include __DIR__ . '/config.php';
$out = [
    'config_loaded' => is_array($config),
    'resend_api_key_len' => is_array($config) ? strlen((string) ($config['resend_api_key'] ?? '')) : null,
    'mail_to' => is_array($config) ? $config['mail_to'] ?? null : null,
    'mail_from' => is_array($config) ? $config['mail_from'] ?? null : null,
];

$fromAddress = ($config['from_name'] ?? 'WasteMates Website') . ' <' . $config['mail_from'] . '>';

$payload = [
    'from'    => $fromAddress,
    'to'      => [$config['mail_to']],
    'subject' => 'DEBUG Resend API test',
    'html'    => '<p>Debug test of the Resend integration.</p>',
    'text'    => 'Debug test of the Resend integration.',
];

$ch = curl_init('https://api.resend.com/emails');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $config['resend_api_key'],
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
]);
$response = curl_exec($ch);
$out['http_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$out['curl_error'] = curl_error($ch);
$out['response_body'] = $response;
curl_close($ch);

echo json_encode($out, JSON_PRETTY_PRINT);
