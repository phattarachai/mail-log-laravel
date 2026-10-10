@props(['group'])

@php
    /** @var \Phattarachai\MailLogLaravel\Models\MailLogGroup $group */
    $recipients = $group->uniqueRecipients();
@endphp

<div {{ $attributes->merge(['class' => 'rounded-lg border border-zinc-200 bg-white']) }}>
    <div class="border-b border-zinc-100 px-4 py-2.5">
        <div class="text-[11px] uppercase text-zinc-500" title="Distinct to, cc and bcc addresses across every send, in the order they were first mailed">Recipients</div>
        <div class="mt-0.5 font-mono text-base font-semibold tracking-tight text-zinc-900">{{ $recipients->count() }}</div>
    </div>

    @if ($recipients->isEmpty())
        <p class="px-4 py-4 text-xs text-zinc-400">No recipients recorded yet.</p>
    @else
        <ul class="max-h-64 divide-y divide-zinc-100 overflow-y-auto text-sm">
            @foreach ($recipients as $recipient)
                <li class="flex items-center justify-between gap-2 px-4 py-1.5">
                    <span class="truncate text-zinc-700">{{ $recipient }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
