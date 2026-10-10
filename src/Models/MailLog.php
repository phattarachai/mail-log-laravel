<?php

declare(strict_types=1);

namespace Phattarachai\MailLogLaravel\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Override;
use Phattarachai\MailLogLaravel\Database\Factories\MailLogFactory;
use Phattarachai\MailLogLaravel\Enums\MailLogStatus;

/**
 * @property int $id
 * @property int $group_id
 * @property array<int, string> $to
 * @property array<int, string>|null $cc
 * @property array<int, string>|null $bcc
 * @property MailLogStatus $status
 * @property string|null $error_message
 * @property float|null $seconds
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 * @property-read MailLogGroup|null $group
 */
class MailLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    #[Override]
    public function getTable(): string
    {
        return (string) config('mail-log.tables.events', 'mail_logs');
    }

    /**
     * Append-only outcome records have no updated_at.
     */
    #[Override]
    public function getUpdatedAtColumn(): ?string
    {
        return null;
    }

    /**
     * @return BelongsTo<MailLogGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(MailLogGroup::class, 'group_id');
    }

    /**
     * Settle this send as Failed and count it on the group, atomically.
     */
    public function markFailed(string $message): void
    {
        DB::transaction(function () use ($message): void {
            $this->update([
                'status' => MailLogStatus::Failed,
                'error_message' => Str::limit($message, 500),
            ]);

            $this->group?->recordEvent($this);
        });
    }

    /**
     * Every recipient of this send: to, then cc, then bcc.
     *
     * @return array<int, string>
     */
    public function allRecipients(): array
    {
        return [...$this->to ?? [], ...$this->cc ?? [], ...$this->bcc ?? []];
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'status' => MailLogStatus::class,
            'sent_at' => 'datetime',
            'seconds' => 'float',
        ];
    }

    protected static function newFactory(): Factory
    {
        return MailLogFactory::new();
    }
}
