@php
/** @var \App\Models\Network $network */
@endphp

<div class="p-4 bg-white dark:bg-gray-900">
    @livewire(\App\Livewire\Filament\Networks\VodPicker::class, ['network' => $network])
</div>
