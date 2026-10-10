<?php

declare(strict_types=1);

namespace Phattarachai\MailLogLaravel\Support;

/**
 * Masks secrets (reset tokens, signed-URL signatures, verify hashes) in a
 * body before it is stored as a group's preview. Driven by
 * config('mail-log.redact').
 */
class BodyRedactor
{
    public function __invoke(?string $body): ?string
    {
        if ($body === null || $body === '' || ! $this->isEnabled()) {
            return $body;
        }

        $patterns = (array) config('mail-log.redact.patterns', []);

        if ($patterns === []) {
            return $body;
        }

        // Fail closed: a pattern that errors must not leave the secret in place.
        return preg_replace(array_keys($patterns), array_values($patterns), $body)
            ?? '[body withheld: mail-log.redact.patterns failed to apply]';
    }

    private function isEnabled(): bool
    {
        return filter_var(config('mail-log.redact.enabled', default: true), FILTER_VALIDATE_BOOL);
    }
}
