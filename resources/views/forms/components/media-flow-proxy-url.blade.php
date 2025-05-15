<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @php($record = $getRecord())
    @php($urls = \App\Facades\PlaylistUrlFacade::getMediaFlowProxyUrls($record))
    @php($m3uUrl = $urls['m3u'])
    <div class="flex gap-2 items-center justify-start mb-4">
        <x-filament::input.wrapper prefix-icon="heroicon-o-globe-alt">
            <x-filament::input
                type="text"
                :value="$m3uUrl"
                readonly
            />
        </x-filament::input.wrapper>
        <x-qr-modal :title="$record->name" body="MediaFlow Proxy URL" :text="$m3uUrl" />
    </div>
</x-dynamic-component>
