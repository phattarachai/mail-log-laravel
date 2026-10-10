@props(['type' => null, 'id' => null, 'linkable' => null, 'short' => false])

@php
    /** @var \Phattarachai\MailLogLaravel\Contracts\MailLogLinkable|null $linkable */
    $url = $linkable?->mailLogUrl();
    $fallback = $type && $id !== null && $id !== ''
        ? ($short ? class_basename($type) : $type).'#'.$id
        : null;
@endphp

{{-- Titles are host text (often Thai), so they use the sans font; type#id stays mono. --}}
@if ($linkable && $url)
    <a href="{{ $url }}" {{ $attributes->merge(['class' => 'font-sans text-sm text-zinc-800 underline decoration-zinc-300 underline-offset-2 hover:decoration-zinc-800']) }} title="{{ $linkable->mailLogTitle() }} ({{ $type }}#{{ $id }})">{{ $linkable->mailLogTitle() }}</a>
@elseif ($linkable)
    <span {{ $attributes->merge(['class' => 'font-sans text-sm text-zinc-800']) }} title="{{ $linkable->mailLogTitle() }} ({{ $type }}#{{ $id }})">{{ $linkable->mailLogTitle() }}</span>
@elseif ($fallback)
    <span {{ $attributes->merge(['class' => 'font-mono text-[11px] text-zinc-500']) }} title="{{ $type }}#{{ $id }}">{{ $fallback }}</span>
@else
    <span class="text-zinc-400">—</span>
@endif
