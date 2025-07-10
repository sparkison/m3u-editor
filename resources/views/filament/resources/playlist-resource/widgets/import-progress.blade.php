<x-filament-widgets::widget>
    @if($processing && $progress < 100)
        <div class="w-full h-5 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden relative mb-2">
            <div class="absolute left-0 top-0 h-5 bg-primary-600 transition-all duration-700 ease-in-out" style="width: {{ $progress }}%"></div>
            <div class="absolute left-0 top-0 w-full h-5 flex items-center justify-center">
                <span class="text-xs font-medium text-primary-900 dark:text-primary-100 select-none">Channel Sync Progress: <strong>{{ $progress }}%</strong></span>
            </div>
        </div>
    @endif
    @if($processing && $seriesProgress < 100)
        <div class="w-full h-5 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden relative">
            <div class="absolute left-0 top-0 h-5 bg-primary-600 transition-all duration-700 ease-in-out" style="width: {{ $seriesProgress }}%"></div>
            <div class="absolute left-0 top-0 w-full h-5 flex items-center justify-center">
                <span class="text-xs font-medium text-primary-900 dark:text-primary-100 select-none">Series Sync Progress: <strong>{{ $seriesProgress }}%</strong></span>
            </div>
        </div>
    @endif
</x-filament-widgets::widget>
