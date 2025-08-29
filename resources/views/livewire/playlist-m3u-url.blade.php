@php($urls = \App\Facades\PlaylistFacade::getUrls($this->record))
@php($m3uUrl = $urls['m3u'])
@php($hdhrUrl = $urls['hdhr'])
@php($publicUrl = url('/playlist/' . $this->record->uuid))
<div class="space-y-6">
    <div>
        <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
            M3U URL
        </span>
        <div class="flex gap-2 items-center justify-start">
            <x-filament::input.wrapper>
                <x-slot name="prefix">
                    <x-copy-to-clipboard :text="$m3uUrl" />
                </x-slot> 
                <x-filament::input
                    type="text"
                    :value="$m3uUrl"
                    readonly
                />
                <x-slot name="suffix">
                    .m3u
                </x-slot>
            </x-filament::input.wrapper>
            <x-qr-modal :title="$this->record->name" body="M3U URL" :text="$m3uUrl" />
        </div>
        <div class="fi-fo-field-wrp-helper-text break-words text-sm text-gray-500 mt-1">
            Access playlist in M3U format.
        </div>
    </div>
    
    <div>
        <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
            HDHR URL
        </span>
        <div class="flex gap-2 items-center justify-start">
            <x-filament::input.wrapper>
                <x-slot name="prefix">
                    <x-copy-to-clipboard :text="$hdhrUrl" />
                </x-slot>
                <x-filament::input
                    type="text"
                    :value="$hdhrUrl"
                    readonly
                />
                <x-slot name="suffix">
                    hdhr
                </x-slot>
            </x-filament::input.wrapper>
            <x-qr-modal :title="$this->record->name" body="HDHR URL" :text="$hdhrUrl" />
        </div>
        <div class="fi-fo-field-wrp-helper-text break-words text-sm text-gray-500 mt-1">
            Access playlist in HDHR format, for players like Plex and Jellyfin.
        </div>
    </div>

    <div>
        <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
            Public URL
        </span>
        <div class="flex gap-2 items-center justify-start">
            <x-filament::input.wrapper suffix-icon="heroicon-o-globe-alt">
                <x-slot name="prefix">
                    <x-copy-to-clipboard :text="$publicUrl" />
                </x-slot> 
                <x-filament::input
                    type="text"
                    :value="$publicUrl"
                    readonly
                />
            </x-filament::input.wrapper>
            <x-qr-modal :title="$this->record->name" body="Public URL" :text="$publicUrl" />
        </div>
        <div class="fi-fo-field-wrp-helper-text break-words text-sm text-gray-500 mt-1">
            Access public page for this playlist (Xtream API authentication required).
        </div>
    </div>
</div>
