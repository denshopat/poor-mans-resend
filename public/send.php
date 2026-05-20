<?php
declare(strict_types=1);

header('Content-Type: application/json');

// Where the app/ directory lives. Default assumes public/ and app/ are
// siblings (the dev layout). For cPanel deployments, set MAIL_API_ROOT
// via .htaccess SetEnv to point at the app dir outside public_html, e.g.:
//   SetEnv MAIL_API_ROOT /home/USERNAME/mail-api
$appRoot = getenv('MAIL_API_ROOT') ?: dirname(__DIR__);

require $appRoot . '/vendor/autoload.php';
require $appRoot . '/app/db.php';
require $appRoot . '/app/auth.php';
require $appRoot . '/app/validator.php';
require $appRoot . '/app/logger.php';
require $appRoot . '/app/mailer.php';

if (!is_file($appRoot . '/app/config.php')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server misconfigured']);
    exit;
}
$config = require $appRoot . '/app/config.php';

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['success' => false, 'error' => 'Method not allowed']);
}

// Some Apache + cPanel setups strip the Authorization header from CGI/FastCGI
// requests; fall back to the REDIRECT_ variant when the direct one is empty.
$authHeader = $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? '';

$sourceApp = auth_resolve_source_app($authHeader, $config['api_keys']);
if ($sourceApp === null) {
    respond(401, ['success' => false, 'error' => 'Unauthorized']);
}

$maxBytes      = (int)($config['max_body_bytes'] ?? 102400);
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > $maxBytes) {
    respond(413, ['success' => false, 'error' => 'Payload too large']);
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > $maxBytes) {
    respond(413, ['success' => false, 'error' => 'Payload too large']);
}

try {
    $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    respond(400, [
        'success' => false,
        'error'   => 'Invalid JSON',
        'details' => ['body' => $e->getMessage()],
    ]);
}
if (!is_array($payload)) {
    respond(400, [
        'success' => false,
        'error'   => 'Invalid JSON',
        'details' => ['body' => 'Expected JSON object'],
    ]);
}

[$msg, $errors] = validate_payload($payload, $sourceApp, $config);
if ($errors) {
    respond(400, [
        'success' => false,
        'error'   => 'Validation failed',
        'details' => $errors,
    ]);
}

try {
    $db = db_connect($config['database']);
} catch (Throwable $e) {
    respond(500, ['success' => false, 'error' => 'Database connection failed']);
}

$remoteIp = $_SERVER['REMOTE_ADDR'] ?? null;

$logId = log_insert($db, [
    'source_app'     => $sourceApp,
    'template'       => $msg['template'] ?? 'passthrough',
    'from_email'     => $msg['from_email'],
    'to_email'       => $msg['to'],
    'reply_to_email' => $msg['reply_to'],
    'subject'        => $msg['subject'],
    'status'         => 'queued',
    'request_json'   => $raw,
    'remote_ip'      => $remoteIp,
]);

if ($msg['html'] !== null) {
    // Passthrough mode — caller owns templating.
    $rendered = ['html' => $msg['html'], 'text' => $msg['text'] ?? ''];
} else {
    try {
        $rendered = render_template(
            $appRoot . '/app/templates',
            $msg['template'],
            $msg['data'],
            $msg['subject']
        );
    } catch (Throwable $e) {
        log_update($db, $logId, [
            'status'        => 'failed',
            'error_message' => 'Template render error: ' . $e->getMessage(),
        ]);
        respond(500, [
            'success' => false,
            'id'      => $logId,
            'error'   => 'Template render failed',
        ]);
    }
}

log_update($db, $logId, ['status' => 'sending']);

$result = mailer_send($config['smtp'], [
    'from_email' => $msg['from_email'],
    'from_name'  => $msg['from_name'],
    'to'         => $msg['to'],
    'reply_to'   => $msg['reply_to'],
    'subject'    => $msg['subject'],
    'html'       => $rendered['html'],
    'text'       => $rendered['text'],
]);

if ($result['success']) {
    log_update($db, $logId, [
        'status'        => 'sent',
        'sent_at'       => date('Y-m-d H:i:s'),
        'smtp_response' => $result['smtp_response'],
        'message_id'    => $result['message_id'],
    ]);
    respond(200, [
        'success' => true,
        'id'      => $logId,
        'status'  => 'sent',
        'message' => 'Email sent',
    ]);
}

log_update($db, $logId, [
    'status'        => 'failed',
    'error_message' => $result['error_message'],
    'smtp_response' => $result['smtp_response'],
]);
respond(502, [
    'success' => false,
    'id'      => $logId,
    'status'  => 'failed',
    'error'   => 'SMTP send failed',
    'details' => ['smtp' => $result['error_message']],
]);
