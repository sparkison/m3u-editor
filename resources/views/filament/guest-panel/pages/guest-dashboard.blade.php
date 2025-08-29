<x-filament-panels::page>
    @php $isAuthed = $this->isGuestAuthenticated(); @endphp

    @if (!$isAuthed)
        <div class="max-w-md mx-auto mt-16 p-8 bg-white dark:bg-gray-900 rounded shadow">
            <h2 class="text-2xl font-bold mb-6 text-center">Playlist Login</h2>
            @if ($authError)
                <div class="mb-4 text-red-600">{{ $authError }}</div>
            @endif
            <form wire:submit.prevent="login">
                <div class="mb-4">
                    <label class="block mb-1 font-medium" for="username">Username</label>
                    <input wire:model.defer="username" id="username" type="text" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring focus:border-indigo-400 dark:bg-gray-800 dark:text-white" autocomplete="username" required>
                </div>
                <div class="mb-6">
                    <label class="block mb-1 font-medium" for="password">Password</label>
                    <input wire:model.defer="password" id="password" type="password" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring focus:border-indigo-400 dark:bg-gray-800 dark:text-white" autocomplete="current-password" required>
                </div>
                <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2 px-4 rounded transition">Login</button>
            </form>
        </div>
    @else
        <div class="flex justify-end mb-4">
            <button wire:click="logout" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-semibold py-1 px-3 rounded">Logout</button>
        </div>
        {{-- Authenticated dashboard content goes here --}}
        <div>
            <h2 class="text-2xl font-bold mb-4">Welcome, {{ $username }}</h2>
            {{-- Add more dashboard content here as needed --}}
        </div>
    @endif
</x-filament-panels::page>
