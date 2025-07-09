<x-filament-widgets::widget>
    @if($isProcessing)
        <div class="w-full h-5 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden relative">
            <div class="absolute left-0 top-0 h-5 bg-primary-600 transition-all duration-700 ease-in-out" style="width: {{ $record->progress }}%"></div>
            <div class="absolute left-0 top-0 w-full h-5 flex items-center justify-center">
                <span class="text-xs font-medium text-primary-900 dark:text-primary-100 select-none">Sync Progress: <strong>{{ $record->progress }}%</strong></span>
            </div>
        </div>
    @endif
</x-filament-widgets::widget>
