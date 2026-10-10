# Changelog

All notable changes to `phattarachai/mail-log-laravel` are documented here. Format loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning is [SemVer](https://semver.org/).

## 0.3.4 — 2026-10-10

### Fixed

- **Index recipients show in full.** The "+N more" pill moved to its own line under the address, as in the Sends table, and the Recipients column is 32px wider (Mailable is 16px narrower). Addresses up to about 29 characters no longer truncate.
- **The failure chip under a subject shows up to four lines** instead of one, so the cause at the end of a typical transport error (e.g. "… (Connection refused)") stays visible. The full message is still in the hover tooltip and on the group page.

### Compatibility

- View and CSS only. Drop-in upgrade from 0.3.x.

## 0.3.3 — 2026-10-10

### Fixed

- **The index table fits its page again.** 0.3.2 only moved the overflow inside the table's box, so the "× sends" column was cut off at every window size. Long failure messages, recipients and class names made the table about 1,350–1,480px wide in a 1,232px box. The table now uses a fixed layout: Last sent, Status, Recipients, Mailable, Model, Mailer and × sends have set widths, Subject takes the rest, and every cell truncates long content with the full text on hover. Checked in headless Chrome: the table is exactly as wide as its box at 1280, 1360 and 1440px. Below about 1,090px it scrolls inside its box.

### Compatibility

- View and CSS only. Drop-in upgrade from 0.3.x.

## 0.3.2 — 2026-10-10

### Fixed

- **No horizontal page scroll on the index.** Since 0.3.1 the one-line Model column made the table about 45px wider than the page at 1360px and below, pushing the "× sends" column to the edge. The Model column now truncates sooner, and the table scrolls inside its own container on narrow screens instead of scrolling the page.

### Compatibility

- View and CSS only. Drop-in upgrade from 0.3.x.

## 0.3.1 — 2026-10-10

### Fixed

- **The body preview shrinks again.** It only grew, so switching from Source to Text left thousands of pixels of empty frame. It now fits the content on every tab switch.
- **The index Model column no longer wraps.** Titles (often Thai names or order labels) stay on one line, truncated with the full title on hover, and use the sans font. `Type#id` fallbacks stay monospace.
- **The search placeholder fits.** The box is wider and the placeholder now reads "Search subject, recipient or class".
- **One back link on the group page.** The breadcrumb's "Back" sat under the header's "Back to app" and did something different. The breadcrumb now starts with the dashboard name ("‹ Mail Log / subject").

### Compatibility

- View, CSS and JS only. Drop-in upgrade from 0.3.0.

## 0.3.0 — 2026-10-10

### Fixed

- **Failed sends no longer stay Pending forever.** The listener now remembers the sends its request or job started and settles any that never fire `MessageSent` as Failed, with the exception message:
  - queued **Notifications** (`SendQueuedNotifications`) whose mail fails in the transport. Only queued Mailables were handled before.
  - queued jobs with `tries > 1`: every failed attempt is recorded as Failed (`Attempt 1 failed, the job will retry: …`) instead of leaving a Pending row per attempt.
  - synchronous `Mail::send()` that throws in the transport, settled when the request or command terminates.
  - sends a job caught and swallowed itself, settled on `JobProcessed`.
- **New `mail-log:settle-stale` command** for what no in-process signal can reach (a worker killed mid-send, a timeout retried on another worker). It marks sends still Pending after `pending_timeout_minutes` (default 15, `MAIL_LOG_PENDING_TIMEOUT_MINUTES`) as Failed. **Schedule it** every ten minutes (see the README).
- **`MAIL_LOG_ENABLED=1` silently disabled capture.** The provider registered listeners only for `=== true`, and `env()` returns `"1"` as a string. The config now parses it with `FILTER_VALIDATE_BOOL`, and the provider does the same so older published configs work too.
- **Reset-password mails split into one group per token.** The raw-path fingerprint stripped `/password/reset/{token}` but not `/reset-password/{token}`, which is the route Breeze, Fortify and the starter kits use. The body hash now also applies the redaction patterns.
- Removed the dead `X-Mail-Log-Event-Id` lookup on `JobFailed`. That header only exists on the sent Symfony message, never on the queued Mailable. The class + model fallback stays for a queued Mailable whose sending attempt ran in another process.
- **Search is case-insensitive on every driver** (`ilike` on PostgreSQL, a lower-cased cast on MySQL's JSON columns) and now matches `cc` and `bcc` recipients too.
- **Dashboard UI:**
  - The **Sends** count (index column, index total, group stats) includes Pending sends, which were counted as 0. The group page also shows "N pending".
  - "first HH:MM" under *Last sent* shows the full date when the group started on an earlier day.
  - The Sends table's time column (renamed **Time**) shows when the send was confirmed (`sent_at`), falling back to the attempt time for Pending and Failed sends.
  - The "N failed" chip and the "× sends" header no longer wrap.
  - The body preview iframe grows to fit the mail instead of a fixed 600px, and can be resized.

### Added

- **Body redaction, on by default.** The group's stored `html_body` / `text_body` came verbatim from the first send. For mail without the trait (Laravel's `ResetPassword` / `VerifyEmail`, magic links), that meant the first recipient's live token URL showed in the dashboard preview. Token, signature, expires, code, hash and otp query values plus reset and verify path segments are now stored as `[redacted]`. Configure with `redact.enabled` (`MAIL_LOG_REDACT_BODIES`) and `redact.patterns`. Rows stored before 0.3.0 keep their body until deleted or pruned.
- **`MailLogLinkable` deep links.** A new Model column on the index and the group page's Model row render `mailLogTitle()`, linked to `mailLogUrl()`. Models without the contract render as `Order#42`, and a `model_type` whose class no longer exists degrades to text.
- CI on GitHub Actions: PHP 8.4 / 8.5 × Laravel 12 / 13 (lowest and stable), plus PostgreSQL and MySQL jobs. Also PHPStan (Larastan level 5), Pint, release-drafter and Dependabot.

### Changed

- **Dashboard chrome is English throughout.** The Thai strings (breadcrumb, search placeholder, empty states, test-send modal, flash messages) are now English, matching the rest of the UI. Dates keep the Thai format `19 พ.ค. 69 · 15:07`; the README documents it.
- **Recipients** are now the distinct `to`, `cc` and `bcc` addresses in the order they were first mailed. Before, they were `to` only, newest first.
- The dashboard loads no third-party fonts (fonts.bunny.net). It uses the system font stack.
- Removed the theme toggle. It never had dark styles behind it.
- The `recipients-accordion` view component is renamed `recipients-list`. If you published the views, rename your override.
- The install command's schedule snippet uses the `routes/console.php` form and adds `mail-log:settle-stale`.

### Upgrading

- Add the new keys to a published `config/mail-log.php`: `enabled` with `filter_var(..., FILTER_VALIDATE_BOOL)`, `pending_timeout_minutes`, and the `redact` block. The defaults apply when they are missing, except the `enabled` parsing, which the provider now handles anyway.
- Schedule `mail-log:settle-stale`.
- No migration.

## 0.2.1 — 2026-06-22

### Fixed

- **`Undefined array key "label"` fatal on the dashboard header.** When `mail-log.ui.back_link` was an array carrying only a `url` key (e.g. an install whose published `config/mail-log.php` predates the 0.2.0 `label` addition, or one that overrode `back_link` with just a URL), the header blew up with a fatal `ViewException` under production error handling. `layout.blade.php` now reads the `label` key defensively (`$mailLogBackLink['label'] ?? null`) and guards the `url` lookup against non-array values, falling back to `"Back"`.

### Compatibility

- View-only patch. No schema, config-key, or listener changes — drop-in upgrade from 0.2.0.

## 0.2.0 — 2026-05-19

### Added

- **Back-to-app link in the header.** A new `mail-log.ui.back_link` config (`url` + `label`) renders a `← Back to app` link on the far left of the dashboard header. Defaults to `url('/')` so it works out of the box. Override via `MAIL_LOG_UI_BACK_URL` (string for a custom URL, `false` to hide) and `MAIL_LOG_UI_BACK_LABEL` (default `"Back to app"`).

### Changed

- **Index page typography.** Dropped `tracking-wide` / `tracking-wider` from every uppercase mini-label across `index.blade.php`, `show.blade.php`, `components/{group-row, stats-strip, recipients-accordion, attachments-list}.blade.php`. Labels stay uppercase but no longer carry expanded letter-spacing.
- **`Last sent` column no-wrap.** The Thai date format (`19 พ.ค. 69 · 15:07`) was wrapping onto three lines in narrow viewports; the `<th>` and `<td>` now carry `whitespace-nowrap`.

### Compatibility

- No schema, listener, or config-key removals. Drop-in upgrade from 0.1.x — no migration, no `mail-log:install` rerun required.

## 0.1.0 — 2026-05-19

Initial release.

### Added

- **Group/event split.** `mail_log_groups` (one row per fingerprint, UNIQUE) + `mail_logs` (one row per `Mail::send()`, cascade-deletes with parent group).
- **Fingerprint on Mailable class + model.** Default mode `['class', 'model']` — same template + same Eloquent record always groups together regardless of per-recipient body variation. Override per-Mailable via `mailLogFingerprintMode()`.
- **`HasMailLog` trait.** Drop into a Mailable; stamps `X-Mail-*` metadata headers the listener reads to build the fingerprint. Hooks: `mailLogModel`, `mailLogNotificationClass`, `mailLogFingerprintHints`, `mailLogFingerprintMode`, `mailLogSkip`.
- **`/mail-log` dashboard.** Pulse-style header, group index with filters (search across subject / mailable class / recipient, status, has-failures), group detail with sandboxed body preview (HTML / Text / Source toggle), Sends table with per-recipient outcomes, attachments list, Test-send modal.
- **`mail-log:install` command.** Publishes config + migrations; auto-detects a missing `media` table and publishes the Spatie laravel-medialibrary migration; writes env keys via `EnvWriter::setIfAbsent` (idempotent); prints the auth-gate / morph-map / `HasMailLog` trait / scheduled-prune snippets. `--dry-run` flag prints intended changes without writing.
- **Prunable retention.** `MailLogGroup` implements `Prunable` — schedule `model:prune` to expire idle groups past `MAIL_LOG_RETENTION_DAYS` (default 365). Events cascade-delete via FK.
- **`MailLogLinkable` contract.** Opt-in interface for host models that want a clickable deep-link in the dashboard's Model column. v0.1 ships the contract; deep-link rendering arrives in v0.2.
- **JobFailed handling.** Failed queued mailables flip the most-recent PENDING event in the matching group to FAILED + bump `failed_count` + flip `latest_status`. Falls back to class+model lookup when the queued mailable's `headers()` doesn't carry the event id.
- **Pre-built Tailwind + Alpine bundles** committed to `dist/`. Layout inlines them via `MailLog::css()` / `MailLog::js()` — no host-side `npm install`, no `storage:link`, no asset publishing. Same pattern as Horizon/Pulse.
- **Boost skill** at `resources/boost/skills/mail-log/{SKILL.md,reference.md}` — install instructions, group/event mental model, fingerprint algorithm, troubleshooting.

### Configuration

- `MAIL_LOG_ENABLED` (master switch), `MAIL_LOG_RETENTION_DAYS`, `MAIL_LOG_UI_PATH`, `MAIL_LOG_TEST_SEND_ENABLED`, `MAIL_LOG_MAX_RECIPIENTS_PER_EVENT`, `MAIL_LOG_TABLE_GROUPS`, `MAIL_LOG_TABLE_EVENTS`, `MAIL_LOG_ATTACHMENT_DISK`, `MAIL_LOG_ATTACHMENT_MAX_BYTES`, `MAIL_LOG_UI_PAGE_SIZE`, `MAIL_LOG_UI_BRAND`, `MAIL_LOG_MORPH_ALIAS`.

### Compatibility

- PHP `^8.4`
- Laravel `^12` || `^13`
- `spatie/laravel-medialibrary ^11`
