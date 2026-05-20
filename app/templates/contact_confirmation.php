<?php
/**
 * Confirmation sent back to the person who submitted a contact form.
 *
 * Expected $data keys:
 *   name      - recipient's name (string, optional, defaults to "there")
 *   message   - the message we want to echo back (string, optional)
 */

$name       = htmlspecialchars((string)($data['name']    ?? 'there'), ENT_QUOTES, 'UTF-8');
$messageHtml = nl2br(htmlspecialchars((string)($data['message'] ?? ''), ENT_QUOTES, 'UTF-8'));

$plainName  = (string)($data['name']    ?? 'there');
$plainMsg   = (string)($data['message'] ?? '');

$html = <<<HTML
<!doctype html>
<html><body style="font-family:system-ui,sans-serif;color:#222;line-height:1.5">
  <p>Hi {$name},</p>
  <p style="white-space:pre-wrap">{$messageHtml}</p>
  <p>We'll be in touch soon.</p>
</body></html>
HTML;

$text = "Hi {$plainName},\n\n{$plainMsg}\n\nWe'll be in touch soon.\n";

return ['html' => $html, 'text' => $text];
