# Bulk Operations and Child Playlist Sync

When performing mass updates or deletes using Eloquent relationship queries or the query builder, model events are bypassed and child playlists will not be synchronised automatically.

After any bulk operation on `Channel`, `Group`, `Category`, `Series`, `Season`, or `Episode` records, collect the affected parent playlists and manually queue a child sync:

```php
use App\Jobs\SyncPlaylistChildren;

// example for an updated group
$group->channels()->update([...]);
SyncPlaylistChildren::debounce($group->playlist, []);
```

Ensure contributors performing bulk changes call `SyncPlaylistChildren::debounce` for each affected playlist ID so parent and child playlists remain consistent.
