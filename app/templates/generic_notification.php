<?php
/**
 * Generic single-message notification.
 *
 * Expected $data keys:
 *   name     - recipient's name (string, optional, defaults to "there")
 *   message  - body text (string, required for anything useful to land)
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
</body></html>
HTML;

$text = "Hi {$plainName},\n\n{$plainMsg}\n";

return ['html' => $html, 'text' => $text];
