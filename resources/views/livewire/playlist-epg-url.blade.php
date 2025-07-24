@php($urls = \App\Facades\PlaylistUrlFacade::getUrls($this->record))
@php($epgUrl = $urls['epg'])
@php($epgZippedUrl = $urls['epg_zip'])
<div>
    <div>
        <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
            Uncompressed EPG URL (XMLTV format)
        </span>
        <div class="flex gap-2 items-center justify-start">
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
            <x-qr-modal :title="$this->record->name" body="EPG URL" :text="$epgUrl" />
        </div>
        <div class="fi-fo-field-wrp-helper-text break-words text-sm text-gray-500 mt-1 mb-4">
            Use the following URL to access your EPG in XML format.
        </div>
    </div>

    <div>
        <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
            Compressed EPG URL (GZIP format)
        </span>
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
            <x-qr-modal :title="$this->record->name" body="EPG URL (compressed)" :text="$epgZippedUrl" />
        </div>
        <div class="fi-fo-field-wrp-helper-text break-words text-sm text-gray-500 mt-1 mb-4">
            Use the following URL to access your EPG in GZIP format.
        </div>
    </div>
</div>