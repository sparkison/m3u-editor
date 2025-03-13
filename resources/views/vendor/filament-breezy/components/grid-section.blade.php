@props(['title','description'])
<x-filament::grid @class(["pt-6 gap-4 filament-breezy-grid-section"]) {{ $attributes }} md=6>

    <x-filament::grid.column md=2>
        <h3 @class(['text-lg font-medium filament-breezy-grid-title'])>{{$title}}</h3>

        <p @class(['mt-1 text-sm text-gray-500 filament-breezy-grid-description'])>
            {{$description}}
        </p>
    </x-filament::grid.column>

    <x-filament::grid.column md=4>
        {{ $slot }}
    </x-filament::grid.column>

</x-filament::grid>
