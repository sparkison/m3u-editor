<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-center gap-x-3">
            <div>
                <a href="https://github.com/{{ $versionData['repo'] }}" rel="noopener noreferrer" target="_blank">
                    @include('filament.admin.logo')
                </a>
            </div>

            <div class="flex-1">
                <div class="flex-1 flex items-start gap-x-2">
                    <h2 class="grid text-base font-semibold leading-6 text-gray-950 dark:text-white">
                        v{{ $versionData['version'] }}
                    </h2>
                    @if ($versionData['branch'])
                        <x-filament::badge x-tooltip="'Commit: {{ $versionData['commit'] }}'" class="cursor-pointer" size="sm" color="primary">
                            {{ $versionData['branch'] }}
                        </x-filament::badge>
                    @endif
                </div>

                @if ($versionData['updateAvailable'])
                    <div>
                        <div class="flex items-center gap-x-1">
                            <x-heroicon-o-exclamation-triangle class="w-4 h-4 text-danger"/>
                            <p class="font-bold text-sm text-gray-700 dark:text-gray-200">
                                A new version is available
                            </p>
                        </div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Latest version: v{{ $versionData['latestVersion'] }}
                        </p>
                    </div>
                @else
                    <div>
                        <div class="flex items-center gap-x-1">
                            <x-heroicon-o-check-circle class="w-4 h-4 text-success"/>
                            <p class="font-bold text-sm text-gray-700 dark:text-gray-200">
                                Up to date
                            </p>
                        </div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            You are using the latest version
                        </p>
                    </div>
                @endif
            </div>

            <div class="flex flex-col items-end gap-y-1">
                <x-filament::button color="{{ $versionData['updateAvailable'] ? 'danger' : 'gray' }}" tag="a"
                                    href="https://github.com/{{ $versionData['repo'] }}/releases"
                                    icon="heroicon-m-arrow-top-right-on-square"
                                    icon-alias="panels::widgets.filament-info.open-documentation-button"
                                    rel="noopener noreferrer"
                                    target="_blank">
                    Releases
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
