@props(['group'])

@php
    use Phattarachai\MailLogLaravel\Enums\MailLogStatus;
    use Phattarachai\MailLogLaravel\MailLog;

    /** @var \Phattarachai\MailLogLaravel\Models\MailLogGroup $group */
    $recipients = $group->uniqueRecipients();
    $firstRecipient = $recipients->first();
    $extra = max(0, $recipients->count() - 1);
    $totalSends = $group->totalSends();
    $multi = $totalSends > 1;
    $firstSentLabel = $group->created_at?->isSameDay($group->updated_at)
        ? $group->created_at->format('H:i')
        : MailLog::dt($group->created_at);
    $latestError = $group->latest_status === MailLogStatus::Failed
        ? optional($group->latestEvent)->error_message
        : null;
@endphp

<tr class="hover:bg-zinc-50">
    <td class="whitespace-nowrap py-2.5 align-top">
        <a href="{{ route('mail-log.show', $group) }}" class="block font-mono">
            <div class="text-xs text-zinc-700">{{ MailLog::dt($group->updated_at) }}</div>
            @if ($multi)
                <div class="text-[10px] text-zinc-400">first {{ $firstSentLabel }}</div>
            @endif
        </a>
    </td>
    <td class="px-3 py-2.5 align-top">
        @if ($group->latest_status)
            <x-mail-log::status-badge :status="$group->latest_status" />
        @endif
        @if ($group->failed_count > 0 && $group->latest_status !== MailLogStatus::Failed)
            <div class="mt-1">
                <x-mail-log::failed-error-chip :count="$group->failed_count" />
            </div>
        @endif
    </td>
    <td class="px-3 py-2.5 align-top">
        <a href="{{ route('mail-log.show', $group) }}" class="font-medium text-zinc-900 hover:underline">
            {{ $group->subject ?: '(no subject)' }}
        </a>
        @if ($latestError)
            <x-mail-log::failed-error-chip :message="$latestError" />
        @endif
    </td>
    <td class="px-3 py-2.5 align-top text-zinc-600">
        @if ($firstRecipient)
            <div class="flex min-w-0 items-center gap-1">
                <span class="truncate" title="{{ $firstRecipient }}">{{ $firstRecipient }}</span>
                @if ($extra > 0)
                    <span class="inline-flex h-5 shrink-0 items-center whitespace-nowrap rounded-full bg-zinc-100 px-1.5 font-mono text-[11px] text-zinc-600">+{{ $extra }} more</span>
                @endif
            </div>
        @else
            <span class="text-zinc-400">—</span>
        @endif
    </td>
    <td class="px-3 py-2.5 align-top">
        @if ($group->mailable_class)
            <span class="block truncate font-mono text-[11px] text-zinc-500" title="{{ $group->mailable_class }}">{{ class_basename($group->mailable_class) }}</span>
        @elseif ($group->notification_class)
            <span class="block truncate font-mono text-[11px] text-zinc-500" title="{{ $group->notification_class }}">{{ class_basename($group->notification_class) }}</span>
        @else
            <span class="text-zinc-400">—</span>
        @endif
    </td>
    <td class="whitespace-nowrap px-3 py-2.5 align-top text-zinc-600">
        <x-mail-log::model-link :group="$group" short class="block truncate" />
    </td>
    <td class="px-3 py-2.5 align-top">
        @if ($group->mailer)
            <span class="inline-block max-w-full truncate rounded-md bg-zinc-100 px-1.5 py-0.5 align-top font-mono text-[11px] text-zinc-600" title="{{ $group->mailer }}">{{ $group->mailer }}</span>
        @else
            <span class="text-zinc-400">—</span>
        @endif
    </td>
    <td class="px-3 py-2.5 text-right align-top font-mono text-[11px] text-zinc-500">{{ $totalSends }}</td>
</tr>
