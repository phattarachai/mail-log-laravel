@props(['filtered' => false])

<div {{ $attributes->merge(['class' => 'mx-auto flex max-w-md flex-col items-center px-6 py-20 text-center']) }}>
    <div class="grid h-16 w-16 place-items-center rounded-full bg-zinc-100 text-zinc-400">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25H4.5a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/>
        </svg>
    </div>

    @if ($filtered)
        <h2 class="mt-4 text-base font-semibold text-zinc-900">No matching groups</h2>
        <p class="mt-1 text-sm text-zinc-500">Clear the filters or try a different search.</p>
        <a href="{{ route('mail-log.index') }}" class="mt-4 inline-flex items-center gap-1.5 rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50">Clear filters</a>
    @else
        <h2 class="mt-4 text-base font-semibold text-zinc-900">No mail sent yet</h2>
        <p class="mt-1 text-sm text-zinc-500">The first email this application sends appears here. Each group collects every send of the same Mailable + model.</p>

        @if (config('mail-log.test_send.enabled', true))
            <button
                type="button"
                @click="$dispatch('mail-log:test-send-open')"
                class="mt-4 inline-flex items-center gap-1.5 rounded-md bg-zinc-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-zinc-800"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"/></svg>
                Send a test email
            </button>
        @endif
    @endif
</div>
