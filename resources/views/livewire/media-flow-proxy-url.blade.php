@php($urls = \App\Facades\PlaylistFacade::getMediaFlowProxyUrls($this->record))
@php($m3uUrl = $urls['m3u'])
<div>
     <div>
        <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
            MediaFlow Proxy URL
        </span>
        <div class="flex gap-2 items-center justify-start">
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
            <x-qr-modal :title="$this->record->name" body="MediaFlow Proxy URL" :text="$m3uUrl" />
        </div>
        <div class="fi-fo-field-wrp-helper-text break-words text-sm text-gray-500 mt-1 mb-4">
            Your MediaFlow Proxy generated links – to disable clear the MediaFlow Proxy values from the app <a href="{{ url('preferences?tab=-proxy-tab') }}" class="text-indigo-500 hover:underline hover:text-indigo-600 dark:hover:text-indigo-400">Settings</a> page.
        </div>
    </div>
</div>
