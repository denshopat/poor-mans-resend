<?php
/**
 * Notification to site admin when a contact form is submitted.
 *
 * Expected $data keys:
 *   name           - sender's name (string, required)
 *   email          - sender's email (string, required)
 *   message        - body text (string, required)
 *   phone          - phone number (string, optional)
 *   subject_line   - their stated subject (string, optional)
 */

$name         = htmlspecialchars((string)($data['name']         ?? 'Unknown'), ENT_QUOTES, 'UTF-8');
$email        = htmlspecialchars((string)($data['email']        ?? ''),        ENT_QUOTES, 'UTF-8');
$phone        = htmlspecialchars((string)($data['phone']        ?? ''),        ENT_QUOTES, 'UTF-8');
$subjectLine  = htmlspecialchars((string)($data['subject_line'] ?? ''),        ENT_QUOTES, 'UTF-8');
$messageHtml  = nl2br(htmlspecialchars((string)($data['message'] ?? ''), ENT_QUOTES, 'UTF-8'));

$html = <<<HTML
<!doctype html>
<html><body style="font-family:system-ui,sans-serif;color:#222;line-height:1.5">
  <h2 style="margin:0 0 12px">New contact form submission</h2>
  <p style="margin:0 0 8px"><strong>Name:</strong> {$name}<br>
  <strong>Email:</strong> {$email}<br>
  <strong>Phone:</strong> {$phone}<br>
  <strong>Subject:</strong> {$subjectLine}</p>
  <hr>
  <p style="white-space:pre-wrap">{$messageHtml}</p>
</body></html>
HTML;

$text = "New contact form submission\n"
      . "Name: "    . ($data['name']         ?? '') . "\n"
      . "Email: "   . ($data['email']        ?? '') . "\n"
      . "Phone: "   . ($data['phone']        ?? '') . "\n"
      . "Subject: " . ($data['subject_line'] ?? '') . "\n"
      . "---\n"
      . ($data['message'] ?? '') . "\n";

return ['html' => $html, 'text' => $text];
