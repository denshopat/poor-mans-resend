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

// Render the template once (or use passthrough html) — the body is identical
// across every recipient in a fan-out batch.
if ($msg['html'] !== null) {
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
        // No log row exists yet — render failed before we could insert.
        // Old behaviour was to insert-then-fail; we now fail earlier and
        // surface the error without a phantom log row.
        respond(500, [
            'success' => false,
            'error'   => 'Template render failed',
            'details' => ['template' => $e->getMessage()],
        ]);
    }
}

// Attachment bytes would balloon email_log (base64 in request_json), so the
// logged payload replaces each attachment's content with its metadata. The
// original $raw is still what was validated and sent.
$rawForLog = $raw;
if ($msg['attachments'] !== []) {
    $loggedPayload = $payload;
    $loggedPayload['attachments'] = array_map(
        static fn(array $att) => [
            'filename'     => $att['filename'],
            'content_type' => $att['content_type'] !== '' ? $att['content_type'] : null,
            'size'         => strlen($att['content']),
        ],
        $msg['attachments']
    );
    $rawForLog = json_encode($loggedPayload, JSON_UNESCAPED_SLASHES);
}

$recipients = $msg['to'];   // validator guarantees: non-empty string[]
$isBatch    = count($recipients) > 1;
$batchId    = $isBatch ? uuid_v4() : null;
$batchSize  = $isBatch ? count($recipients) : null;

// Insert one queued row per recipient. Only the first row carries the full
// request_json — for a 1000-recipient newsletter, repeating it on every
// row would balloon disk usage and the payload is identical anyway.
$logIds = [];
foreach ($recipients as $i => $rcpt) {
    $logIds[$i] = log_insert($db, [
        'batch_id'       => $batchId,
        'batch_size'     => $batchSize,
        'batch_index'    => $isBatch ? $i : null,
        'source_app'     => $sourceApp,
        'template'       => $msg['template'] ?? 'passthrough',
        'from_email'     => $msg['from_email'],
        'to_email'       => $rcpt,
        'reply_to_email' => $msg['reply_to'],
        'subject'        => $msg['subject'],
        'status'         => 'queued',
        'request_json'   => $i === 0 ? $rawForLog : null,
        'remote_ip'      => $remoteIp,
    ]);
}

// Fan-out send. SMTPKeepAlive in mailer_send_many keeps a single
// authenticated connection open across all recipients; the callback
// updates each row's status as soon as its SMTP call returns.
$results = mailer_send_many(
    $config['smtp'],
    [
        'from_email'  => $msg['from_email'],
        'from_name'   => $msg['from_name'],
        'reply_to'    => $msg['reply_to'],
        'subject'     => $msg['subject'],
        'html'        => $rendered['html'],
        'text'        => $rendered['text'],
        'attachments' => $msg['attachments'],
    ],
    $recipients,
    function (int $i, string $rcpt, array $result) use ($db, $logIds) {
        if ($result['success']) {
            log_update($db, $logIds[$i], [
                'status'        => 'sent',
                'sent_at'       => date('Y-m-d H:i:s'),
                'smtp_response' => $result['smtp_response'],
                'message_id'    => $result['message_id'],
            ]);
        } else {
            log_update($db, $logIds[$i], [
                'status'        => 'failed',
                'error_message' => $result['error_message'],
                'smtp_response' => $result['smtp_response'],
            ]);
        }
    }
);

// Single-recipient response shape is unchanged — same keys, same status codes.
if (!$isBatch) {
    $r = $results[0];
    if ($r['success']) {
        respond(200, [
            'success' => true,
            'id'      => $logIds[0],
            'status'  => 'sent',
            'message' => 'Email sent',
        ]);
    }
    respond(502, [
        'success' => false,
        'id'      => $logIds[0],
        'status'  => 'failed',
        'error'   => 'SMTP send failed',
        'details' => ['smtp' => $r['error_message']],
    ]);
}

// Batch response: per-recipient breakdown + summary. 200 if all sent,
// 207 (Multi-Status) if partial, 502 if every recipient failed.
$sent   = 0;
$failed = 0;
$breakdown = [];
foreach ($recipients as $i => $rcpt) {
    $r = $results[$i];
    if ($r['success']) {
        $sent++;
        $breakdown[] = [
            'to'         => $rcpt,
            'id'         => $logIds[$i],
            'status'     => 'sent',
            'message_id' => $r['message_id'],
        ];
    } else {
        $failed++;
        $breakdown[] = [
            'to'     => $rcpt,
            'id'     => $logIds[$i],
            'status' => 'failed',
            'error'  => $r['error_message'],
        ];
    }
}

$status = $failed === 0 ? 200 : ($sent === 0 ? 502 : 207);
respond($status, [
    'success'  => $failed === 0,
    'batch_id' => $batchId,
    'count'    => count($recipients),
    'summary'  => ['sent' => $sent, 'failed' => $failed],
    'results'  => $breakdown,
]);

/** RFC 4122 v4 UUID using cryptographically secure random bytes. */
function uuid_v4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
