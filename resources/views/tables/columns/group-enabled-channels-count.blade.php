<div class="fi-ta-text-item-label text-sm leading-6 text-gray-950 dark:text-white">
    {{ $getRecord()->channels()->where('enabled', true)->count() }}
</div>
