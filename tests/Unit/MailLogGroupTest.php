<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use Phattarachai\MailLogLaravel\Enums\MailLogStatus;
use Phattarachai\MailLogLaravel\Models\MailLog;
use Phattarachai\MailLogLaravel\Models\MailLogGroup;

it('creates a row from the factory with every expected column', function (): void {
    $group = MailLogGroup::factory()->create();

    expect($group->exists)->toBeTrue()
        ->and(strlen((string) $group->fingerprint))->toBe(64)
        ->and($group->subject)->not->toBeEmpty()
        ->and($group->latest_status)->toBe(MailLogStatus::Sent)
        ->and($group->sent_count)->toBe(1)
        ->and($group->failed_count)->toBe(0);

    expect(Schema::hasColumns($group->getTable(), [
        'id', 'fingerprint', 'subject', 'from', 'mailable_class',
        'notification_class', 'model_type', 'model_id', 'mailer',
        'html_body', 'text_body', 'sent_count', 'failed_count',
        'latest_status', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('exposes factory states for the common dashboard scenarios', function (): void {
    expect(MailLogGroup::factory()->pending()->create()->latest_status)->toBe(MailLogStatus::Pending)
        ->and(MailLogGroup::factory()->failed()->create()->latest_status)->toBe(MailLogStatus::Failed)
        ->and(MailLogGroup::factory()->merged()->create()->sent_count)->toBe(5)
        ->and(MailLogGroup::factory()->withFailures()->create())
        ->sent_count->toBe(4)
        ->failed_count->toBe(1)
        ->latest_status->toBe(MailLogStatus::Sent);
});

it('relates to events via group_id and ordered latest-first', function (): void {
    $group = MailLogGroup::factory()->create(['sent_count' => 0]);
    $older = MailLog::factory()->for($group, 'group')->create(['created_at' => now()->subHour()]);
    $newer = MailLog::factory()->for($group, 'group')->create(['created_at' => now()]);

    expect($group->events()->pluck('id')->all())->toBe([$newer->id, $older->id])
        ->and($group->latestEvent()->first()->id)->toBe($newer->id);
});

it('records a sent event by bumping sent_count + latest_status + touching updated_at', function (): void {
    $group = MailLogGroup::factory()->create([
        'sent_count' => 2,
        'failed_count' => 0,
        'latest_status' => MailLogStatus::Sent,
        'updated_at' => now()->subDays(5),
    ]);

    $event = MailLog::factory()->for($group, 'group')->create(['status' => MailLogStatus::Sent]);
    $before = $group->updated_at;

    $group->recordEvent($event);
    $group->refresh();

    expect($group->sent_count)->toBe(3)
        ->and($group->failed_count)->toBe(0)
        ->and($group->latest_status)->toBe(MailLogStatus::Sent)
        ->and($group->updated_at)->toBeGreaterThan($before);
});

it('records a failed event by bumping failed_count + flipping latest_status', function (): void {
    $group = MailLogGroup::factory()->create([
        'sent_count' => 3,
        'failed_count' => 0,
        'latest_status' => MailLogStatus::Sent,
    ]);

    $event = MailLog::factory()->for($group, 'group')->failed()->create();

    $group->recordEvent($event);
    $group->refresh();

    expect($group->sent_count)->toBe(3)
        ->and($group->failed_count)->toBe(1)
        ->and($group->latest_status)->toBe(MailLogStatus::Failed);
});

it('does not bump counters for pending events', function (): void {
    $group = MailLogGroup::factory()->create(['sent_count' => 0, 'failed_count' => 0]);
    $event = MailLog::factory()->for($group, 'group')->pending()->create();

    $group->recordEvent($event);
    $group->refresh();

    expect($group->sent_count)->toBe(0)
        ->and($group->failed_count)->toBe(0)
        ->and($group->latest_status)->toBe(MailLogStatus::Pending);
});

it('computes success rate as sent / (sent + failed)', function (): void {
    expect(MailLogGroup::factory()->make(['sent_count' => 0, 'failed_count' => 0])->successRate())->toBe(1.0)
        ->and(MailLogGroup::factory()->make(['sent_count' => 4, 'failed_count' => 1])->successRate())->toBe(0.8)
        ->and(MailLogGroup::factory()->make(['sent_count' => 0, 'failed_count' => 3])->successRate())->toBe(0.0);
});

it('cascade-deletes events when a group is deleted', function (): void {
    $group = MailLogGroup::factory()->create();
    MailLog::factory()->for($group, 'group')->count(3)->create();

    expect(MailLog::query()->count())->toBe(3);

    $group->delete();

    expect(MailLog::query()->count())->toBe(0);
});

it('prunes groups older than retention_days and leaves fresh groups alone', function (): void {
    config()->set('mail-log.retention_days', 30);

    $stale = MailLogGroup::factory()->create(['updated_at' => now()->subDays(60)]);
    $fresh = MailLogGroup::factory()->create(['updated_at' => now()->subDays(5)]);

    $pruned = $stale->prunable()->get();

    expect($pruned->pluck('id')->all())->toBe([$stale->id])
        ->and($pruned->contains('id', $fresh->id))->toBeFalse();
});

it('prunes nothing when retention_days is null', function (): void {
    config()->set('mail-log.retention_days');

    MailLogGroup::factory()->create(['updated_at' => now()->subYears(5)]);

    expect(MailLogGroup::factory()->make()->prunable()->count())->toBe(0);
});

it('registers a stable morph alias for the group model', function (): void {
    Relation::morphMap([], merge: false);

    MailLogGroup::registerMorphMap();

    expect(Relation::morphMap()['mail_log_group'])->toBe(MailLogGroup::class);
});

it('aggregates unique recipients across events', function (): void {
    $group = MailLogGroup::factory()->create(['sent_count' => 0]);
    MailLog::factory()->for($group, 'group')->create(['to' => ['a@example.com']]);
    MailLog::factory()->for($group, 'group')->create(['to' => ['b@example.com']]);
    MailLog::factory()->for($group, 'group')->create(['to' => ['a@example.com']]);

    $unique = $group->uniqueRecipients()->all();
    sort($unique);

    expect($unique)->toBe(['a@example.com', 'b@example.com']);
});

it('lists recipients from to, cc and bcc in the order they were first mailed', function (): void {
    $group = MailLogGroup::factory()->create();
    MailLog::factory()->for($group, 'group')->create([
        'to' => ['customer@example.com'],
        'cc' => ['warehouse@example.com'],
        'bcc' => ['audit@example.com'],
        'created_at' => now()->subHour(),
    ]);
    MailLog::factory()->for($group, 'group')->create([
        'to' => ['warehouse@example.com'],
        'created_at' => now(),
    ]);

    expect($group->uniqueRecipients()->all())
        ->toBe(['customer@example.com', 'warehouse@example.com', 'audit@example.com']);
});
