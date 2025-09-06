<div class="p-4" x-data="{
    channels: @js($channels->map(fn($c) => [
        'id' => $c->id,
        'title' => $c->title,
        'title_custom' => $c->title_custom,
        'stream_id' => $c->stream_id,
        'stream_id_custom' => $c->stream_id_custom,
        'enabled' => $c->enabled,
        'sort' => $c->sort,
    ])),
    message: '',
    messageType: '',
    sortAZ() {
        this.channels.sort((a, b) => {
            const tA = (a.title_custom || a.title || '').toLowerCase();
            const tB = (b.title_custom || b.title || '').toLowerCase();
            return tA.localeCompare(tB);
        });
        this.persistSortOrder();
        var channels = [...this.channels]; // Force Alpine to update the DOM
        this.channels = [];
        this.$nextTick(() => this.channels = channels); // Re-assign to trigger reactivity
    },
    persistSortOrder() {
        fetch('{{ route('channels.reorder') }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({ ids: this.channels.map(c => c.id) }),
        })
        .then(response => response.json())
        .then(data => {
            this.message = data.message;
            this.messageType = data.success ? 'success' : 'error';
        })
        .catch(() => {
            this.message = 'An error occurred while saving the order.';
            this.messageType = 'error';
        });
    }
}" x-init="initChannelSortable()">
    <div class="flex justify-end mb-2">
        <template x-if="message">
            <div :class="messageType === 'success' ? 'mr-4 text-green-600 dark:text-green-400' : 'mr-4 text-red-600 dark:text-red-400'" class="text-xs font-semibold flex items-center">
                <x-heroicon-o-check-circle class="w-4 h-4 mr-1" x-show="messageType === 'success'" />
                <x-heroicon-o-x-circle class="w-4 h-4 mr-1" x-show="messageType === 'error'" />
                <span x-text="message"></span>
            </div>
        </template>
        <button
            type="button"
            class="inline-flex items-center px-3 py-1.5 bg-blue-600 text-white text-xs font-semibold rounded hover:bg-blue-700 transition"
            x-on:click="sortAZ()"
        >
            Sort A-Z
            <x-heroicon-o-arrow-up class="ml-1 w-4 h-4" />
        </button>
    </div>
    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
        <thead>
            <tr>
                <th class="w-8"></th> <!-- Drag handle column -->
                @php
                    $currentSort = request('sort', 'title');
                    $currentDirection = request('direction', 'asc');
                @endphp
                <th class="px-2 py-1 text-left text-xs font-medium text-gray-500 dark:text-gray-300">
                    <a href="#" class="hover:underline">Title</a>
                </th>
                <th class="px-2 py-1 text-left text-xs font-medium text-gray-500 dark:text-gray-300">Stream ID</th>
                <th class="px-2 py-1 text-left text-xs font-medium text-gray-500 dark:text-gray-300">Status</th>
                <th class="px-2 py-1 text-left text-xs font-medium text-gray-500 dark:text-gray-300">Sort Order</th>
            </tr>
        </thead>
        <tbody id="channels-tbody" class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
            <template x-for="channel in channels" :key="channel.id">
                <tr :data-id="channel.id">
                    <td class="cursor-move text-gray-400 dark:text-gray-500">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16" /></svg>
                    </td>
                    <td class="px-2 py-1 text-sm text-gray-900 dark:text-gray-100" x-text="channel.title_custom || channel.title"></td>
                    <td class="px-2 py-1 text-sm text-gray-900 dark:text-gray-100" x-text="channel.stream_id_custom || channel.stream_id"></td>
                    <td class="px-2 py-1 text-sm">
                        <template x-if="channel.enabled">
                            <span class="text-green-600 dark:text-green-400">Enabled</span>
                        </template>
                        <template x-if="!channel.enabled">
                            <span class="text-red-600 dark:text-red-400">Disabled</span>
                        </template>
                    </td>
                    <td class="px-2 py-1 text-sm text-gray-900 dark:text-gray-100" x-text="channel.sort"></td>
                </tr>
            </template>
        </tbody>
    </table>
</div>
