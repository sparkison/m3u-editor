@php
/** @var \App\Models\Network $network */
@endphp

<div class="p-4 bg-white dark:bg-gray-900">
    @livewire(\App\Livewire\Filament\Networks\EpisodePicker::class, ['network' => $network])
</div>
