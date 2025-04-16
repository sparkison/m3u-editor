<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @php($record = $getRecord())
    @php($urls = \App\Facades\PlaylistUrlFacade::getUrls($record))
    @php($epgUrl = $urls['epg'])
    <div x-data="{ state: $wire.$entangle('{{ $getStatePath() }}') }">
        <div class="flex gap-2">
            <div class="">
                <a href="{{ $epgUrl }}" target="_blank"
                    class="underline flex items-center gap-1 text-primary-500 hover:text-primary-700 dark:hover:text-primary-300">
                    {{ $epgUrl }}
                </a>
                <small class="fi-fo-field-wrp-helper-text break-words text-sm text-gray-500">Filename: <strong>{{ Str::slug($getRecord()->name) . '.xml' }}</strong></small>
            </div>
            <x-qr-modal :title="$record->name" body="EPG URL" :text="$epgUrl" />
        </div>
    </div>
</x-dynamic-component>
