<?php
declare(strict_types=1);

// Copy this file to config.php and fill in real values.
// config.php is gitignored — never commit credentials.

return [

    'database' => [
        'host'    => 'localhost',
        'name'    => 'cpaneluser_maildb',
        'user'    => 'cpaneluser_mailuser',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],

    'smtp' => [
        'host'       => 'mail.example.com',
        'port'       => 465,
        'username'   => 'office@example.com',
        'password'   => 'CHANGE_ME',
        'encryption' => 'ssl',   // 'ssl' (465) or 'tls' (587)
        'timeout'    => 30,
    ],

    // Each API key maps to a source_app identifier. The bearer token in the
    // Authorization header determines which FCS instance is calling — the
    // body's source_app is cross-checked but not trusted.
    //
    // Generate keys with:  php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"
    'api_keys' => [
        'REPLACE_WITH_RANDOM_HEX_1' => 'honeyshop',
        // 'REPLACE_WITH_RANDOM_HEX_2' => 'clientb',
    ],

    // Per-source_app sender allowlist. Entries starting with @ match any
    // local-part on that domain; entries without @ at the start must be exact.
    'sender_allowlist' => [
        'honeyshop' => ['@honeymonster.com.au'],
        // 'clientb' => ['office@clientb.example', '@clientb.example'],
    ],

    // Templates that may be requested. Each must correspond to a file
    // app/templates/<name>.php that returns ['html' => ..., 'text' => ...].
    // Used only when the calling FCS app requests a server-side rendered
    // template by name. The preferred path is passthrough (FCS sends
    // pre-rendered `html` + `text` directly), in which case this list is
    // ignored. The remaining entries are legacy contact-form templates.
    'allowed_templates' => [
        'contact_admin',
        'contact_confirmation',
        'generic_notification',
    ],

    // Reject request bodies larger than this many bytes (defence-in-depth;
    // also configure post_max_size in php.ini for the cPanel account).
    // Attachments arrive base64-encoded inside the JSON body (~1.37x their
    // real size), so this must be comfortably larger than
    // max_attachment_total_bytes. If you don't use attachments, drop this
    // back to 102400 (100 KB).
    'max_body_bytes' => 15728640, // 15 MB

    // Maximum number of attachments per request.
    'max_attachments' => 10,

    // Combined *decoded* size of all attachments per request. Keep this
    // under your SMTP host's message size limit (cPanel/exim commonly caps
    // messages at 10-50 MB — check with your host) minus headroom for the
    // MIME re-encoding of the message body.
    'max_attachment_total_bytes' => 10485760, // 10 MB

    // Maximum recipients per request when `to` is an array. Fan-out beyond
    // this number is rejected at validation. Raise cautiously — synchronous
    // SMTP sends scale linearly with this value, and very large bursts can
    // trip per-hour mailbox limits on the cPanel host. For real bulk
    // newsletters, batch on the caller side or build a dedicated queue.
    'max_recipients' => 100,

];
