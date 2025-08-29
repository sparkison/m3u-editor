<x-filament-panels::page>
    @php $isAuthed = $this->isGuestAuthenticated(); @endphp
    @if (!$isAuthed)
        <div class="max-w-md mx-auto mt-16 p-8 bg-white dark:bg-gray-900 rounded shadow">
            <h2 class="text-2xl font-bold mb-6 text-center">Playlist Login</h2>
            @if ($authError)
                <div class="mb-4 text-red-600">{{ $authError }}</div>
            @endif
            <form wire:submit.prevent="login">
                {{ $this->form }}
                <x-filament::button type="submit" class="w-full mt-4">
                    Login
                </x-filament::button>
            </form>
        </div>
    @else
        <div class="flex justify-end mb-4">
            <button wire:click="logout" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-semibold py-1 px-3 rounded">Logout</button>
        </div>
        {{-- Authenticated dashboard content goes here --}}
        <div>
            {{-- Add more dashboard content here as needed --}}
        </div>
    @endif
</x-filament-panels::page>
