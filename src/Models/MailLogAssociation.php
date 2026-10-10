<?php

declare(strict_types=1);

namespace Phattarachai\MailLogLaravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Override;
use Phattarachai\MailLogLaravel\Contracts\MailLogLinkable;
use Throwable;

/**
 * One model a send relates to: the Mailable's mailLogModel() (is_primary)
 * or one of its mailLogModels().
 *
 * @property int $id
 * @property int $event_id
 * @property string $model_type
 * @property string $model_id
 * @property bool $is_primary
 * @property-read MailLog|null $event
 */
class MailLogAssociation extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    #[Override]
    public function getTable(): string
    {
        return (string) config('mail-log.tables.associations', 'mail_log_associations');
    }

    /**
     * @return BelongsTo<MailLog, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(MailLog::class, 'event_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The host model when it implements MailLogLinkable. Never throws: a
     * missing class or a blocked lazy load renders as type#id text.
     */
    public function linkable(): ?MailLogLinkable
    {
        try {
            $model = $this->getRelationValue('model');
        } catch (Throwable) {
            return null;
        }

        return $model instanceof MailLogLinkable ? $model : null;
    }

    /**
     * The value of the index page's ?model= filter for this model.
     */
    public function filterValue(): string
    {
        return $this->model_type.':'.$this->model_id;
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }
}
