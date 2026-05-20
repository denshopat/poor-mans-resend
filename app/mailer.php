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
