<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @php($record = $getRecord())
    @php($urls = \App\Facades\PlaylistUrlFacade::getMediaFlowProxyUrls($record))
    @php($m3uUrl = $urls['m3u'])
    <div class="flex gap-2">
        <div x-data="{ state: $wire.$entangle('{{ $getStatePath() }}') }">
            <a href="{{ $m3uUrl }}" target="_blank"
                class="underline flex items-center gap-1 text-primary-500 hover:text-primary-700 dark:hover:text-primary-300">
                {{ $m3uUrl }}
            </a>
        </div>
        <x-qr-modal :title="$record->name" body="MediaFlow Proxy URL" :text="$m3uUrl" />
    </div>
</x-dynamic-component>
