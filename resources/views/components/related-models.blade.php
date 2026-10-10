@props(['associations'])

@php
    /** @var \Illuminate\Support\Collection<int, \Phattarachai\MailLogLaravel\Models\MailLogAssociation> $associations */
@endphp

@if ($associations->contains(fn ($association) => ! $association->is_primary))
    <div {{ $attributes->merge(['class' => 'rounded-lg border border-zinc-200 bg-white']) }}>
        <div class="border-b border-zinc-100 px-4 py-2.5">
            <div class="text-[11px] uppercase text-zinc-500" title="The primary model and every model the sends in this group were associated with (mailLogModels())">Related models</div>
            <div class="mt-0.5 font-mono text-base font-semibold tracking-tight text-zinc-900">{{ $associations->count() }}</div>
        </div>
        <ul class="max-h-64 divide-y divide-zinc-100 overflow-y-auto text-sm">
            @foreach ($associations as $association)
                <li class="flex items-center justify-between gap-2 px-4 py-1.5">
                    <x-mail-log::model-link :type="$association->model_type" :id="$association->model_id" :linkable="$association->linkable()" class="min-w-0 truncate" />
                    <a href="{{ route('mail-log.index', ['model' => $association->filterValue()]) }}" class="shrink-0 whitespace-nowrap text-[11px] text-zinc-500 hover:text-zinc-900" title="Every group that relates to this model">All mail →</a>
                </li>
            @endforeach
        </ul>
    </div>
@endif
