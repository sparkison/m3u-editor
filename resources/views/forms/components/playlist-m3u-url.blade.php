<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @php($record = $getRecord())
    @php($urls = \App\Facades\PlaylistUrlFacade::getUrls($record))
    @php($m3uUrl = $urls['m3u'])
    @php($hdhrUrl = $urls['hdhr'])
    <div x-data="{ state: $wire.$entangle('{{ $getStatePath() }}') }">
        <div class="flex gap-2">
            <div class="">
                <a href="{{ $m3uUrl }}" target="_blank"
                    class="underline flex items-center gap-1 text-primary-500 hover:text-primary-700 dark:hover:text-primary-300">
                    @if($urls['authEnabled'])
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="w-4 h-4">
                            <path fill-rule="evenodd" d="M8 1a3.5 3.5 0 0 0-3.5 3.5V7A1.5 1.5 0 0 0 3 8.5v5A1.5 1.5 0 0 0 4.5 15h7a1.5 1.5 0 0 0 1.5-1.5v-5A1.5 1.5 0 0 0 11.5 7V4.5A3.5 3.5 0 0 0 8 1Zm2 6V4.5a2 2 0 1 0-4 0V7h4Z" clip-rule="evenodd" />
                        </svg>
                    @endif
                    {{ $m3uUrl }}
                </a>
                <small class="fi-fo-field-wrp-helper-text break-words text-sm text-gray-500">Filename: <strong>{{ Str::slug($getRecord()->name) . '.m3u' }}</strong></small>
            </div>
            <x-qr-modal :title="$record->name" body="M3U URL" :text="$m3uUrl" />
        </div>
        <div class="flex gap-2">
            <div class="">
                <a href="{{ $hdhrUrl }}" target="_blank"
                class="underline flex items-center gap-1 text-primary-500 hover:text-primary-700 dark:hover:text-primary-300">
                    @if($urls['authEnabled'])
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="w-4 h-4">
                            <path fill-rule="evenodd" d="M8 1a3.5 3.5 0 0 0-3.5 3.5V7A1.5 1.5 0 0 0 3 8.5v5A1.5 1.5 0 0 0 4.5 15h7a1.5 1.5 0 0 0 1.5-1.5v-5A1.5 1.5 0 0 0 11.5 7V4.5A3.5 3.5 0 0 0 8 1Zm2 6V4.5a2 2 0 1 0-4 0V7h4Z" clip-rule="evenodd" />
                        </svg>
                    @endif
                {{ $hdhrUrl }}
                </a>
                <small class="fi-fo-field-wrp-helper-text break-words text-sm text-gray-500">HDHR compatible url (for players like <strong>Plex</strong>)</small>
            </div>
            <x-qr-modal :title="$record->name" body="HDHR URL" :text="$hdhrUrl" />
        </div>
    </div>
</x-dynamic-component>
