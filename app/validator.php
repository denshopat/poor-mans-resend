<?php
declare(strict_types=1);

/**
 * Validates the decoded JSON payload. Returns [normalized_payload, errors].
 * If errors is non-empty the normalized payload should not be used.
 *
 * The trusted source_app comes from the API key, not from the request body.
 */
function validate_payload(array $payload, string $sourceApp, array $config): array
{
    $errors = [];
    $out = [
        'source_app' => $sourceApp,
        'template'   => null,
        'html'       => null,
        'text'       => null,
        'to'         => null,
        'from_name'  => null,
        'from_email' => null,
        'reply_to'   => null,
        'subject'    => null,
        'data'       => [],
    ];

    if (isset($payload['source_app']) && $payload['source_app'] !== $sourceApp) {
        $errors['source_app'] = 'Does not match API key';
    }

    // Two send modes:
    //   (a) Server-side template — caller passes `template` + `data` and the
    //       relay renders the matching app/templates/<name>.php file.
    //   (b) Passthrough — caller passes pre-rendered `html` (required) and
    //       optionally `text` directly. Used when the FCS app owns templating
    //       (admin-editable DB-backed templates) and treats mail-api as a
    //       dumb transport.
    //
    // Exactly one of {template, html} must be present.
    $template = $payload['template'] ?? null;
    $htmlBody = $payload['html']     ?? null;

    if (is_string($template) && $template !== '') {
        if (!in_array($template, $config['allowed_templates'], true)) {
            $errors['template'] = 'Unknown template';
        } else {
            $out['template'] = $template;
        }
        if ($htmlBody !== null) {
            $errors['html'] = 'Cannot combine html with template';
        }
    } elseif (is_string($htmlBody) && $htmlBody !== '') {
        $out['html'] = $htmlBody;
        if (isset($payload['text'])) {
            if (!is_string($payload['text'])) {
                $errors['text'] = 'Must be a string';
            } else {
                $out['text'] = $payload['text'];
            }
        }
    } else {
        $errors['template'] = 'Either template or html is required';
    }

    $to = $payload['to'] ?? null;
    if (!is_string($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $errors['to'] = 'Invalid email address';
    } else {
        $out['to'] = $to;
    }

    $from = $payload['from'] ?? null;
    if (!is_string($from) || $from === '') {
        $errors['from'] = 'Required';
    } else {
        [$fromName, $fromEmail] = parse_address($from);
        if (!$fromEmail || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $errors['from'] = 'Invalid sender';
        } elseif (!sender_allowed($fromEmail, $sourceApp, $config['sender_allowlist'])) {
            $errors['from'] = 'Sender not in allowlist for this source_app';
        } else {
            $out['from_name']  = $fromName;
            $out['from_email'] = $fromEmail;
        }
    }

    if (isset($payload['reply_to'])) {
        $rt = $payload['reply_to'];
        if (!is_string($rt) || !filter_var($rt, FILTER_VALIDATE_EMAIL)) {
            $errors['reply_to'] = 'Invalid email address';
        } else {
            $out['reply_to'] = $rt;
        }
    }

    $subject = $payload['subject'] ?? null;
    if (!is_string($subject) || $subject === '') {
        $errors['subject'] = 'Required';
    } elseif (strlen($subject) > 500) {
        $errors['subject'] = 'Too long (max 500 chars)';
    } else {
        $out['subject'] = $subject;
    }

    if (isset($payload['data'])) {
        if (!is_array($payload['data'])) {
            $errors['data'] = 'Must be an object';
        } else {
            $out['data'] = $payload['data'];
        }
    }

    return [$out, $errors];
}

/** Parses "Name <email@host>" or bare "email@host" into [name|null, email]. */
function parse_address(string $input): array
{
    $input = trim($input);
    if (preg_match('/^(.*)<([^>]+)>\s*$/', $input, $m)) {
        return [trim($m[1], " \t\"'"), trim($m[2])];
    }
    return [null, $input];
}

function sender_allowed(string $email, string $sourceApp, array $allowlist): bool
{
    $entries = $allowlist[$sourceApp] ?? [];
    $emailDomain = strtolower(substr(strrchr($email, '@') ?: '', 1));
    foreach ($entries as $entry) {
        if (str_starts_with($entry, '@')) {
            if ($emailDomain !== '' && $emailDomain === strtolower(substr($entry, 1))) {
                return true;
            }
        } elseif (strcasecmp($entry, $email) === 0) {
            return true;
        }
    }
    return false;
}
