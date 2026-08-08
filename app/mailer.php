<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

/**
 * DKIM-signs the message when a private key exists for the From domain.
 * Multi-tenant by construction: the key is looked up per sending domain
 * ($keysDir/<domain>.pem), so tenants without a key send unsigned exactly
 * as before — never signed with another tenant's key. Signing here (rather
 * than trusting the MTA) survives smarthost routes that don't sign, e.g.
 * cPanel+SpamExperts where exim hands off unsigned.
 */
function mailer_apply_dkim(PHPMailer $mail, array $dkimConfig, string $fromEmail): void
{
    if (empty($dkimConfig['keys_dir']) || empty($dkimConfig['selector'])) {
        return;
    }
    $domain = strtolower(substr(strrchr($fromEmail, '@') ?: '', 1));
    if ($domain === '') {
        return;
    }
    $keyFile = rtrim($dkimConfig['keys_dir'], '/') . '/' . basename($domain) . '.pem';
    if (!is_file($keyFile)) {
        return;
    }
    $mail->DKIM_domain   = $domain;
    $mail->DKIM_selector = $dkimConfig['selector'];
    $mail->DKIM_private  = $keyFile;
    $mail->DKIM_identity = $fromEmail;
}

/**
 * Sends an email through the configured SMTP server.
 * Returns [success, smtp_response, message_id, error_message].
 */
function mailer_send(array $smtpConfig, array $msg, array $dkimConfig = []): array
{
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $smtpConfig['host'];
        $mail->Port       = (int)$smtpConfig['port'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpConfig['username'];
        $mail->Password   = $smtpConfig['password'];
        $mail->SMTPSecure = ($smtpConfig['encryption'] ?? 'ssl') === 'tls'
            ? PHPMailer::ENCRYPTION_STARTTLS
            : PHPMailer::ENCRYPTION_SMTPS;
        $mail->Timeout    = (int)($smtpConfig['timeout'] ?? 30);
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($msg['from_email'], $msg['from_name'] ?? '');
        mailer_apply_dkim($mail, $dkimConfig, $msg['from_email']);
        $mail->addAddress($msg['to']);
        if (!empty($msg['reply_to'])) {
            $mail->addReplyTo($msg['reply_to']);
        }
        $mail->Subject = $msg['subject'];
        $mail->isHTML(true);
        $mail->Body    = $msg['html'];
        $mail->AltBody = $msg['text'];
        mailer_add_attachments($mail, $msg['attachments'] ?? []);

        $mail->send();

        return [
            'success'       => true,
            'smtp_response' => trim((string)$mail->ErrorInfo) ?: 'OK',
            'message_id'    => trim($mail->getLastMessageID(), '<>') ?: null,
            'error_message' => null,
        ];
    } catch (MailerException $e) {
        return [
            'success'       => false,
            'smtp_response' => trim((string)$mail->ErrorInfo) ?: null,
            'message_id'    => null,
            'error_message' => $e->getMessage(),
        ];
    }
}

/**
 * Sends the same message body to many recipients over a single authenticated
 * SMTP connection (SMTPKeepAlive). One TCP+TLS+AUTH handshake amortised
 * across the whole batch — essential for newsletter-shaped workloads.
 *
 * $perRecipient is called as ($index, $recipient, $result) after each send,
 * letting the caller update its log row with real-time status.
 *
 * Returns an array of per-recipient results (same shape as mailer_send),
 * indexed positionally to $recipients.
 */
function mailer_send_many(array $smtpConfig, array $msg, array $recipients, ?callable $perRecipient = null, array $dkimConfig = []): array
{
    $mail = new PHPMailer(true);
    $results = [];

    try {
        $mail->isSMTP();
        $mail->Host          = $smtpConfig['host'];
        $mail->Port          = (int)$smtpConfig['port'];
        $mail->SMTPAuth      = true;
        $mail->Username      = $smtpConfig['username'];
        $mail->Password      = $smtpConfig['password'];
        $mail->SMTPSecure    = ($smtpConfig['encryption'] ?? 'ssl') === 'tls'
            ? PHPMailer::ENCRYPTION_STARTTLS
            : PHPMailer::ENCRYPTION_SMTPS;
        $mail->Timeout       = (int)($smtpConfig['timeout'] ?? 30);
        $mail->CharSet       = 'UTF-8';
        $mail->SMTPKeepAlive = true;

        $mail->setFrom($msg['from_email'], $msg['from_name'] ?? '');
        mailer_apply_dkim($mail, $dkimConfig, $msg['from_email']);
        if (!empty($msg['reply_to'])) {
            $mail->addReplyTo($msg['reply_to']);
        }
        $mail->Subject = $msg['subject'];
        $mail->isHTML(true);
        $mail->Body    = $msg['html'];
        $mail->AltBody = $msg['text'];
        // Attachments survive clearAddresses() in the fan-out loop, so adding
        // them once here covers every recipient in the batch.
        mailer_add_attachments($mail, $msg['attachments'] ?? []);
    } catch (MailerException $e) {
        // Couldn't even build the message — fail every recipient identically.
        foreach ($recipients as $i => $rcpt) {
            $result = [
                'success'       => false,
                'smtp_response' => null,
                'message_id'    => null,
                'error_message' => 'Mailer init failed: ' . $e->getMessage(),
            ];
            $results[$i] = $result;
            if ($perRecipient) {
                $perRecipient($i, $rcpt, $result);
            }
        }
        return $results;
    }

    foreach ($recipients as $i => $rcpt) {
        try {
            $mail->clearAddresses();
            $mail->addAddress($rcpt);
            $mail->send();
            $result = [
                'success'       => true,
                'smtp_response' => trim((string)$mail->ErrorInfo) ?: 'OK',
                'message_id'    => trim($mail->getLastMessageID(), '<>') ?: null,
                'error_message' => null,
            ];
        } catch (MailerException $e) {
            $result = [
                'success'       => false,
                'smtp_response' => trim((string)$mail->ErrorInfo) ?: null,
                'message_id'    => null,
                'error_message' => $e->getMessage(),
            ];
        }
        $results[$i] = $result;
        if ($perRecipient) {
            $perRecipient($i, $rcpt, $result);
        }
    }

    $mail->smtpClose();
    return $results;
}

/**
 * Adds validated attachments (raw bytes, already base64-decoded by the
 * validator) to a PHPMailer instance. An empty content_type lets PHPMailer
 * infer the MIME type from the filename extension.
 */
function mailer_add_attachments(PHPMailer $mail, array $attachments): void
{
    foreach ($attachments as $att) {
        $mail->addStringAttachment(
            $att['content'],
            $att['filename'],
            PHPMailer::ENCODING_BASE64,
            $att['content_type']
        );
    }
}

/**
 * Renders a template file with the given $data and $subject. The template
 * must return an array with 'html' and 'text' keys.
 *
 * Template names are validated against the allowlist before this is called,
 * but basename() is used as belt-and-braces against path-traversal.
 */
function render_template(string $templatesDir, string $template, array $data, string $subject): array
{
    $file = $templatesDir . '/' . basename($template) . '.php';
    if (!is_file($file)) {
        throw new RuntimeException('Template file missing: ' . $template);
    }
    $result = (static function (string $__file, array $data, string $subject) {
        return require $__file;
    })($file, $data, $subject);

    if (!is_array($result) || !isset($result['html'], $result['text'])) {
        throw new RuntimeException('Template did not return html/text array: ' . $template);
    }
    return $result;
}
