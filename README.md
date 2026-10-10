# Mail Log

[![Latest Version on Packagist](https://img.shields.io/packagist/v/phattarachai/mail-log-laravel.svg?style=flat-square)](https://packagist.org/packages/phattarachai/mail-log-laravel)
[![Tests](https://img.shields.io/github/actions/workflow/status/phattarachai/mail-log-laravel/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/phattarachai/mail-log-laravel/actions/workflows/run-tests.yml?query=branch%3Amain)
[![Code Style](https://img.shields.io/github/actions/workflow/status/phattarachai/mail-log-laravel/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/phattarachai/mail-log-laravel/actions/workflows/fix-php-code-style-issues.yml?query=branch%3Amain)
[![PHP Version](https://img.shields.io/packagist/dependency-v/phattarachai/mail-log-laravel/php?style=flat-square&label=php&logo=php&logoColor=white)](https://packagist.org/packages/phattarachai/mail-log-laravel)
![Laravel Version](https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20?style=flat-square&logo=laravel&logoColor=white)
[![Total Downloads](https://img.shields.io/packagist/dt/phattarachai/mail-log-laravel.svg?style=flat-square)](https://packagist.org/packages/phattarachai/mail-log-laravel)
[![License](https://img.shields.io/packagist/l/phattarachai/mail-log-laravel.svg?style=flat-square)](LICENSE)

Self-hosted outbound mail logger for Laravel. Captures every `Mail::send(...)` + Notification mail-channel call, groups identical sends together by Mailable class + Eloquent model, and exposes the result at a `/mail-log` dashboard. Ships its own Tailwind UI — no Filament, no Livewire-from-the-package, no `npm install` in the host.

## What it gives you

- **One row per template, not per send.** Sending `OrderShippedMail($order)` to ten recipients lands as **one group with `sent_count=10`** + ten event rows. Different `Order` → different group.
- **A `/mail-log` dashboard** styled like Laravel Horizon / Pulse: list of groups (last-sent first), per-group detail page with body preview (sandboxed `<iframe srcdoc>`, sized to the mail), Sends table with per-recipient outcomes, attachments, "Test send" modal. No third-party requests: system fonts, inlined CSS + JS.
- **Per-mailable opt-in via a single trait** — drop `HasMailLog` into a Mailable, return the originating model from `mailLogModel()`, return `$this->withMailLog(new Headers())` from `headers()`. That's the whole integration surface.
- **Every send settles.** A send whose transport throws becomes FAILED with the exception message, whether it was a queued Mailable, a queued Notification, or a synchronous `Mail::send()`. A scheduled `mail-log:settle-stale` catches the rest (see [Failed and stale sends](#failed-and-stale-sends)).
- **Secrets stay out of the preview.** Reset tokens, signed-URL signatures and verify hashes in the stored body are masked as `[redacted]`.
- **First-class retention.** `MailLogGroup` implements `Prunable`; `php artisan model:prune` (daily via scheduler) cascades old groups + their events.

## Install

```bash
composer require phattarachai/mail-log-laravel
php artisan mail-log:install
```

`mail-log:install` is idempotent. It publishes the package config, the Spatie `laravel-medialibrary` migration (auto-detected when the `media` table is missing), and the package's own migrations. It then writes three env keys (`MAIL_LOG_ENABLED`, `MAIL_LOG_RETENTION_DAYS`, `MAIL_LOG_UI_PATH`) only when absent, and prints the snippets you need to paste into `AppServiceProvider::boot()`, a Mailable, and the scheduler.

Re-run safely. Pass `--dry-run` to print intended changes without writing.

After install, paste the auth gate snippet:

```php
use Phattarachai\MailLogLaravel\MailLog;
use Phattarachai\MailLogLaravel\Models\MailLogGroup;

MailLogGroup::registerMorphMap();
MailLog::auth(fn ($request) => $request->user()?->isAdmin() ?? false);
```

The default policy blocks `/mail-log` unless `APP_DEBUG=true` — loud, safe failure until you opt in.

## Per-Mailable opt-in

Drop the trait into a Mailable and tell it what model originated the send:

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

Now every `Mail::to($recipient)->send(new OrderShippedMail($order))` to ANY recipient lands in the same group; sending the same Mailable for a different `Order` creates a separate group.

### Trait hooks

All return safe defaults — override only what you need.

| Hook | Returns | Behavior |
|---|---|---|
| `mailLogModel(): ?Model` | `null` | The originating record. Folded into the fingerprint when mode includes `'model'` (default). |
| `mailLogNotificationClass(): ?string` | `null` | The source `Notification` when the Mailable is constructed inside `toMail()`. Lets you fingerprint on the notification instead. |
| `mailLogFingerprintHints(): array` | `[]` | Extra strings (tenant id, A/B variant) folded in when mode includes `'hints'`. |
| `mailLogFingerprintMode(): ?array` | `null` | Per-Mailable override of `['class', 'model']`. Valid: `class`, `notification_class`, `model`, `hints`, `subject`, `body`, `mailer`. |
| `mailLogSkip(): bool` | `false` | Opt this Mailable out of capture entirely (health-check pings, transactional one-offs). |

## Group / event model

Two tables in lock-step:

```
mail_log_groups (one row per fingerprint)
├── fingerprint  (CHAR 64, UNIQUE — the dedup key)
├── subject / from / mailable_class / notification_class / mailer  (latest seen)
├── model_type / model_id  (morphTo)
├── html_body / text_body  (first-send wins; representative preview only)
├── sent_count / failed_count / latest_status  (denormalized counters)
└── created_at / updated_at

mail_logs (one row per Mail::send(), append-only)
├── group_id  (FK → mail_log_groups.id, cascadeOnDelete)
├── to / cc / bcc  (JSON)
├── status  (Pending → Sent | Failed)
├── error_message / seconds / sent_at
└── created_at
```

The body shown on the show page is the **first send** in the group — per-recipient body variation (signed URLs, magic-login tokens, personalized greetings) is NOT captured per row. The package favors fingerprint stability over body fidelity, and per-event body storage is not planned.

**Recipients** on the dashboard are the distinct `to`, `cc` and `bcc` addresses across every send in the group, in the order they were first mailed (oldest send first; within a send, `to` before `cc` before `bcc`). The index shows the first one plus a `+N more` count, so the primary recipient of the first send leads.

**Sends** counts every event in the group, Pending included. `sent_count` / `failed_count` only count settled outcomes.

### Redacted bodies

Mail sent without the trait goes through the raw fallback path, and its body carries one recipient's secret: Laravel's `ResetPassword` and `VerifyEmail` notifications, magic-login links, any signed URL. The group stores the first send's body, so by default the listener masks these before saving:

- query values of `token`, `signature`, `expires`, `_token`, `code`, `hash`, `otp` (raw `&` or HTML-escaped `&amp;`)
- the token in `/password/reset/{token}` and `/reset-password/{token}`
- the hash in `/email/verify/{id}/{hash}` and `/verify-email/{id}/{hash}`

Each becomes `[redacted]`. Add your own patterns under `redact.patterns` in `config/mail-log.php` (`pattern => replacement`), or set `MAIL_LOG_REDACT_BODIES=false` to store bodies verbatim. Rows stored before v0.3.0 keep their original body until they are deleted or pruned.

## Model deep links

Implement `MailLogLinkable` on a model a Mailable returns from `mailLogModel()`, and the dashboard's Model column (index) and Model row (group page) link back into your app:

```php
use Phattarachai\MailLogLaravel\Contracts\MailLogLinkable;

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

Models without the contract render as `Order#42`.

## Failed and stale sends

Every event starts **Pending** when Laravel fires `MessageSending` and becomes **Sent** on `MessageSent`. When the transport throws, the listener settles it as **Failed** with the exception message:

| Where the send ran | Settled by |
|---|---|
| Queued Mailable or queued Notification (`SendQueuedMailable`, `SendQueuedNotifications`) or your own job | `JobExceptionOccurred` / `JobFailed` in the same worker. A job with `tries > 1` records one Failed row per failed attempt (`Attempt 1 failed, the job will retry: …`) plus the final outcome. |
| A job that caught the send exception itself | `JobProcessed` |
| Synchronous `Mail::send()` in a request or command (Laravel fires no event for a transport error) | the app's `terminating` hook |
| A worker killed mid-send, a timeout retried on another worker, a crashed process | `php artisan mail-log:settle-stale` |

`mail-log:settle-stale` marks events still Pending after `MAIL_LOG_PENDING_TIMEOUT_MINUTES` (default 15) as Failed. Schedule it (see [Scheduling](#scheduling)); `--minutes=N` overrides the timeout for one run.

A send that another `MessageSending` listener cancels after Mail Log logged it also ends up Failed, since it was never delivered.

## Config

```env
MAIL_LOG_ENABLED=true                    # master switch (1/true/on) — false skips listener registration
MAIL_LOG_RETENTION_DAYS=365              # prunable; null = never prune; events cascade with the group
MAIL_LOG_UI_PATH=mail-log                # URL prefix; null skips route registration
MAIL_LOG_TEST_SEND_ENABLED=true          # show the Test-send button + POST /test-send route
MAIL_LOG_MAX_RECIPIENTS_PER_EVENT=200    # cap recipients stored in a single event row's JSON columns
MAIL_LOG_PENDING_TIMEOUT_MINUTES=15      # mail-log:settle-stale fails sends still Pending after this
MAIL_LOG_REDACT_BODIES=true              # mask tokens / signatures / verify hashes in stored bodies
```

See [`config/mail-log.php`](config/mail-log.php) for the full schema; the [Boost skill `reference.md`](resources/boost/skills/mail-log/reference.md) walks every key.

## Scheduling

Prune old groups daily and settle stale Pending sends every few minutes. In Laravel 12 / 13, in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;
use Phattarachai\MailLogLaravel\Models\MailLogGroup;

Schedule::command('model:prune', ['--model' => [MailLogGroup::class]])->daily();
Schedule::command('mail-log:settle-stale')->everyTenMinutes();
```

Or in `bootstrap/app.php`:

```php
->withSchedule(function (Schedule $schedule): void {
    $schedule->command('model:prune', [
        '--model' => [\Phattarachai\MailLogLaravel\Models\MailLogGroup::class],
    ])->daily();
    $schedule->command('mail-log:settle-stale')->everyTenMinutes();
})
```

Events cascade-delete via FK when their parent group is pruned.

## Dashboard language

The dashboard's labels, buttons and flash messages are in English. **Dates are always in Thai format**: day, Thai month abbreviation, two-digit Buddhist-era year and 24-hour time, e.g. `19 พ.ค. 69 · 15:07` for 19 May 2026, 15:07. The format is not configurable yet.

## Requirements

- PHP `^8.4`
- Laravel `^12` or `^13`
- `spatie/laravel-medialibrary ^11`
- SQLite, PostgreSQL or MySQL / MariaDB (CI runs the suite on all three)

## Credits

Built by [Phattarachai Chaimongkol](https://github.com/phattarachai). Same shipping pattern as Laravel [Horizon](https://laravel.com/docs/horizon) and [Pulse](https://laravel.com/docs/pulse) — pre-built CSS + JS committed to `dist/`, inlined into the layout via `MailLog::css()` / `MailLog::js()` helpers, no host-side `npm install` required.

## License

MIT. See [LICENSE](LICENSE).
