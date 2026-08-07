<?php
/**
 * WasteMates MCP server.
 *
 * A minimal, real Model Context Protocol server (Streamable HTTP transport,
 * protocol revision 2026-07-28 — the current stateless, per-request-metadata
 * model; no `initialize` handshake, no sessions). Implements exactly what
 * that revision requires and needs for one real tool:
 *
 *   - server/discover (MUST implement)
 *   - tools/list
 *   - tools/call — submit_enquiry, backed by the same
 *     wm_send_enquiry_notifications() logic send-enquiry.php and a2a.php use.
 *
 * No resources, prompts, sampling, elicitation, or subscriptions/listen —
 * this server doesn't need them, and the Server Card at
 * /.well-known/mcp/server-card.json makes no claim that it does.
 */

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/lib/enquiry-notify.php';

const MCP_PROTOCOL_VERSION = '2026-07-28';
const SERVER_NAME = 'wastemates-enquiry-server';
const SERVER_VERSION = '1.0.0';
const TOOL_NAME = 'submit_enquiry';

header('Content-Type: application/json; charset=utf-8');
// Public, stateless, no cookies/ambient auth — same posture as a2a.php.
// DNS-rebinding (the reason Streamable HTTP normally requires Origin
// validation) targets servers that trust network position or session
// state; this one trusts neither, so it's safe to accept any Origin.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, MCP-Protocol-Version, Mcp-Method, Mcp-Name');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    exit;
}

function get_header(string $name): ?string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return $_SERVER[$key] ?? null;
}

function send_error($id, int $httpStatus, int $code, string $message, ?array $data = null): void
{
    http_response_code($httpStatus);
    $err = ['code' => $code, 'message' => $message];
    if ($data !== null) {
        $err['data'] = $data;
    }
    echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => $err]);
    exit;
}

function server_info(): array
{
    return ['name' => SERVER_NAME, 'version' => SERVER_VERSION];
}

function result_response($id, array $result): void
{
    $result['_meta'] = ['io.modelcontextprotocol/serverInfo' => server_info()];
    echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    exit;
}

function tool_definition(): array
{
    return [
        'name' => TOOL_NAME,
        'title' => 'Submit Rubbish Removal Enquiry',
        'description' => 'Submits a rubbish removal or waste collection quote enquiry to WasteMates, a Melbourne-based rubbish removal business. Requires at least one of "name", "mobile", or "email".',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => "Enquirer's full name."],
                'mobile' => ['type' => 'string', 'description' => 'Contact phone number.'],
                'email' => ['type' => 'string', 'format' => 'email', 'description' => 'Contact email address.'],
                'suburb' => ['type' => 'string', 'description' => 'Job location suburb.'],
                'contact_preference' => ['type' => 'string', 'description' => 'e.g. "Phone" or "Email".'],
                'job' => ['type' => 'string', 'description' => 'e.g. "Household rubbish removal".'],
                'details' => ['type' => 'string', 'description' => 'Free-text description of the job.'],
            ],
            'anyOf' => [
                ['required' => ['name']],
                ['required' => ['mobile']],
                ['required' => ['email']],
            ],
        ],
    ];
}

function tool_result($id, string $text, bool $isError): void
{
    result_response($id, [
        'resultType' => 'complete',
        'content' => [['type' => 'text', 'text' => $text]],
        'isError' => $isError,
    ]);
}

function handle_tools_call($id, array $params, ?string $nameHeader): void
{
    $toolName = $params['name'] ?? null;
    if ($toolName !== TOOL_NAME) {
        send_error($id, 400, -32602, 'Unknown tool: ' . (is_string($toolName) ? $toolName : '(missing)'));
    }
    if ($nameHeader === null) {
        send_error($id, 400, -32020, 'HeaderMismatch: Mcp-Name header is required for tools/call');
    }
    if ($nameHeader !== $toolName) {
        send_error($id, 400, -32020, "HeaderMismatch: Mcp-Name header value '{$nameHeader}' does not match body params.name '{$toolName}'");
    }

    $args = $params['arguments'] ?? [];
    if (!is_array($args)) {
        $args = [];
    }

    $fields = [
        'name'               => clean_field((string) ($args['name'] ?? '')),
        'mobile'             => clean_field((string) ($args['mobile'] ?? '')),
        'email'              => clean_field((string) ($args['email'] ?? '')),
        'suburb'             => clean_field((string) ($args['suburb'] ?? '')),
        'contact_preference' => clean_field((string) ($args['contact_preference'] ?? '')),
        'job'                => clean_field((string) ($args['job'] ?? '')),
        'details'            => trim((string) ($args['details'] ?? '')),
    ];

    if ($fields['name'] === '' && $fields['mobile'] === '' && $fields['email'] === '') {
        tool_result($id, 'At least one of "name", "mobile", or "email" is required to submit this enquiry.', true);
    }

    $config = @include __DIR__ . '/config.php';
    if (!is_array($config)) {
        error_log('WasteMates MCP server: config.php missing or invalid');
        tool_result($id, 'The enquiry could not be delivered — the site is misconfigured. Please try https://wastemates.com.au/contact.html instead.', true);
    }

    $result = wm_send_enquiry_notifications($config, $fields, 'MCP agent', []);
    $delivered = $result['email_sent'] || $result['telegram_sent'] || $result['discord_sent'];

    if (!$delivered) {
        tool_result($id, 'The enquiry could not be delivered through any channel. Please try https://wastemates.com.au/contact.html instead.', true);
    }

    tool_result($id, 'Thanks — the WasteMates team will be in touch soon.', false);
}

// ── Request parsing and validation ──────────────────────────────────────────

$protoHeader = get_header('MCP-Protocol-Version');
$methodHeader = get_header('Mcp-Method');
$nameHeader = get_header('Mcp-Name');

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body)) {
    send_error(null, 400, -32700, 'Parse error: request body must be valid JSON');
}

if (!array_key_exists('id', $body)) {
    // This server implements no notification methods, so any notification
    // (a body with no "id") is something it "cannot accept" — the transport
    // spec allows an HTTP error with a JSON-RPC error body carrying no id.
    http_response_code(400);
    echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32601, 'message' => 'This server does not accept notifications']]);
    exit;
}

$id = $body['id'];

if (($body['jsonrpc'] ?? null) !== '2.0' || !isset($body['method']) || !is_string($body['method'])) {
    send_error($id, 400, -32600, 'Invalid Request: expected a JSON-RPC 2.0 request with "method"');
}
$method = $body['method'];

$params = $body['params'] ?? null;
if (!is_array($params)) {
    $params = [];
}

$meta = $params['_meta'] ?? null;
$bodyProtocolVersion = is_array($meta) ? ($meta['io.modelcontextprotocol/protocolVersion'] ?? null) : null;
$clientCapabilities = is_array($meta) ? ($meta['io.modelcontextprotocol/clientCapabilities'] ?? null) : null;

if ($protoHeader === null || $methodHeader === null) {
    send_error($id, 400, -32020, 'HeaderMismatch: MCP-Protocol-Version and Mcp-Method headers are required');
}
if (!is_string($bodyProtocolVersion) || !is_array($clientCapabilities)) {
    send_error($id, 400, -32602, 'Invalid params: params._meta must include "io.modelcontextprotocol/protocolVersion" and "io.modelcontextprotocol/clientCapabilities"');
}
if ($methodHeader !== $method) {
    send_error($id, 400, -32020, "HeaderMismatch: Mcp-Method header value '{$methodHeader}' does not match body method '{$method}'");
}
if ($protoHeader !== $bodyProtocolVersion) {
    send_error($id, 400, -32020, "HeaderMismatch: MCP-Protocol-Version header value '{$protoHeader}' does not match body _meta value '{$bodyProtocolVersion}'");
}
if ($bodyProtocolVersion !== MCP_PROTOCOL_VERSION) {
    send_error($id, 400, -32022, "Unsupported protocol version: {$bodyProtocolVersion}", ['supported' => [MCP_PROTOCOL_VERSION]]);
}

// ── Dispatch ─────────────────────────────────────────────────────────────

switch ($method) {
    case 'server/discover':
        result_response($id, [
            'resultType' => 'complete',
            'supportedVersions' => [MCP_PROTOCOL_VERSION],
            'capabilities' => ['tools' => (object) []],
            'instructions' => 'Use the submit_enquiry tool to submit a WasteMates rubbish-removal quote enquiry on a visitor\'s behalf.',
        ]);
        break;

    case 'tools/list':
        result_response($id, [
            'resultType' => 'complete',
            'tools' => [tool_definition()],
        ]);
        break;

    case 'tools/call':
        handle_tools_call($id, $params, $nameHeader);
        break;

    default:
        send_error($id, 404, -32601, "Method not found: {$method}");
}
