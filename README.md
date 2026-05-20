# mail-api

Lightweight transactional email relay. FCS instances POST JSON jobs; this
service authenticates, validates, logs to MySQL, and sends via authenticated
SMTP using PHPMailer. Designed to run on a cPanel/Verpex account whose
mailboxes are the actual sending identities.

This is intentionally not part of the FCS Node/TS monolith — it lives on
separate infrastructure (the cPanel mail host) so it can use the platform's
native PHP runtime without dragging PHP into the main app.

## Layout

```
mail-api/
  public/
    send.php          # the only file exposed via HTTP
    .htaccess         # blocks everything except send.php
  app/                # NOT web-reachable in production
    config.example.php
    config.php        # gitignored; you create this on deploy
    db.php
    auth.php
    validator.php
    logger.php
    mailer.php
    templates/
      contact_admin.php
      contact_confirmation.php
      generic_notification.php
    .htaccess         # belt-and-braces deny-all if app/ ends up under public_html
  sql/
    schema.sql
  composer.json
  README.md
```

## Install

On the cPanel server:

```bash
cd ~/mail-api          # or wherever you uploaded the repo
composer install --no-dev --optimize-autoloader
```

If Composer is unavailable, drop `phpmailer/phpmailer` ^6.9 into `vendor/`
manually and create an autoloader, but installing Composer is far simpler —
cPanel usually offers it via "Setup Site → Composer" or you can grab the PHAR.

## Database

Create a MySQL database and user in cPanel, then load the schema:

```bash
mysql -u CPANELUSER_mailuser -p CPANELUSER_maildb < sql/schema.sql
```

### Applying migrations to an existing deployment

For deployments that pre-date the fan-out columns, apply migrations from
`sql/migrations/` in numerical order:

```bash
mysql -u CPANELUSER_mailuser -p CPANELUSER_maildb \
  < sql/migrations/001_add_batch_columns.sql
```

Each migration is additive and safe to apply against rows written by older
versions — existing single-recipient rows simply leave the new columns NULL.

## Configure

```bash
cp app/config.example.php app/config.php
$EDITOR app/config.php
```

Fill in:

- **database** — DB name/user/pass from cPanel
- **smtp** — host (usually `mail.yourdomain.com`), port (465 for SSL or 587 for
  TLS), the mailbox username and password
- **api_keys** — map of bearer-token → `source_app`. Generate keys with
  `php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"`. One key per FCS instance.
- **sender_allowlist** — which `from` addresses each `source_app` is allowed
  to send as. Entries starting with `@` match any local-part on that domain.
- **allowed_templates** — names of template files that may be requested.

Keep `app/config.php` outside git — `.gitignore` already excludes it.

## Deploy on cPanel

**Recommended layout** — put `app/` outside `public_html` so it cannot be
served as raw PHP if a config goes wrong:

```
/home/USERNAME/mail-api/                    # the app/ side
    app/
    sql/
    vendor/
    composer.json
/home/USERNAME/public_html/mail-api/        # the public/ side
    send.php
    .htaccess
```

Edit `public_html/mail-api/.htaccess` (or your account-level `.htaccess`) to
set the env var that tells `send.php` where the app directory lives:

```apache
SetEnv MAIL_API_ROOT /home/USERNAME/mail-api
```

**Fallback layout** — if your host insists everything must live under
`public_html`, deploy the whole repo as `public_html/mail-api/`. The
`app/.htaccess` shipped in this repo will deny direct access to it, but
deploying outside `public_html` remains safer.

After deploying, the endpoint is:

```
https://yourdomain.example/mail-api/send.php
```

## Test with curl

```bash
curl -X POST "https://yourdomain.example/mail-api/send.php" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_SECRET_API_KEY" \
  -d '{
    "source_app": "honeyshop",
    "template": "generic_notification",
    "to": "client@example.com",
    "from": "Office <office@honeymonster.com.au>",
    "reply_to": "office@honeymonster.com.au",
    "subject": "Test email",
    "data": {
      "name": "Patrick",
      "message": "This is a test email from the mail API."
    }
  }'
```

Expected success response:

```json
{ "success": true, "id": 123, "status": "sent", "message": "Email sent" }
```

Expected validation-failure shape:

```json
{
  "success": false,
  "error": "Validation failed",
  "details": { "to": "Invalid email address" }
}
```

## Sending to multiple recipients (fan-out)

`to` accepts either a single email string (legacy shape, single SMTP send,
one `email_log` row) or an array of email strings. With an array, the relay
fans out: one SMTP send and one `email_log` row per recipient, all linked
by a generated `batch_id`. Recipients never appear in each other's `To:`
headers — each recipient sees only their own address.

```bash
curl -X POST "https://yourdomain.example/mail-api/send.php" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_SECRET_API_KEY" \
  -d '{
    "to": ["a@example.com", "b@example.com", "c@example.com"],
    "from": "Office <office@honeymonster.com.au>",
    "subject": "Multi-recipient test",
    "html": "<p>Hello</p>"
  }'
```

Batch success response (all recipients delivered, HTTP 200):

```json
{
  "success": true,
  "batch_id": "1c5b8e8a-4a3f-4f78-9a14-2c5b1d8d3f01",
  "count": 3,
  "summary": { "sent": 3, "failed": 0 },
  "results": [
    { "to": "a@example.com", "id": 124, "status": "sent", "message_id": "<...>" },
    { "to": "b@example.com", "id": 125, "status": "sent", "message_id": "<...>" },
    { "to": "c@example.com", "id": 126, "status": "sent", "message_id": "<...>" }
  ]
}
```

Partial-failure response (some sent, some failed, HTTP 207):

```json
{
  "success": false,
  "batch_id": "1c5b8e8a-...",
  "count": 3,
  "summary": { "sent": 2, "failed": 1 },
  "results": [
    { "to": "a@example.com", "id": 124, "status": "sent",   "message_id": "<...>" },
    { "to": "b@example.com", "id": 125, "status": "failed", "error": "..." },
    { "to": "c@example.com", "id": 126, "status": "sent",   "message_id": "<...>" }
  ]
}
```

All-failed batches return HTTP 502 with the same `results` shape.

**Implementation notes:**

- Duplicate addresses in `to` are silently deduped (case-insensitive on the
  full email).
- The array is capped at `max_recipients` (default 100, configurable in
  `config.php`). Larger batches should be split caller-side or use a
  dedicated bulk endpoint.
- A single TCP/TLS/AUTH handshake is reused across the whole batch via
  `SMTPKeepAlive` — fan-out cost is dominated by SMTP RTT, not setup.
- The shared `request_json` payload is stored only on the first row of a
  batch (where `batch_index = 0`); other rows reference the batch via
  `batch_id`. Avoids ballooning disk on large fan-outs.
- Single-recipient sends (whether passed as a bare string or as a one-item
  array) leave `batch_id`, `batch_size`, and `batch_index` NULL — the
  response shape and HTTP codes are unchanged from the pre-fan-out version.

## Inspect logs

Every request — even rejected ones that get as far as validation passing —
produces a row in `email_log`. Useful queries:

```sql
-- Last 20 attempts
SELECT id, created_at, source_app, status, to_email, subject, error_message
FROM email_log ORDER BY id DESC LIMIT 20;

-- Failures in the last day
SELECT id, created_at, to_email, error_message, smtp_response
FROM email_log
WHERE status = 'failed' AND created_at > NOW() - INTERVAL 1 DAY
ORDER BY id DESC;

-- Volume per source_app over the last 7 days
SELECT source_app, COUNT(*) AS sent
FROM email_log
WHERE status = 'sent' AND created_at > NOW() - INTERVAL 7 DAY
GROUP BY source_app;
```

`request_json` stores the raw incoming payload so you can replay a request
exactly if needed.

## Security notes

- **HTTPS only.** Never send API keys over plain HTTP. Force HTTPS at the
  cPanel level (AutoSSL + a redirect rule).
- **Rotate API keys** by adding a new entry to `api_keys`, switching the
  caller, then removing the old one. Each FCS instance gets its own key.
- **The body's `source_app` is not trusted.** The bearer token determines
  which source_app the request belongs to; the body field is only cross-checked.
- **Sender allowlist is enforced.** An FCS instance can only send `from`
  addresses configured for its source_app, even if it knows the key.
- **Template names are an allowlist.** Arbitrary template paths cannot be
  requested. `basename()` is also applied as defence-in-depth against
  path traversal.
- **All template `data` is HTML-escaped** by the templates. The JSON body
  cannot inject raw HTML into rendered emails.
- **Body size is capped** (default 100 KB; tune `max_body_bytes` in config
  and `post_max_size` in php.ini).
- **Rate limiting** is not implemented in PHP. If you need it, the simplest
  options on cPanel are:
  1. Cloudflare in front of the domain with a Rate Limit rule on the
     `/mail-api/send.php` path.
  2. mod_evasive at the Apache level.
  3. A small MySQL counter (per `source_app`, per minute) added to this
     codebase — straightforward but not built in yet.

## Limitations

- `status = sent` means the SMTP server **accepted** the message, not that
  it landed in the recipient's inbox. Bounces and spam-folder delivery
  happen downstream and are not visible here.
- Delivery quality depends entirely on the SMTP host's IP reputation and
  the DKIM/SPF/DMARC records on the sending domain. Verify those are set
  up correctly in cPanel → Email Deliverability for every sending domain.
- The relay is **synchronous** — `send.php` does not return until SMTP has
  responded. Calls from FCS should treat this as a slow endpoint (few hundred
  ms to a few seconds) and probably push the call onto a queue.
- There is no retry logic. If SMTP fails, the row is marked `failed` and
  the caller can re-submit.
