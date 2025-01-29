<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @php($epgUrl = route('epg.generate', ['uuid' => $getRecord()->uuid]))
    <div x-data="{ state: $wire.$entangle('{{ $getStatePath() }}') }">
        <a href="{{ $epgUrl }}" target="_blank"
            class="underline flex items-center gap-1 text-primary-500 hover:text-primary-700 dark:hover:text-primary-300">
            {{ $epgUrl }}
            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                stroke="currentColor" class="size-6">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
            </svg>
        </a>
        <small class="block">Filename: <strong>{{ Str::slug($getRecord()->name) . '.xml' }}</strong></small>
    </div>
</x-dynamic-component>
