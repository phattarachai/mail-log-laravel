@props(['event'])

@php
    use Phattarachai\MailLogLaravel\Enums\MailLogStatus;
    use Phattarachai\MailLogLaravel\MailLog;

    /** @var \Phattarachai\MailLogLaravel\Models\MailLog $event */
    $primaryRecipient = collect($event->to ?? [])->first();
    $extraRecipients = max(0, count($event->allRecipients()) - 1);
    $extraModels = $event->relationLoaded('associations')
        ? $event->associations->reject(fn ($association) => $association->is_primary)
        : collect();
    $canResend = $event->isResendable()
        && filter_var(config('mail-log.resend.enabled', true), FILTER_VALIDATE_BOOL);
    $originalRecipients = collect($event->allRecipients())
        ->map(fn (string $recipient): string => \Symfony\Component\Mime\Address::create($recipient)->getAddress())
        ->implode(', ');
@endphp

<tr class="hover:bg-zinc-50">
    <td class="px-3 py-2 align-top">
        <x-mail-log::status-badge :status="$event->status" />
        @if ($event->resent_from_id)
            <div class="mt-1 whitespace-nowrap font-mono text-[10px] text-zinc-500" title="Resent from send #{{ $event->resent_from_id }}">↻ resent</div>
        @endif
    </td>
    <td class="px-3 py-2 align-top text-zinc-700">
        @if ($primaryRecipient)
            <div class="font-mono text-[12px]">{{ $primaryRecipient }}</div>
            @if ($extraRecipients > 0)
                <div class="font-mono text-[11px] text-zinc-400">+{{ $extraRecipients }} more</div>
            @endif
            @if ($extraModels->isNotEmpty())
                <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-zinc-500">
                    <span>Also:</span>
                    @foreach ($extraModels as $association)
                        <x-mail-log::model-link :type="$association->model_type" :id="$association->model_id" :linkable="$association->linkable()" short class="text-[11px]" />
                    @endforeach
                </div>
            @endif
        @else
            <span class="text-zinc-400">—</span>
        @endif
    </td>
    <td class="px-3 py-2 align-top font-mono text-[11px] text-zinc-500">{{ MailLog::dt($event->sent_at ?? $event->created_at) }}</td>
    <td class="px-3 py-2 align-top font-mono text-[11px] text-zinc-500">
        @if ($event->seconds !== null)
            {{ number_format($event->seconds, 3) }}s
        @else
            —
        @endif
    </td>
    <td class="px-3 py-2 align-top">
        @if ($event->status === MailLogStatus::Failed && $event->error_message)
            <button
                type="button"
                @click="toggle({{ $event->id }})"
                class="inline-flex items-center gap-1 text-[11px] font-medium text-rose-700 hover:underline"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
                <span x-text="isOpen({{ $event->id }}) ? 'Hide error' : 'View error'"></span>
            </button>
        @endif
        @if ($canResend)
            <button
                type="button"
                @click="toggleResend({{ $event->id }})"
                class="mt-1 inline-flex items-center gap-1 text-[11px] font-medium text-zinc-600 hover:text-zinc-900 hover:underline"
                title="Render this Mailable again and send it"
            >↻ Resend</button>
        @endif
    </td>
</tr>
@if ($canResend)
    <tr x-show="isResending({{ $event->id }})" x-cloak>
        <td colspan="5" class="px-3 pb-3">
            <form method="POST" action="{{ route('mail-log.resend', [$event->group_id, $event->id]) }}" class="flex flex-wrap items-center gap-2 rounded-md border border-zinc-200 bg-zinc-50 px-3 py-2">
                @csrf
                <label for="resend-{{ $event->id }}" class="text-[11px] text-zinc-600">Resend to</label>
                <input
                    id="resend-{{ $event->id }}"
                    type="text"
                    name="recipients"
                    value="{{ $originalRecipients }}"
                    class="h-8 min-w-0 flex-1 rounded-md border border-zinc-200 bg-white px-2 font-mono text-[12px] focus:border-zinc-400 focus:outline-none focus:ring-2 focus:ring-zinc-100"
                    placeholder="a@example.com, b@example.com"
                >
                <button type="submit" class="h-8 rounded-md bg-zinc-900 px-3 text-xs font-medium text-white hover:bg-zinc-700">Resend</button>
                <p class="basis-full text-[11px] text-zinc-500">Renders the Mailable again with current data and links, then sends it as a new send in this group. Leave the original recipients, or enter other addresses (up to 10).</p>
            </form>
        </td>
    </tr>
@endif
@if ($event->status === MailLogStatus::Failed && $event->error_message)
    <tr x-show="isOpen({{ $event->id }})" x-cloak>
        <td colspan="5" class="px-3 pb-3">
            <div class="rounded-md border border-rose-100 bg-rose-50 px-3 py-2 font-mono text-[11px] text-rose-700">
                {{ $event->error_message }}
            </div>
        </td>
    </tr>
@endif
