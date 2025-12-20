<div 
    x-data="{ 
        count: 0,
        init() {
            // Initial fetch
            this.fetchCount();
            // Poll every 5 seconds for updated job count
            setInterval(() => this.fetchCount(), 5000);
        },
        fetchCount() {
            fetch('{{ route('jobs.active-count') }}')
                .then(response => response.json())
                .then(data => {
                    this.count = data.count;
                })
                .catch(() => {});
        }
    }"
    x-show="count > 0"
    x-transition
    x-cloak
    class="flex items-center gap-2"
>
    <a 
        href="{{ \App\Filament\Pages\Jobs::getUrl() }}"
        class="flex items-center gap-2 px-3 py-1.5 bg-primary-50 dark:bg-primary-900/50 hover:bg-primary-100 dark:hover:bg-primary-900 rounded-lg border border-primary-200 dark:border-primary-700 transition-colors"
        title="Background jobs running - click to view"
    >
        <div class="relative">
            <x-heroicon-s-queue-list class="h-5 w-5 text-primary-600 dark:text-primary-400" />
            <span class="absolute -top-1 -right-1 flex h-3 w-3">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-primary-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-3 w-3 bg-primary-500"></span>
            </span>
        </div>
        <span 
            x-text="count + ' job' + (count === 1 ? '' : 's')"
            class="text-xs font-medium text-primary-700 dark:text-primary-300"
        ></span>
    </a>
</div>
