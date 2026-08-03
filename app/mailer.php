<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

/**
 * Sends an email through the configured SMTP server.
 * Returns [success, smtp_response, message_id, error_message].
 */
function mailer_send(array $smtpConfig, array $msg): array
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
function mailer_send_many(array $smtpConfig, array $msg, array $recipients, ?callable $perRecipient = null): array
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
