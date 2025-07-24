@php($urls = \App\Facades\PlaylistUrlFacade::getUrls($this->record))
@php($m3uUrl = $urls['m3u'])
@php($hdhrUrl = $urls['hdhr'])
<div>
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
        <div class="fi-fo-field-wrp-helper-text break-words text-sm text-gray-500 mt-1 mb-4">
            Use the following URL to access your playlist in M3U format.
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
            Use the following URL to access your playlist in HDHR format, for players like Plex and Jellyfin.
        </div>
    </div>
</div>
