@php
/** @var \App\Models\Network $network */
@endphp

<div class="p-4">
    @livewire(\App\Livewire\Filament\Networks\VodPicker::class, ['network' => $network])
</div>
