<div class="grid gap-6">
  @foreach ($streamStats as $streamStat)
    <div class="bg-white dark:bg-gray-800 border border-gray-50 dark:border-gray-700 rounded-xl py-2 overflow-x-auto">
      {{-- Optional header if you have an identifier --}}
      <h3 class="text-lg font-semibold text-gray-800 px-4 py-2 dark:text-gray-100">
        Stream {{ $loop->iteration }}
      </h3>
      <div>
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
          <thead class="bg-gray-50 dark:bg-gray-900">
            <tr>
              <th class="px-4 py-2 text-xs font-medium text-gray-500 dark:text-gray-200 uppercase tracking-wider text-left">
                Key
              </th>
              <th class="px-4 py-2 text-xs font-medium text-gray-500 dark:text-gray-200 uppercase tracking-wider text-left">
                Value
              </th>
            </tr>
          </thead>
          <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-100 dark:divide-gray-700">
            @foreach ($streamStat['stream'] as $key => $value)
              <tr>
                <td class="px-4 py-3 whitespace-nowrap text-sm font-mono text-gray-700 dark:text-gray-300">
                  {{ $key }}
                </td>
                <td class="px-4 py-3 whitespace-nowrap text-sm font-mono text-gray-900 dark:text-gray-100">
                  {{ $value }}
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  @endforeach
</div>
