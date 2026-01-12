<x-filament-panels::page>
    <form wire:submit="addMovies">
        {{ $this->form }}

        <div class="mt-6 flex justify-end gap-x-3">
            <x-filament::button type="submit" color="primary">
                Add Selected Movies
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
