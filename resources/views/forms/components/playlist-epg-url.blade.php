<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @php($record = $getRecord())
    @php($urls = \App\Facades\PlaylistUrlFacade::getUrls($record))
    @php($epgUrl = $urls['epg'])
    @php($epgZippedUrl = $urls['epg_zip'])
    @php($epgCacheModalId = 'epg-url-modal-' . $record->getKey())
    <div x-data="{ state: $wire.$entangle('{{ $getStatePath() }}') }">
        <div class="flex gap-2 items-center justify-start mb-4">
            <x-filament::input.wrapper>
                <x-slot name="prefix">
                    <x-copy-to-clipboard :text="$epgUrl" />
                </x-slot>
                <x-filament::input
                    type="text"
                    :value="$epgUrl"
                    readonly
                />
                <x-slot name="suffix">
                    .xml
                </x-slot>
            </x-filament::input.wrapper>
            <x-qr-modal :title="$record->name" body="EPG URL" :text="$epgUrl" />
        </div>
        <div class="flex gap-2 items-center justify-start">
            <x-filament::input.wrapper>
                <x-slot name="prefix">
                    <x-copy-to-clipboard :text="$epgZippedUrl" />
                </x-slot>
                <x-filament::input
                    type="text"
                    :value="$epgZippedUrl"
                    readonly
                />
                <x-slot name="suffix">
                    .xml.gz
                </x-slot>
            </x-filament::input.wrapper>
            <x-qr-modal :title="$record->name" body="EPG URL (compressed)" :text="$epgZippedUrl" />
        </div>
    </div>

    <x-filament::modal id="{{ $epgCacheModalId }}" icon="heroicon-o-trash" icon-color="warning" alignment="center">
        <x-slot name="trigger">
            <x-filament::button icon="heroicon-o-trash" color="gray" size="xs">
                Clear Playlist EPG File Cache
            </x-filament::button>
        </x-slot>

        <x-slot name="heading">
            Clear Playlist EPG File Cache
        </x-slot>

        Clear the EPG file cache for this playlist? It will be automatically regenerated on the next download.

        <x-slot name="footer">
            <div class="grid grid-cols-2 gap-2 w-full">
                <x-filament::button
                    wire:click="$dispatch('close-modal', { id: '{{ $epgCacheModalId }}' })"
                    label="Cancel"
                    color="gray"
                    class="w-full"
                >
                    Cancel
                </x-filament::button>
                <x-filament::button
                    wire:click="clearEpgFileCache"
                    label="Clear Playlist EPG File Cache"
                    color="warning"
                    class="w-full"
                >
                    Confirm
                </x-filament::button>
            </div>
        </x-slot>
    </x-filament::modal>
</x-dynamic-component>
