---
name: mail-log
description: Use this skill when the user wants to wire up Mail Log (an outbound-mail logger for Laravel) into a project, or asks about per-Mailable dedup grouping, the `/mail-log` dashboard, or the `HasMailLog` trait. Covers the `phattarachai/mail-log-laravel` package end-to-end via the `mail-log:install` command — publishes config + migrations + Spatie media migration, writes env keys, prints integration snippets (auth gate, morph map, `HasMailLog` trait, scheduled prune). Group/events split — one row per fingerprint (`mail_log_groups`) + one row per send (`mail_logs`). Fingerprint defaults to Mailable class + Eloquent model so the same template to many recipients lands in one group. Triggers on mentions of mail-log, MAIL_LOG_ENABLED, MAIL_LOG_RETENTION_DAYS, HasMailLog, `/mail-log` dashboard, "log outgoing mail", "track sent emails", "group emails by template", "mailable dedup", "test-send modal", "mail log retention", or "set up mail logging".
version: 2026.10.10.1
---

# Mail Log

Mail Log captures every outbound `Mail::send(...)` + Notification mail-channel call, groups identical sends together by Mailable class + Eloquent model, and exposes the result at a self-hosted `/mail-log` dashboard. Same shape as Laravel Horizon / Pulse — own routes, own Tailwind UI, no Filament dep.

**Scope of this skill:** install, the per-Mailable opt-in flow, the group/events mental model, verification flow.  Full config + algorithm details live in [`reference.md`](reference.md).

## Install in 2 commands

```bash
composer require phattarachai/mail-log-laravel
php artisan mail-log:install
```

`mail-log:install` is idempotent. It:

1. Publishes `config/mail-log.php`.
2. **Auto-detects a missing `media` table** and publishes the Spatie `laravel-medialibrary` migration (the package depends on it for attachment storage).
3. Publishes the package's own migrations (`mail_log_groups` + `mail_logs`) unless one is already present.
4. Prompts to run `php artisan migrate`.
5. Writes `MAIL_LOG_ENABLED`, `MAIL_LOG_RETENTION_DAYS`, `MAIL_LOG_UI_PATH` to `.env` (only when absent — values already set are preserved).
6. Prints three integration snippets the operator pastes manually: the `AppServiceProvider::boot()` auth gate + morph map, the per-Mailable `HasMailLog` trait usage, and the `model:prune` schedule entry.
7. Prints the final dashboard URL.

Re-run safely. `--dry-run` prints intended changes without writing.

After install, paste the **auth gate** snippet into `AppServiceProvider::boot()` — the default policy blocks `/mail-log` unless `APP_DEBUG=true`, which is a loud, safe failure in production.

## The mental model — groups vs events

Mail Log uses two tables in lock-step:

- **`mail_log_groups`** (one row per fingerprint) — what the user sees at `/mail-log`. Carries `subject`, `mailable_class`, the morphTo `model`, `mailer`, `sent_count`, `failed_count`, `latest_status`, and the **first-send** `html_body` / `text_body` / attachments. Body is a representative preview only; per-recipient body variation (signed URLs, magic links, personalized greetings) is NOT captured per row, and reset tokens / URL signatures / verify hashes are stored as `[redacted]` (`MAIL_LOG_REDACT_BODIES`, on by default).
- **`mail_logs`** (one row per `Mail::send()` call, append-only) — captures `to` / `cc` / `bcc` / `status` / `error_message` / `seconds` / `sent_at`. Cascade-deletes when the parent group is deleted.

A fingerprint of `class + model` means: same Mailable + same `Order #1042` to 10 recipients = **one group with `sent_count=10` and 10 event rows**. Different Order → different group. The dashboard list page (`/mail-log`) is the group view; the detail page (`/mail-log/{group}`) shows the Sends table with per-recipient outcomes.

The fingerprint algorithm + body-hash fallback for `Mail::raw(...)` live in `reference.md` § "Fingerprint".

## Per-Mailable opt-in (the headline behavior)

Drop the `HasMailLog` trait into a Mailable and return the originating model. The trait stamps `X-Mail-*` headers the listener reads to build the fingerprint:

```php
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Headers;
use Phattarachai\MailLogLaravel\Concerns\HasMailLog;

class OrderShippedMail extends Mailable
{
    use HasMailLog;

    public function __construct(public Order $order) {}

    protected function mailLogModel(): ?\Illuminate\Database\Eloquent\Model
    {
        return $this->order;
    }

    public function headers(): Headers
    {
        return $this->withMailLog(new Headers());
    }
}
```

That's it — the listener handles everything else. Each send to a new recipient appends to the same group's events table; the dashboard shows it as `×N sends`.

Override hooks the trait exposes (all return null / `false` / `[]` by default):

- `mailLogModel(): ?Model` — the originating record (`Order`, `User`, `Invoice`). Folded into the fingerprint when mode includes `'model'` (default).
- `mailLogNotificationClass(): ?string` — the source `Notification` when the Mailable is constructed inside `toMail()`. Lets you fingerprint on the notification instead of the channel mailable.
- `mailLogFingerprintHints(): array` — extra strings to fold in (e.g. `tenant_id`, A/B variant). Only used when mode includes `'hints'`.
- `mailLogFingerprintMode(): ?array` — per-Mailable override of the global mode list (`['class', 'model']` default). Valid entries: `class`, `notification_class`, `model`, `hints`, `subject`, `body`, `mailer`.
- `mailLogSkip(): bool` — opt out of capture entirely (health-check pings, transactional one-offs).

## Verifying a send arrived

After install, dogfood from `tinker`:

```php
Mail::raw('hello', fn ($m) => $m->to('you@example.com')->subject('Mail Log smoke test'));
// then:
\Phattarachai\MailLogLaravel\Models\MailLogGroup::query()->latest()->first();
```

The raw path falls back to the body-hash fingerprint, so subsequent smoke tests with the same subject + body merge into one group with bumping `sent_count`.

For a real Mailable + model send, open `/mail-log` → the group should appear at the top with `latest_status=sent` and the recipient under "Recipients".

A send whose transport throws becomes FAILED with the exception message: queued mailables and queued notifications on each failed attempt, synchronous `Mail::send()` at the end of the request. Anything left Pending past `MAIL_LOG_PENDING_TIMEOUT_MINUTES` (default 15) is settled by `php artisan mail-log:settle-stale`. The error chip on the index row + the Sends-table error expander show the truncated message.

## Common workflows

**"Why are sends to the same template not being grouped?"** Check that the Mailable actually `use HasMailLog;` AND that its `headers()` returns `$this->withMailLog(new Headers())`. Without those headers stamped, the listener falls back to the raw-mail path (subject + mailer + body hash) and per-recipient body bytes break grouping.

**"How do I hide this Mailable from the dashboard?"** Override `mailLogSkip(): bool { return true; }` on the Mailable. The listener short-circuits before any DB write.

**"How do I retain only N days of mail history?"** Set `MAIL_LOG_RETENTION_DAYS` in `.env` (default 365, `null` = never prune). The package implements `Prunable` on `MailLogGroup`; schedule `model:prune` daily and `mail-log:settle-stale` every ten minutes in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;
use Phattarachai\MailLogLaravel\Models\MailLogGroup;

Schedule::command('model:prune', ['--model' => [MailLogGroup::class]])->daily();
Schedule::command('mail-log:settle-stale')->everyTenMinutes();
```

Events cascade-delete via FK when their parent group is pruned.

**"How do I expose `/mail-log` to non-debug users?"** Register `MailLog::auth(fn ($request) => $request->user()?->isAdmin())` in `AppServiceProvider::boot()`. The install command prints the exact snippet.

**"My dashboard 500s with 'relation media does not exist'."** The Spatie `media` table wasn't migrated. Run `mail-log:install` (it auto-publishes the Spatie migration when missing) then `php artisan migrate`.
