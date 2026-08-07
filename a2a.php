<?php
/**
 * WasteMates A2A skill endpoint.
 *
 * A minimal, real Agent2Agent (A2A) JSON-RPC 1.0 server implementing exactly
 * one method — SendMessage — for exactly one skill: submitting a rubbish
 * removal enquiry. It's a thin JSON-RPC front end over the same
 * wm_send_enquiry_notifications() logic that send-enquiry.php (the on-site
 * form) uses, so a lead submitted by an agent reaches the team the same way
 * a lead submitted through the website does.
 *
 * No task persistence, streaming, or push notifications: every call is
 * handled synchronously and returns a terminal Task state in the same
 * response. That's honestly reflected in the Agent Card's `capabilities`
 * (streaming/pushNotifications both false).
 */

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/lib/enquiry-notify.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    echo json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Invalid Request: use POST']]);
    exit;
}

function jsonrpc_error($id, int $code, string $message): void
{
    echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    exit;
}

function uuidv4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

$raw = file_get_contents('php://input');
$request = json_decode($raw, true);

if (!is_array($request)) {
    jsonrpc_error(null, -32700, 'Parse error: request body must be valid JSON');
}

$id = $request['id'] ?? null;

if (($request['jsonrpc'] ?? null) !== '2.0' || !isset($request['method'])) {
    jsonrpc_error($id, -32600, 'Invalid Request: expected a JSON-RPC 2.0 envelope with "method"');
}

if ($request['method'] !== 'SendMessage') {
    jsonrpc_error($id, -32601, 'Method not found: this agent only implements SendMessage');
}

$params = $request['params'] ?? null;
$message = $params['message'] ?? null;
$parts = $message['parts'] ?? null;

if (!is_array($params) || !is_array($message) || !is_array($parts)) {
    jsonrpc_error($id, -32602, 'Invalid params: expected params.message.parts');
}

// Pull the enquiry out of whichever Part carries it: a structured `data`
// object is preferred; a plain `text` part is folded into `details` so a
// caller that can only send natural language still gets a usable result
// (just missing contact info, which the INPUT_REQUIRED reply below asks for).
$data = null;
$text = null;
foreach ($parts as $part) {
    if (is_array($part) && isset($part['data']) && is_array($part['data']) && $data === null) {
        $data = $part['data'];
    }
    if (is_array($part) && isset($part['text']) && is_string($part['text']) && $text === null) {
        $text = $part['text'];
    }
}

$contextId = is_string($message['contextId'] ?? null) ? $message['contextId'] : uuidv4();
$taskId = uuidv4();

function task_response($id, string $taskId, string $contextId, string $state, string $statusText, ?array $artifactData = null): void
{
    $task = [
        'id' => $taskId,
        'contextId' => $contextId,
        'status' => [
            'state' => $state,
            'message' => [
                'messageId' => uuidv4(),
                'contextId' => $contextId,
                'taskId' => $taskId,
                'role' => 'ROLE_AGENT',
                'parts' => [['text' => $statusText]],
            ],
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        ],
    ];
    if ($artifactData !== null) {
        $task['artifacts'] = [[
            'artifactId' => uuidv4(),
            'parts' => [['data' => $artifactData]],
        ]];
    }
    echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['task' => $task]]);
    exit;
}

$fields = [
    'name'               => clean_field((string) ($data['name'] ?? '')),
    'mobile'             => clean_field((string) ($data['mobile'] ?? '')),
    'email'              => clean_field((string) ($data['email'] ?? '')),
    'suburb'             => clean_field((string) ($data['suburb'] ?? '')),
    'contact_preference' => clean_field((string) ($data['contact_preference'] ?? '')),
    'job'                => clean_field((string) ($data['job'] ?? '')),
    'details'            => trim((string) ($data['details'] ?? ($text ?? ''))),
];

if ($fields['name'] === '' && $fields['mobile'] === '' && $fields['email'] === '') {
    task_response(
        $id,
        $taskId,
        $contextId,
        'TASK_STATE_INPUT_REQUIRED',
        'To submit this enquiry I need at least one way to contact the enquirer: a name, mobile number, or email address. Send another message on this task with a `data` part containing at least one of "name", "mobile", or "email" (plus optionally "suburb", "job", "details").'
    );
}

$config = @include __DIR__ . '/config.php';
if (!is_array($config)) {
    error_log('WasteMates A2A skill: config.php missing or invalid');
    task_response($id, $taskId, $contextId, 'TASK_STATE_FAILED', 'The enquiry could not be delivered — the site is misconfigured. Please try the web form at https://wastemates.com.au/contact.html instead.');
}

$result = wm_send_enquiry_notifications($config, $fields, 'A2A agent', []);
$delivered = $result['email_sent'] || $result['telegram_sent'] || $result['discord_sent'];

if (!$delivered) {
    task_response($id, $taskId, $contextId, 'TASK_STATE_FAILED', 'The enquiry could not be delivered through any channel. Please try the web form at https://wastemates.com.au/contact.html instead.', $result);
}

task_response($id, $taskId, $contextId, 'TASK_STATE_COMPLETED', "Thanks — the WasteMates team will be in touch soon.", $result);
