<div class="w-full">
    @php($rows = $getState())
    @if(is_array($rows))
        <div class="space-y-1 w-full max-h-48 overflow-y-auto">
            @foreach($rows as $key => $value)
                <div class="w-full flex flex-col sm:flex-row sm:justify-between sm:items-center gap-1 sm:gap-x-2 text-xs bg-gray-100 dark:bg-gray-800 rounded px-2 py-1">
                    <span class="font-medium text-gray-700 dark:text-gray-300 flex-shrink-0">{{ $key }}:</span>
                    <span class="text-gray-900 dark:text-gray-100 font-mono break-all sm:text-right">{{ $value }}</span>
                </div>
            @endforeach
        </div>
    @endif
</div>