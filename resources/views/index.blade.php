@extends('mail-log::layout', ['title' => 'Inbox'])

@section('content')
    <div class="border-b border-zinc-200 bg-zinc-50/60">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-2 px-6 py-3">
            <form method="GET" action="{{ route('mail-log.index') }}" class="flex flex-1 flex-wrap items-center gap-2">
                <div class="relative">
                    <svg xmlns="http://www.w3.org/2000/svg" class="absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
                    <input
                        type="text"
                        name="search"
                        value="{{ $filters['search'] ?? '' }}"
                        class="h-9 w-80 max-w-full rounded-md border border-zinc-200 bg-white pl-8 pr-3 text-sm placeholder:text-zinc-400 focus:border-zinc-400 focus:outline-none focus:ring-2 focus:ring-zinc-100"
                        placeholder="Search subject, recipient or class"
                    >
                </div>

                <select
                    name="status"
                    class="h-9 rounded-md border border-zinc-200 bg-white px-2 text-sm text-zinc-700 focus:border-zinc-400 focus:outline-none focus:ring-2 focus:ring-zinc-100"
                    onchange="this.form.submit()"
                >
                    <option value="">All statuses</option>
                    <option value="sent" @selected(($filters['status'] ?? '') === 'sent')>Sent</option>
                    <option value="pending" @selected(($filters['status'] ?? '') === 'pending')>Pending</option>
                    <option value="failed" @selected(($filters['status'] ?? '') === 'failed')>Failed</option>
                </select>

                <label class="ml-2 inline-flex items-center gap-2 text-sm text-zinc-600">
                    <input
                        type="checkbox"
                        name="has_failures"
                        value="1"
                        @checked(!empty($filters['has_failures']))
                        class="h-4 w-4 rounded border-zinc-300 text-zinc-900 focus:ring-zinc-400"
                        onchange="this.form.submit()"
                    > Has failures
                </label>

                <button type="submit" class="hidden">Apply</button>

                @if (($filters['model'] ?? '') !== '')
                    <input type="hidden" name="model" value="{{ $filters['model'] }}">
                    <a href="{{ route('mail-log.index', array_filter(array_merge($filters, ['model' => null]))) }}" class="ml-2 inline-flex items-center gap-1 rounded-full bg-zinc-900 px-2.5 py-1 font-mono text-[11px] text-white hover:bg-zinc-700" title="Groups whose primary model is this model, or that have a send associated with it. Click to remove.">
                        model: {{ class_basename(\Illuminate\Support\Str::beforeLast($filters['model'], ':')) }}#{{ \Illuminate\Support\Str::afterLast($filters['model'], ':') }}
                        <span aria-hidden="true">×</span>
                    </a>
                @endif

                @if (!empty(array_filter($filters)))
                    <a href="{{ route('mail-log.index') }}" class="ml-1 text-xs text-zinc-500 underline-offset-2 hover:underline">Clear</a>
                @endif
            </form>

            <div class="ml-auto font-mono text-xs text-zinc-500">
                <span>{{ number_format($stats['groups']) }}</span> groups ·
                <span>{{ number_format($stats['sends']) }}</span> sends total
            </div>
        </div>
    </div>

    @if ($groups->isEmpty())
        <x-mail-log::empty-state :filtered="!empty(array_filter($filters))" />
    @else
        <div class="mx-auto max-w-7xl overflow-x-auto px-6">
            {{-- Fixed layout: these widths total 928px, so Subject takes the rest of the
                 1232px content box. Cells truncate rather than widen the table. Below
                 ~68rem the table scrolls inside this box. --}}
            <table class="w-full min-w-[68rem] table-fixed text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 text-left text-xs font-medium uppercase text-zinc-500">
                        <th class="w-36 whitespace-nowrap py-2.5 font-medium">Last sent</th>
                        <th class="w-24 px-3 py-2.5 font-medium">Status</th>
                        <th class="px-3 py-2.5 font-medium">Subject</th>
                        <th class="w-60 px-3 py-2.5 font-medium">Recipients</th>
                        <th class="w-36 px-3 py-2.5 font-medium">Mailable</th>
                        <th class="w-36 px-3 py-2.5 font-medium">Model</th>
                        <th class="w-20 px-3 py-2.5 font-medium">Mailer</th>
                        <th class="w-20 whitespace-nowrap px-3 py-2.5 text-right font-medium" title="Send count, pending included">× <span class="font-normal lowercase">sends</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @foreach ($groups as $group)
                        <x-mail-log::group-row :group="$group" />
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($groups->hasPages())
            <div class="border-t border-zinc-200 bg-zinc-50/60">
                <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-6 py-3 text-sm text-zinc-500">
                    {{ $groups->withQueryString()->links() }}
                </div>
            </div>
        @endif
    @endif
@endsection
