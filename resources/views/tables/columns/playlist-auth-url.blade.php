<div>
    @php($model = $getRecord()->model)
    @php($urls = \App\Facades\PlaylistUrlFacade::getUrls($model))
    @php($m3uUrl = $urls['m3u'])
    @php($hdhrUrl = $urls['hdhr'])
    <div class="px-3 py-2 flex flex-col gap-4">
        <div class="text-sm flex flex-col">
            <p class="font-bold">
                M3U URL
            </p>
            <a href="{{ $m3uUrl }}"
                target="_blank"         
                class="underline flex items-center gap-1 text-primary-500 hover:text-primary-700 dark:hover:text-primary-300">
                {{ $m3uUrl }}
            </a>
        </div>
        <div class="text-sm flex flex-col">
            <p class="font-bold">
                HDHR URL
            </p>
            <a href="{{ $hdhrUrl }}"
                target="_blank"         
                class="underline flex items-center gap-1 text-primary-500 hover:text-primary-700 dark:hover:text-primary-300">
                {{ $hdhrUrl }}
            </a>
        </div>
    </div>
</div>
