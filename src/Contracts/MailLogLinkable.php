<?php

declare(strict_types=1);

namespace Phattarachai\MailLogLaravel\Contracts;

/**
 * Optional contract for Eloquent models attached to a MailLogGroup. Implement
 * it on the model to give the dashboard a deep link back into the host app:
 * the Model column on the index and the Model row on the group page render
 * mailLogTitle(), linked to mailLogUrl() when it returns a URL. Models without
 * the contract render as `Type#id` text.
 */
interface MailLogLinkable
{
    /**
     * Short, human-readable label for the model (e.g. order number, user name).
     */
    public function mailLogTitle(): string;

    /**
     * URL pointing back to the model's canonical detail page in the host app.
     */
    public function mailLogUrl(): ?string;
}
