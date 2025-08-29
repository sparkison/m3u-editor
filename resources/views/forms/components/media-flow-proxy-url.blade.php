<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @php($record = $getRecord())
    @php($urls = \App\Facades\PlaylistFacade::getMediaFlowProxyUrls($record))
    @php($m3uUrl = $urls['m3u'])
    <div class="flex gap-2 items-center justify-start mb-4">
        <x-filament::input.wrapper suffix-icon="heroicon-o-globe-alt">
            <x-slot name="prefix">
                <x-copy-to-clipboard :text="$m3uUrl" />
            </x-slot>
            <x-filament::input
                type="text"
                :value="$m3uUrl"
                readonly
            />
        </x-filament::input.wrapper>
        <x-qr-modal :title="$record->name" body="MediaFlow Proxy URL" :text="$m3uUrl" />
    </div>
</x-dynamic-component>
