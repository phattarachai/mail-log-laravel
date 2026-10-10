@props(['group', 'short' => false])

@php
    /** @var \Phattarachai\MailLogLaravel\Models\MailLogGroup $group */
    $linkable = $group->linkable();
    $url = $linkable?->mailLogUrl();
    $fallback = $group->model_type && $group->model_id
        ? ($short ? class_basename($group->model_type) : $group->model_type).'#'.$group->model_id
        : null;
@endphp

@if ($linkable && $url)
    <a href="{{ $url }}" class="text-zinc-800 underline decoration-zinc-300 underline-offset-2 hover:decoration-zinc-800" title="{{ $group->model_type }}#{{ $group->model_id }}">{{ $linkable->mailLogTitle() }}</a>
@elseif ($linkable)
    <span title="{{ $group->model_type }}#{{ $group->model_id }}">{{ $linkable->mailLogTitle() }}</span>
@elseif ($fallback)
    <span title="{{ $group->model_type }}#{{ $group->model_id }}">{{ $fallback }}</span>
@else
    <span class="text-zinc-400">—</span>
@endif
