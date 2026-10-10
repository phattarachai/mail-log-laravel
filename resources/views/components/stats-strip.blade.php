@props(['group'])

@php
    use Phattarachai\MailLogLaravel\MailLog;

    /** @var \Phattarachai\MailLogLaravel\Models\MailLogGroup $group */
    $rate = (int) round($group->successRate() * 100);
    $rateColor = match (true) {
        $rate >= 99 => 'text-emerald-600',
        $rate >= 90 => 'text-amber-600',
        default => 'text-rose-600',
    };
@endphp

<div {{ $attributes->merge(['class' => 'border-b border-zinc-200 bg-zinc-50/60']) }}>
    <div class="mx-auto grid max-w-7xl grid-cols-2 gap-6 px-6 py-3 text-sm sm:grid-cols-3 lg:grid-cols-6">
        <div>
            <div class="text-[11px] uppercase text-zinc-500">Status</div>
            <div class="mt-1 flex items-center gap-1.5">
                @if ($group->latest_status)
                    <x-mail-log::status-badge :status="$group->latest_status" />
                @else
                    <span class="text-zinc-400">—</span>
                @endif
            </div>
        </div>
        <div>
            <div class="text-[11px] uppercase text-zinc-500">Sends</div>
            <div class="mt-1 font-mono text-xl font-semibold tracking-tight text-zinc-900">{{ $group->totalSends() }}</div>
            @if (($pending = $group->pendingSends()) > 0)
                <div class="text-[11px] text-amber-700">{{ $pending }} pending</div>
            @endif
        </div>
        <div>
            <div class="text-[11px] uppercase text-zinc-500">Failed</div>
            <div class="mt-1 font-mono text-xl font-semibold tracking-tight {{ $group->failed_count > 0 ? 'text-rose-600' : 'text-zinc-400' }}">{{ $group->failed_count }}</div>
        </div>
        <div>
            <div class="text-[11px] uppercase text-zinc-500">Success rate</div>
            <div class="mt-1 font-mono text-xl font-semibold tracking-tight {{ $rateColor }}">{{ $rate }}%</div>
        </div>
        <div>
            <div class="text-[11px] uppercase text-zinc-500">First sent</div>
            <div class="mt-1 font-mono text-xs text-zinc-700">{{ MailLog::dt($group->created_at) }}</div>
        </div>
        <div>
            <div class="text-[11px] uppercase text-zinc-500">Last sent</div>
            <div class="mt-1 font-mono text-xs text-zinc-700">{{ MailLog::dt($group->updated_at) }}</div>
        </div>
    </div>
</div>
