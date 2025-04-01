<div>
    @php($model = $getRecord()->model)
    @php($playlistAuth = $getRecord()->playlistAuth)
    @php($auth = '?username=' . $playlistAuth->username . '&password=' . $playlistAuth->password)
    @php($m3uUrl = route('playlist.generate', ['uuid' => $model->uuid]) . $auth)
    @php($hdhrUrl = route('playlist.hdhr.overview', ['uuid' => $model->uuid]) . $auth)
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
