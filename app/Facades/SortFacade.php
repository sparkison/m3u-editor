<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static null bulkSortGroupChannels(\App\Models\Group $record, string $order = 'ASC')
 * @method static null bulkRecountGroupChannels(\App\Models\Group $record, int $start = 1)
 */
class SortFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'sort';
    }
}
