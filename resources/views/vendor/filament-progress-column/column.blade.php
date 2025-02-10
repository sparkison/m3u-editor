@php
$color = $getColor();
$barStyles = \Filament\Support\get_color_css_variables(
    $color,
    shades: [600],
);
$progress = $getProgress();
$poll = $getPoll();
@endphp

<div
    class="w-full filament-tables-progress-column"
    @if($poll)
        wire:poll.{{ $poll }}
    @endif
>
    <div class="flex items-center space-x-4 px-4">
        <div class="bg-gray-200 rounded-full h-2.5 dark:bg-gray-600 w-full">
            <div style="{{ $barStyles }}; width: {{ min($progress, 100) }}%" class="h-2.5 rounded-full bg-custom-600"></div>
        </div>

        <span class="text-sm text-gray-700 dark:text-gray-200">{{ $progress }}%</span>
    </div>
</div>
