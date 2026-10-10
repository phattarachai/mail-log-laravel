<?php

declare(strict_types=1);

namespace Phattarachai\MailLogLaravel\Support;

use JsonException;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Header\Headers;

class Fingerprinter
{
    public function __invoke(Email $message, ?string $mailerName = null): string
    {
        $headers = $message->getHeaders();

        $override = $this->header($headers, 'X-Mail-Fingerprint');

        if ($override !== null) {
            return preg_match('/^[a-f0-9]{64}$/i', $override) === 1
                ? strtolower($override)
                : hash('sha256', $override);
        }

        $mode = $this->resolveMode($headers);

        $inputs = [];

        foreach ($mode as $name) {
            $value = $this->resolveInput($name, $message, $headers, $mailerName);

            if ($value !== null) {
                $inputs[$name] = $value;
            }
        }

        ksort($inputs);

        $canonical = '';

        foreach ($inputs as $name => $value) {
            $canonical .= $name.'='.$value.'|';
        }

        return hash('sha256', $canonical);
    }

    /**
     * @return array<int, string>
     */
    private function resolveMode(Headers $headers): array
    {
        $hasClassSignal = $this->header($headers, 'X-Mail-Class') !== null
            || $this->header($headers, 'X-Mail-Notification-Class') !== null;

        if (! $hasClassSignal) {
            return ['subject', 'mailer', 'body'];
        }

        $modeHeader = $this->header($headers, 'X-Mail-Fingerprint-Mode');

        if ($modeHeader !== null) {
            try {
                $decoded = json_decode($modeHeader, associative: true, flags: JSON_THROW_ON_ERROR);

                if (is_array($decoded) && $decoded !== []) {
                    return array_values(array_filter($decoded, is_string(...)));
                }
            } catch (JsonException) {
                // fall through to the config default
            }
        }

        $default = (array) config('mail-log.fingerprint.default_mode', ['class', 'model']);

        return array_values(array_filter($default, is_string(...)));
    }

    private function resolveInput(string $name, Email $message, Headers $headers, ?string $mailerName): ?string
    {
        return match ($name) {
            'class' => $this->header($headers, 'X-Mail-Class'),
            'notification_class' => $this->header($headers, 'X-Mail-Notification-Class'),
            'model' => $this->resolveModel($headers),
            'hints' => $this->resolveHints($headers),
            'subject' => (string) $message->getSubject(),
            'body' => $this->bodyHash($message),
            'mailer' => $mailerName ?? '',
            default => null,
        };
    }

    private function resolveModel(Headers $headers): ?string
    {
        $type = $this->header($headers, 'X-Mail-Model-Type');
        $id = $this->header($headers, 'X-Mail-Model-Id');

        if ($type === null || $id === null) {
            return null;
        }

        return $type.'|'.$id;
    }

    private function resolveHints(Headers $headers): ?string
    {
        $raw = $this->header($headers, 'X-Mail-Fingerprint-Hint');

        if ($raw === null) {
            return null;
        }

        try {
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $raw;
        }

        if (! is_array($decoded)) {
            return $raw;
        }

        $this->canonicalize($decoded);

        return json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Recursively normalize array order so hosts can't break dedup by
     * passing the same data in a different sequence.
     *
     * @param  array<int|string, mixed>  $data
     */
    private function canonicalize(array &$data): void
    {
        $isList = array_is_list($data);

        foreach ($data as &$value) {
            if (is_array($value)) {
                $this->canonicalize($value);
            }
        }
        unset($value);

        if ($isList) {
            sort($data);
        } else {
            ksort($data);
        }
    }

    private function bodyHash(Email $message): string
    {
        $html = (string) $message->getHtmlBody();
        $text = (string) $message->getTextBody();

        $patterns = (array) config('mail-log.fingerprint.body_strip_patterns', []);

        if ($patterns !== []) {
            $html = (string) preg_replace($patterns, '', $html);
            $text = (string) preg_replace($patterns, '', $text);
        }

        // The redaction patterns mask the same per-recipient secrets, so they
        // keep the hash stable even when redact.enabled is false or a published
        // config predates a strip pattern (e.g. /reset-password/{token}).
        $masks = (array) config('mail-log.redact.patterns', []);

        if ($masks !== []) {
            $html = (string) preg_replace(array_keys($masks), array_values($masks), $html);
            $text = (string) preg_replace(array_keys($masks), array_values($masks), $text);
        }

        return hash('sha256', $html."\n--\n".$text);
    }

    private function header(Headers $headers, string $name): ?string
    {
        $header = $headers->get($name);

        if (! $header instanceof HeaderInterface) {
            return null;
        }

        $value = $header->getBodyAsString();

        return $value === '' ? null : $value;
    }
}
