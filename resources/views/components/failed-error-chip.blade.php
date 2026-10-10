@props(['message' => null, 'count' => null])

@if ($count !== null && $count > 0)
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 whitespace-nowrap rounded-md bg-rose-50 px-1.5 py-0.5 text-[10px] font-medium text-rose-700']) }}>
        <svg xmlns="http://www.w3.org/2000/svg" class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
        {{ $count }} failed
    </span>
@endif

@if ($message)
    <div {{ $attributes->merge(['class' => 'mt-1 flex max-w-full items-start gap-1.5 rounded-md border border-rose-100 bg-rose-50/60 px-2 py-1 text-[11px] text-rose-700']) }}>
        <svg xmlns="http://www.w3.org/2000/svg" class="mt-0.5 h-3 w-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
        <span class="line-clamp-4 min-w-0 break-words" title="{{ $message }}">{{ $message }}</span>
    </div>
@endif
