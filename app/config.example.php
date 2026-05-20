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
    'allowed_templates' => [
        'contact_admin',
        'contact_confirmation',
        'generic_notification',
    ],

    // Reject request bodies larger than this many bytes (defence-in-depth;
    // also configure post_max_size in php.ini for the cPanel account).
    'max_body_bytes' => 102400, // 100 KB

];
