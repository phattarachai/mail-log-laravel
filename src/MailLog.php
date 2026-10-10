<?php

declare(strict_types=1);

namespace Phattarachai\MailLogLaravel;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;

class MailLog
{
    /**
     * Thai month abbreviations indexed by month number (1-12).
     *
     * @var array<int, string>
     */
    private const array THAI_MONTHS = [
        1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
        5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
        9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.',
    ];

    /**
     * Host-registered authorization gate. When null, falls back to APP_DEBUG.
     *
     * @var (Closure(Request): bool)|null
     */
    protected static ?Closure $authCallback = null;

    /**
     * Format a date as `19 พ.ค. 69 · 14:34`: day, Thai month abbreviation,
     * 2-digit Buddhist-era year, 24-hour time. Every dashboard date uses this
     * format; it is not configurable.
     */
    public static function dt(?CarbonInterface $date): string
    {
        if (! $date instanceof CarbonInterface) {
            return '—';
        }

        return sprintf(
            '%d %s %d · %s',
            $date->day,
            self::THAI_MONTHS[$date->month] ?? $date->shortMonthName,
            ($date->year + 543) % 100,
            $date->format('H:i'),
        );
    }

    /**
     * Cached, inlined CSS bundle for the dashboard layout.
     */
    public static function css(): HtmlString
    {
        $contents = self::readDistAsset('mail-log.css');

        if ($contents === '') {
            return new HtmlString('');
        }

        return new HtmlString('<style>'.$contents.'</style>');
    }

    /**
     * Cached, inlined JS bundle for the dashboard layout. Prepended with a
     * `window.MailLog` prelude so the bundled Alpine code can locate the
     * CSRF token + base path without DOM scraping.
     */
    public static function js(): HtmlString
    {
        $contents = self::readDistAsset('mail-log.js');

        if ($contents === '') {
            return new HtmlString('');
        }

        $prelude = sprintf(
            'window.MailLog = window.MailLog || {csrfToken: %s, basePath: %s};',
            json_encode(csrf_token(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            json_encode('/'.ltrim((string) config('mail-log.ui.path', 'mail-log'), '/'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );

        return new HtmlString('<script type="module">'.$prelude."\n".$contents.'</script>');
    }

    /**
     * Register the dashboard authorization callback. Called from the host's
     * AppServiceProvider::boot().
     *
     * @param  Closure(Request): bool  $callback
     */
    public static function auth(Closure $callback): void
    {
        static::$authCallback = $callback;
    }

    /**
     * Resolve the current request's authorization state. Default policy:
     * allow only when APP_DEBUG=true (loud, safe failure until host opts in).
     */
    public static function check(Request $request): bool
    {
        if (static::$authCallback instanceof Closure) {
            return (bool) (static::$authCallback)($request);
        }

        return (bool) config('app.debug', default: false);
    }

    /**
     * Test seam — drops any registered callback so the default policy applies.
     *
     * @internal
     */
    public static function flushAuth(): void
    {
        static::$authCallback = null;
    }

    private static function readDistAsset(string $filename): string
    {
        $path = __DIR__.'/../dist/'.$filename;

        if (! is_file($path)) {
            return '';
        }

        return (string) file_get_contents($path);
    }
}
