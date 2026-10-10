@extends('mail-log::layout', ['title' => $group->subject ?: 'Group'])

@section('content')
    <div class="border-b border-zinc-200 bg-white">
        <div class="mx-auto flex max-w-7xl items-center gap-3 px-6 py-3 text-sm">
            <a href="{{ route('mail-log.index') }}" class="inline-flex items-center gap-1.5 text-zinc-500 hover:text-zinc-900">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
                {{ config('mail-log.ui.brand', 'Mail Log') }}
            </a>
            <span class="text-zinc-300">/</span>
            <span class="truncate font-medium">{{ $group->subject ?: '(no subject)' }}</span>
            <span class="ml-2 inline-flex items-center rounded-md bg-zinc-100 px-1.5 py-0.5 font-mono text-[10px] uppercase text-zinc-500" title="This page represents one group; sends below are its events.">group</span>

            <div class="ml-auto flex items-center gap-2">
                <form method="POST" action="{{ route('mail-log.destroy', $group) }}" onsubmit="return confirm('Delete this group and all its events?')">
                    @csrf
                    @method('DELETE')
                    <button
                        type="submit"
                        class="inline-flex items-center gap-1.5 rounded-md border border-rose-200 bg-white px-2.5 py-1.5 text-xs font-medium text-rose-700 hover:bg-rose-50"
                        title="Deletes the group and every send under it"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                        Delete group
                    </button>
                </form>
            </div>
        </div>
    </div>

    <x-mail-log::stats-strip :group="$group" />

    <div class="mx-auto grid max-w-7xl gap-6 px-6 py-6 lg:grid-cols-[1fr_360px]">
        <div class="space-y-6">
            <x-mail-log::body-preview :group="$group" />

            <div>
                <div class="mb-2 flex items-center justify-between gap-2">
                    <h3 class="text-[11px] font-semibold uppercase text-zinc-500">Sends ({{ $events->total() }})</h3>
                </div>

                <div x-data="sendsTable()" class="rounded-lg border border-zinc-200 bg-white">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-100 text-left text-[11px] font-medium uppercase text-zinc-500">
                                <th class="px-3 py-2 font-medium">Status</th>
                                <th class="px-3 py-2 font-medium">Recipient</th>
                                <th class="px-3 py-2 font-medium" title="When the send was confirmed; the attempt time for pending and failed sends">Time</th>
                                <th class="px-3 py-2 font-medium">Duration</th>
                                <th class="px-3 py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100">
                            @foreach ($events as $event)
                                <x-mail-log::event-row :event="$event" />
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($events->hasPages())
                    <div class="mt-3">
                        {{ $events->withQueryString()->links() }}
                    </div>
                @endif
            </div>
        </div>

        <aside class="space-y-4">
            <div class="rounded-lg border border-zinc-200 bg-white">
                <div class="border-b border-zinc-100 px-4 py-2.5">
                    <div class="text-[11px] uppercase text-zinc-500">Metadata</div>
                </div>
                <dl class="divide-y divide-zinc-100 text-sm">
                    <div class="grid grid-cols-3 gap-2 px-4 py-2">
                        <dt class="text-xs text-zinc-500">Mailable</dt>
                        <dd class="col-span-2 truncate font-mono text-xs text-zinc-800">{{ $group->mailable_class ?: '—' }}</dd>
                    </div>
                    @if ($group->notification_class)
                        <div class="grid grid-cols-3 gap-2 px-4 py-2">
                            <dt class="text-xs text-zinc-500">Notification</dt>
                            <dd class="col-span-2 truncate font-mono text-xs text-zinc-800">{{ $group->notification_class }}</dd>
                        </div>
                    @endif
                    <div class="grid grid-cols-3 gap-2 px-4 py-2">
                        <dt class="text-xs text-zinc-500">Mailer</dt>
                        <dd class="col-span-2 font-mono text-xs text-zinc-800">{{ $group->mailer ?: '—' }}</dd>
                    </div>
                    <div class="grid grid-cols-3 gap-2 px-4 py-2">
                        <dt class="text-xs text-zinc-500">From</dt>
                        <dd class="col-span-2 truncate font-mono text-xs text-zinc-800">{{ $group->from ?: '—' }}</dd>
                    </div>
                    <div class="grid grid-cols-3 gap-2 px-4 py-2">
                        <dt class="text-xs text-zinc-500">Model</dt>
                        <dd class="col-span-2 flex min-w-0 items-center justify-between gap-2 font-mono text-xs text-zinc-800">
                            <x-mail-log::model-link :type="$group->model_type" :id="$group->model_id" :linkable="$group->linkable()" class="min-w-0 truncate" />
                            @if ($group->model_type && $group->model_id)
                                <a href="{{ route('mail-log.index', ['model' => $group->model_type.':'.$group->model_id]) }}" class="shrink-0 whitespace-nowrap font-sans text-[11px] text-zinc-500 hover:text-zinc-900" title="Every group that relates to this model">All mail →</a>
                            @endif
                        </dd>
                    </div>
                    <div class="grid grid-cols-3 gap-2 px-4 py-2">
                        <dt class="text-xs text-zinc-500">Fingerprint</dt>
                        <dd class="col-span-2 break-all font-mono text-[10px] text-zinc-500">{{ $group->fingerprint }}</dd>
                    </div>
                </dl>
            </div>

            <x-mail-log::related-models :associations="$relatedModels" />

            <x-mail-log::recipients-list :group="$group" />

            <x-mail-log::attachments-list :group="$group" />
        </aside>
    </div>
@endsection
