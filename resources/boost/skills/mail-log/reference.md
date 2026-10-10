# Mail Log — reference

Deep reference for `phattarachai/mail-log-laravel`. Read [`SKILL.md`](SKILL.md) first for install + the per-Mailable opt-in.

## Config reference (`config/mail-log.php`)

| Key | Env | Default | Meaning |
|---|---|---|---|
| `enabled` | `MAIL_LOG_ENABLED` | `true` | Master switch, parsed with `FILTER_VALIDATE_BOOL` (`1`, `true`, `on` enable). When false the service provider skips event listener registration entirely — zero overhead on hot paths. |
| `pending_timeout_minutes` | `MAIL_LOG_PENDING_TIMEOUT_MINUTES` | `15` | `mail-log:settle-stale` marks sends still Pending after this many minutes as Failed. |
| `redact.enabled` | `MAIL_LOG_REDACT_BODIES` | `true` | Mask secrets in the stored group body (`html_body` / `text_body`) before saving. |
| `redact.patterns` | — | 3 regexes (token/signature/expires/_token/code/hash/otp query values, `/password/reset/` + `/reset-password/` tokens, `/email/verify/` + `/verify-email/` hashes) | `pattern => replacement` map applied with `preg_replace`. Also applied before the body hash, so it keeps raw-path fingerprints stable. A pattern that errors withholds the body instead of storing it. |
| `tables.groups` | `MAIL_LOG_TABLE_GROUPS` | `mail_log_groups` | Group table name. Migration + models honor this. |
| `tables.events` | `MAIL_LOG_TABLE_EVENTS` | `mail_logs` | Event (per-send) table name. |
| `tables.associations` | `MAIL_LOG_TABLE_ASSOCIATIONS` | `mail_log_associations` | Models related to each send (primary + `mailLogModels()`). |
| `resend.enabled` | `MAIL_LOG_RESEND_ENABLED` | `true` | Show *↻ Resend* for sends of Mailables that opt in, and mount `POST /{group}/events/{event}/resend`. |
| `resend.max_payload_bytes` | `MAIL_LOG_RESEND_MAX_PAYLOAD_BYTES` | `65536` | Encrypted Mailables larger than this aren't stored (that send can't be resent). |
| `retention_days` | `MAIL_LOG_RETENTION_DAYS` | `365` | Prunable scope on `MailLogGroup` (keyed on `updated_at`). `null` = never prune. Events cascade-delete via FK. |
| `attachments.disk` | `MAIL_LOG_ATTACHMENT_DISK` | (null → Spatie default) | Filesystem disk Spatie writes attachment media to. |
| `attachments.collection` | — | `attachments` | Spatie media collection name. |
| `attachments.max_bytes_each` | `MAIL_LOG_ATTACHMENT_MAX_BYTES` | `10485760` (10 MB) | Per-attachment cap. Larger attachments are silently dropped from media. |
| `fingerprint.default_mode` | — | `['class', 'model']` | Global fingerprint inputs when a Mailable doesn't override `mailLogFingerprintMode()`. |
| `fingerprint.body_strip_patterns` | — | 4 regexes (token / verify-email / password-reset / ISO timestamps) | Applied to html + text body BEFORE the body hash on the raw-mail fallback path. Strips obvious per-recipient entropy so `Mail::raw(...)` with a magic-link URL still groups across recipients. |
| `fingerprint.max_recipients_per_event` | `MAIL_LOG_MAX_RECIPIENTS_PER_EVENT` | `200` | Cap on recipients stored in a single event row's `to`/`cc`/`bcc` JSON column. Protects against malformed Mailables blowing up the column. |
| `ui.path` | `MAIL_LOG_UI_PATH` | `mail-log` | URL prefix. Set to `null` to skip route registration entirely (CLI-only install). |
| `ui.middleware` | — | `['web', Authorize::class]` | Route group middleware. `Authorize` calls `MailLog::check($request)`. |
| `ui.auth_default` | — | `'debug-only'` | Documentation only — `Authorize::class` falls back to `APP_DEBUG` when no callback registered. |
| `ui.page_size` | `MAIL_LOG_UI_PAGE_SIZE` | `25` | Paginator size on the index + the Sends table on the show page. |
| `ui.brand` | `MAIL_LOG_UI_BRAND` | `Mail Log` | Header brand string. |
| `morph_alias` | `MAIL_LOG_MORPH_ALIAS` | `mail_log_group` | Morph alias `MailLogGroup::registerMorphMap()` registers under. |
| `test_send.enabled` | `MAIL_LOG_TEST_SEND_ENABLED` | `true` | Show the "Test send" button in the header + mount `POST /test-send`. |

## Fingerprint algorithm

The fingerprint is what determines whether a send appends to an existing group or creates a new one. It's a sha256 hex string stored UNIQUE on `mail_log_groups.fingerprint`.

```
Symfony Mime\Email + mailer name
    ↓
1. Hard override? (X-Mail-Fingerprint header)
    ↓ no
2. Resolve mode:
   - X-Mail-Class OR X-Mail-Notification-Class header missing → ['subject', 'mailer', 'body']  (raw-mail fallback)
   - X-Mail-Fingerprint-Mode header set → use its JSON array
   - else → config('mail-log.fingerprint.default_mode')
    ↓
3. For each mode entry, resolve its value:
     class              → X-Mail-Class header
     notification_class → X-Mail-Notification-Class header
     model              → X-Mail-Model-Type|X-Mail-Model-Id
     hints              → X-Mail-Fingerprint-Hint header (JSON, canonicalized via ksort/sort)
     subject            → $message->getSubject()
     body               → sha256(strip(html_body) . "\n--\n" . strip(text_body))   ← body_strip_patterns applied
     mailer             → $event->data['mailer']
    ↓
4. ksort by mode name → concat as "<name>=<value>|" → sha256
```

**Hard override** (`X-Mail-Fingerprint`): if you stamp this header in `withMailLog` or a custom `headers()`, it's used as-is. Already-64-hex values are returned directly; anything else gets sha256'd. Use this when you need to manually merge sends that wouldn't otherwise group (e.g., across two Mailable classes).

**Raw-mail fallback**: `Mail::raw('hi', fn ($m) => $m->to('a@b'))` doesn't have a Mailable class, so neither `X-Mail-Class` nor `X-Mail-Notification-Class` exist. The Fingerprinter forces `['subject', 'mailer', 'body']` for that send, which means raw mails ARE captured but per-recipient body variation (links, names, dates) WILL break grouping unless covered by `body_strip_patterns`.

**Hint canonicalization**: `['tenant_id' => 7, 'role' => 'admin']` and `['role' => 'admin', 'tenant_id' => 7]` produce the same fingerprint. Both keys and values are normalized (ksort on associative arrays, sort on lists, recursively).

## Group vs event semantics

The split mirrors what Watchtower does for exceptions: `mail_log_groups` is the issue identity, `mail_logs` is the event stream.

### `mail_log_groups`

- `fingerprint` (CHAR 64, UNIQUE) — the dedup key.
- `subject`, `from`, `mailable_class`, `notification_class`, `mailer` — **"latest seen"** on every send (refreshed inside the listener's `forceFill($canonical)->save()`). Drift across deploys when classes get renamed is fine; old groups will quietly carry the new name once they next fire.
- `nullableMorphs('model')` — the originating record. `model_type` carries the morph alias (`mail_log_group` by default — `MailLogGroup::registerMorphMap()` registers it).
- `html_body` / `text_body` — **first send wins**. Subsequent events do NOT rewrite the body. Surfaced in the show page's body-preview iframe as a representative sample. Per-event bodies are not stored; the `body-preview` partial says so. With `redact.enabled` (default) the stored body has reset tokens, URL signatures and verify hashes replaced by `[redacted]`, because a mail without the trait (Laravel's `ResetPassword` / `VerifyEmail`, magic links) carries the first recipient's secret URL.
- `sent_count`, `failed_count`, `latest_status` — denormalized counters. The listener's `recordEvent` increments atomically via `$this->save()` (status) + `$this->increment(...)` (counter).
- `created_at` = first seen; `updated_at` = last activity (bumped by `recordEvent`).

### `mail_logs`

- `group_id` (FK → `mail_log_groups.id`, `cascadeOnDelete`).
- `to` / `cc` / `bcc` (JSON, capped at `max_recipients_per_event`).
- `status` (`Pending` → `Sent` | `Failed`, MailLogStatus enum).
- `error_message` (TEXT, `Str::limit(..., 500)` truncation).
- `seconds` (DOUBLE) — calculated from `X-Mail-Log-Start` header stamped during `handleSending`.
- `sent_at`, `created_at`. NO `updated_at` — events are append-only outcome records.
- Index on `(group_id, created_at)` for the Sends-table pagination.

### Listener flow

0. **Headers stay internal.** `handleSending` reads the trait's `X-Mail-*` headers and removes them in a `finally`, before the transport sees the message. It keeps the host's `Message-ID` or assigns one, and stores it on the row; `MessageSent` matches the row by that `Message-ID`.
1. **`MessageSending`** → fingerprint computed → `MailLogGroup::firstOrCreate(['fingerprint' => $fp], $canonical)` inside `DB::transaction`. On race (UNIQUE violation on second insert) catches `UniqueConstraintViolationException` and re-fetches. New group: persist body + attachments. Existing group: refresh canonical attrs only. Then `MailLog::create([...PENDING])`. Stamps `X-Mail-Log-Event-Id` + `X-Mail-Log-Start` onto the outgoing message.
2. **`MessageSent`** → reads `X-Mail-Log-Event-Id`, looks up the event, updates to `SENT` + `sent_at` + `seconds`, calls `$group->recordEvent($event)` to bump `sent_count` and flip `latest_status`.
3. **Failure settlement.** The listener is a scoped singleton that remembers the events this request / job created whose `MessageSent` has not fired ("in flight"):
   - **`JobExceptionOccurred`** (every failed attempt of any job: `SendQueuedMailable`, `SendQueuedNotifications`, or the host's own job) → in-flight events become `FAILED`. A non-final attempt's message is prefixed `Attempt N failed, the job will retry:`, so a job with `tries > 1` leaves one Failed row per failed attempt plus the final outcome, never a trail of Pending rows.
   - **`JobFailed`** → same, for the final attempt. When nothing is in flight in this process (the sending attempt ran in a worker that died), a queued **Mailable** falls back to the newest PENDING event of its class + model.
   - **`JobProcessed`** → a job that finished but caught a send exception itself.
   - **App `terminating`** → a synchronous `Mail::send()` that threw in the transport (Laravel fires no event for it), settled with `LogOutgoingMail::UNCONFIRMED_MESSAGE`.
4. **`mail-log:settle-stale`** (scheduled) → the backstop for everything else (killed workers, timeouts retried elsewhere, crashed requests): Pending rows older than `pending_timeout_minutes` become `FAILED`.

All handlers `report()` exceptions but never re-throw — the mail pipeline never breaks because of a logging failure.

## `MailLogLinkable` contract (opt-in)

Implementing `Phattarachai\MailLogLaravel\Contracts\MailLogLinkable` on a host model lets the dashboard surface a clickable deep-link in the group's "Model" column.

```php
class Order extends Model implements MailLogLinkable
{
    public function mailLogTitle(): string
    {
        return "Order #{$this->number}";
    }

    public function mailLogUrl(): ?string
    {
        return route('orders.show', $this);
    }
}
```

The index's Model column and the group page's Model row render `mailLogTitle()`, linked to `mailLogUrl()` when it returns a URL. Models without the contract render as `Type#id` (class basename on the index). A `model_type` whose class no longer exists degrades to text instead of erroring.

## Several models and resend

- **`mail_log_associations`** — one row per model per send: the primary model (`is_primary`) plus `mailLogModels()`. `GroupController` filters `?model=Type:id` against the group's primary model or any association of its sends; the group page lists the distinct related models.
- **Resend** — `mailLogResendable()` makes the trait put `Crypt::encryptString(serialize($mailable))` in `X-Mail-Log-Resend`; the listener stores it in `mail_logs.resend_payload` (hidden from `toArray()`). `MailResender` restores it, clears its to/cc/bcc, tells the listener which addresses the resend may reach, and `sendNow()`s it. The listener cancels (returns false from `MessageSending`) a resend that would reach any other address, e.g. one the Mailable adds in `envelope()`. The new send gets `resent_from_id`. Failures surface as `CannotResend` (dashboard flash, command error).

## UI routes + auth gate

| Method | URI | Name | Controller |
|---|---|---|---|
| GET | `/{ui.path}` | `mail-log.index` | `GroupController@index` (filters: `search`, `status`, `has_failures`) |
| GET | `/{ui.path}/{group}` | `mail-log.show` | `GroupController@show` (paginated events) |
| DELETE | `/{ui.path}/{group}` | `mail-log.destroy` | `GroupController@destroy` (cascade) |
| GET | `/{ui.path}/{group}/events/{event}` | `mail-log.event` | `EventController@show` (JSON) |
| GET | `/{ui.path}/{group}/attachments/{media}` | `mail-log.attachment` | `AttachmentController@show` (binary file response) |
| POST | `/{ui.path}/test-send` | `mail-log.test-send` | `TestSendController@store` (dispatches `Mail\TestMail`) |

Routes mount under `config('mail-log.ui.middleware')` (default `['web', Authorize::class]`). `Authorize` calls `MailLog::check($request)`:

```php
public static function check(Request $request): bool
{
    if (static::$authCallback !== null) {
        return (bool) (static::$authCallback)($request);
    }

    return (bool) config('app.debug', false);
}
```

So the default is "allow when `APP_DEBUG=true`, deny otherwise" — production deployments hit 403 until an `auth()` callback is registered. Typical setup:

```php
use Phattarachai\MailLogLaravel\MailLog;

MailLog::auth(fn ($request) => $request->user()?->isAdmin() ?? false);
```

## Asset bundle internals

The package ships pre-built CSS + JS in `dist/`:

- `dist/mail-log.css` (~21 KB / ~5 KB gz) — Tailwind v4 compiled from `resources/css/mail-log.css` + `@source` of `resources/views`.
- `dist/mail-log.js` (~48 KB / ~17 KB gz) — Alpine.js v3 + the package's `mailLogShell` / `bodyPreview` / `copyButton` / `sendsTable` / `testSendModal` data definitions.

Both are inlined into the layout via static helpers (`MailLog::css()` returns `<style>...</style>`, `MailLog::js()` returns `<script type="module">window.MailLog = {...}; <bundle>...</script>`). No HTTP route for assets — same pattern as Laravel Pulse. Drop-in install with no `storage:link`, no asset publishing, no `npm install` in the host.

To rebuild from source after editing `resources/css/mail-log.css` or `resources/js/mail-log.js`, run:

```bash
cd vendor/phattarachai/mail-log-laravel
npm install
npm run build
```

Maintainer commits the built `dist/` files before tagging.

## Troubleshooting

**`SQLSTATE[42P01]: Undefined table: 7 ERROR: relation "media" does not exist`** — the Spatie `laravel-medialibrary` migration wasn't published in the host. Re-run `php artisan mail-log:install` (it auto-detects + publishes the Spatie migration when missing) or manually:

```bash
php artisan vendor:publish --tag=medialibrary-migrations
php artisan migrate
```

**Index page is empty after sending mail** — check (a) the Mailable uses `HasMailLog`; (b) `headers()` calls `$this->withMailLog(new Headers())`; (c) `MAIL_LOG_ENABLED=true`; (d) the event listeners are registered (`php artisan event:list | grep MessageSending`).

**Sends to the same template land in separate groups** — confirm `mailLogModel()` returns the SAME model instance (same `getKey()` value). A Mailable that constructs a fresh model in its constructor will get a different model id each time. Also check `Relation::morphMap()` is registered if `model_type` is changing across runs (the package's `MailLogGroup::registerMorphMap()` pins it to `mail_log_group`; without a morph map the FQCN of any related model lands in the column instead).

**Attachments don't appear on the show page** — Spatie media's `disk_name` config must match `mail-log.attachments.disk`. Default `null` falls through to Spatie's default; if your project has a custom disk, set `MAIL_LOG_ATTACHMENT_DISK=<name>` in `.env`.

**`/mail-log` 403s on a fresh install** — that's the default gate (`APP_DEBUG=true` only). Either set `APP_DEBUG=true` in local, or register `MailLog::auth(...)` in `AppServiceProvider::boot()`.

**Index search is slow on large groups tables** — the `search` filter is case-insensitive and does `WHERE EXISTS (SELECT 1 FROM mail_logs WHERE to / cc / bcc LIKE ?)` (`::text ilike` on Postgres, `lower(cast(… as char))` on MySQL). No index covers it; on a very large `mail_logs` table add a trigram (Postgres) or full-text index on the recipient columns.

**Sends stuck on Pending** — schedule `mail-log:settle-stale` (see SKILL.md). Rows the in-process settlement couldn't reach (a killed worker, a timed-out attempt retried on another worker) become Failed once older than `pending_timeout_minutes`.

**`X-Mail-Fingerprint` hard override** for cross-class merging — useful when you've renamed a Mailable but want the old group to keep accumulating sends. Stamp `X-Mail-Fingerprint` to the prior fingerprint value via `withMailLog(...)` (subclass the trait or post-process the Headers).
