<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @php($record = $getRecord())
    @php($url = $record->url_custom ?? $record->url)
    @php($proxyUrl = App\Facades\ProxyFacade::getProxyUrlForChannel($record->id))
    @php($playerId = "channel_{$record->id}_preview")
    <div x-data="{ state: $wire.$entangle('{{ $getStatePath() }}'), player: null }">
        <div x-data x-init="
            player = videojs('{{ $playerId }}', { fluid: true, responsive: true });
        " x-on:close-modal.window="player.dispose();">
            <video id="{{ $playerId }}"
                class="video-js vjs-fluid vjs-16-9 vjs-default-skin" 
                preload="auto" data-setup="{}" controls>
                <source src="{{ $url }}" type="application/x-mpegURL">
            </video>
        </div>
    </div>
</x-dynamic-component>
