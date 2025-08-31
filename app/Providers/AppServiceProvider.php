<?php

namespace App\Providers;

use Exception;
use App\Events\EpgCreated;
use App\Events\EpgDeleted;
use App\Events\EpgUpdated;
use App\Events\PlaylistCreated;
use App\Events\PlaylistDeleted;
use App\Events\PlaylistUpdated;
use App\Livewire\BackupDestinationListRecords;
use App\Livewire\StreamPlayer;
use App\Models\ChannelFailover;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Epg;
use App\Models\Category;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\EpgCacheService;
use App\Services\FfmpegCodecService;
use App\Services\HlsStreamService;
use App\Services\PlaylistUrlService;
use App\Services\ProxyService;
use App\Settings\GeneralSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Storage;
use Opcodes\LogViewer\Facades\LogViewer;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\GitInfoService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Don't kill the app if the database hasn't been created.
        try {
            foreach (['sqlite', 'jobs'] as $connection) {
                DB::connection($connection)
                    ->statement('
                        PRAGMA synchronous = NORMAL;
                        PRAGMA mmap_size = 134217728; -- 128 megabytes
                        PRAGMA cache_size = 1000000000;
                        PRAGMA foreign_keys = true;
                        PRAGMA busy_timeout = 5000;
                        PRAGMA temp_store = memory;
                        PRAGMA auto_vacuum = incremental;
                        PRAGMA incremental_vacuum;
                    ');
            }
        } catch (\Throwable $throwable) {
            return;
        }

        // Disable mass assignment protection (security handled by Filament)
        Model::unguard();

        // Check if app url contains https, and if so, force https
        if (Str::contains(config('app.url'), 'https')) {
            URL::forceScheme('https');
            request()->server->set('HTTPS', request()->header('X-Forwarded-Proto', 'https') == 'https' ? 'on' : 'off');
        }

        // Setup the middleware
        $this->setupMiddleware();

        // Setup the gates
        $this->setupGates();

        // Register the model event listeners
        $this->registerModelEventListeners();

        // Register the Filament hooks
        $this->registerFilamentHooks();

        // Setup the API
        $this->setupApi();

        // Setup the services
        $this->setupServices();

        // Livewire components
        $this->registerLivewireComponents();
    }

    /**
     * Setup the middleware.
     */
    private function setupMiddleware(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip());
        });
    }

    /**
     * Setup the gates.
     */
    private function setupGates(): void
    {
        // Allow only the admin to download and delete backups
        Gate::define('download-backup', function (User $user) {
            return in_array($user->email, config('dev.admin_emails'), true);
        });
        Gate::define('delete-backup', function (User $user) {
            return in_array($user->email, config('dev.admin_emails'), true);
        });
    }

    /**
     * Register the model event listeners.
     */
    private function registerModelEventListeners(): void
    {
        // Register the event listener
        try {
            // Process playlist on creation
            Playlist::created(fn(Playlist $playlist) => event(new PlaylistCreated($playlist)));
            Playlist::updated(fn(Playlist $playlist) => event(new PlaylistUpdated($playlist)));
            Playlist::saved(function (Playlist $playlist) {
                if ($playlist->paired_playlist_id) {
                    $playlist->syncPairedPlaylist();
                }
            });
            Playlist::creating(function (Playlist $playlist) {
                if (!$playlist->user_id) {
                    $playlist->user_id = auth()->id();
                }
                if (!$playlist->sync_interval) {
                    $playlist->sync_interval = '24 hours';
                }
                $playlist->uuid = Str::orderedUuid()->toString();
                return $playlist;
            });
            Playlist::updating(function (Playlist $playlist) {
                if (!$playlist->sync_interval) {
                    $playlist->sync_interval = '24 hours';
                }
                if ($playlist->isDirty('short_urls_enabled')) {
                    $playlist->generateShortUrl();
                }
                if ($playlist->isDirty('uuid')) {
                    // If changing the UUID, remove the old short URLs and generate new ones
                    if ($playlist->short_urls_enabled) {
                        $playlist->removeShortUrls();
                        $playlist->generateShortUrl();
                    }
                }
                return $playlist;
            });
            Playlist::deleting(function (Playlist $playlist) {
                Storage::disk('local')->deleteDirectory($playlist->folder_path);
                if ($playlist->uploads && Storage::disk('local')->exists($playlist->uploads)) {
                    Storage::disk('local')->delete($playlist->uploads);
                }

                // Delete cached EPG files
                EpgCacheService::clearPlaylistEpgCacheFile($playlist);

                // Remove short URLs and detach playlist auths
                $playlist->removeShortUrls();
                $playlist->playlistAuths()->detach();
                event(new PlaylistDeleted($playlist));
                $playlist->postProcesses()->detach();
                return $playlist;
            });

            // Process epg on creation
            Epg::created(fn(Epg $epg) => event(new EpgCreated($epg)));
            Epg::updated(fn(Epg $epg) => event(new EpgUpdated($epg)));
            Epg::creating(function (Epg $epg) {
                if (!$epg->user_id) {
                    $epg->user_id = auth()->id();
                }
                if (!$epg->sync_interval) {
                    $epg->sync_interval = '24 hours';
                }
                $epg->uuid = Str::orderedUuid()->toString();
                return $epg;
            });
            Epg::updating(function (Epg $epg) {
                if (!$epg->sync_interval) {
                    $epg->sync_interval = '24 hours';
                }
                return $epg;
            });
            Epg::deleting(function (Epg $epg) {
                Storage::disk('local')->deleteDirectory($epg->folder_path);
                if ($epg->uploads && Storage::disk('local')->exists($epg->uploads)) {
                    Storage::disk('local')->delete($epg->uploads);
                }
                event(new EpgDeleted($epg));
                $epg->postProcesses()->detach();
                return $epg;
            });

            // Merged playlist
            // MergedPlaylist::created(fn(MergedPlaylist $mergedPlaylist) => /* ... */);
            MergedPlaylist::creating(function (MergedPlaylist $mergedPlaylist) {
                if (!$mergedPlaylist->user_id) {
                    $mergedPlaylist->user_id = auth()->id();
                }
                $mergedPlaylist->uuid = Str::orderedUuid()->toString();
                return $mergedPlaylist;
            });
            MergedPlaylist::updating(function (MergedPlaylist $mergedPlaylist) {
                if ($mergedPlaylist->isDirty('short_urls_enabled')) {
                    $mergedPlaylist->generateShortUrl();
                }
                if ($mergedPlaylist->isDirty('uuid')) {
                    // If changing the UUID, remove the old short URLs and generate new ones
                    if ($mergedPlaylist->short_urls_enabled) {
                        $mergedPlaylist->removeShortUrls();
                        $mergedPlaylist->generateShortUrl();
                    }
                }
                return $mergedPlaylist;
            });

            // Custom playlist
            // CustomPlaylist::created(fn(CustomPlaylist $customPlaylist) => /* ... */);
            CustomPlaylist::creating(function (CustomPlaylist $customPlaylist) {
                if (!$customPlaylist->user_id) {
                    $customPlaylist->user_id = auth()->id();
                }
                $customPlaylist->uuid = Str::orderedUuid()->toString();
                return $customPlaylist;
            });
            CustomPlaylist::updating(function (CustomPlaylist $customPlaylist) {
                if ($customPlaylist->isDirty('short_urls_enabled')) {
                    $customPlaylist->generateShortUrl();
                }
                if ($customPlaylist->isDirty('uuid')) {
                    // If changing the UUID, remove the old short URLs and generate new ones
                    if ($customPlaylist->short_urls_enabled) {
                        $customPlaylist->removeShortUrls();
                        $customPlaylist->generateShortUrl();
                    }
                }
                return $customPlaylist;
            });

            // Groups
            Group::updated(function (Group $group) {
                $changes = $group->getChanges();
                if (isset($changes['name'])) {
                    // Update the name of the group in the channels
                    $group->channels()
                        ->update(['group' => $group->name]);
                }
            });

            Channel::saved(function (Channel $channel) {
                $channel->playlist?->syncPairedPlaylist();
            });
            Channel::deleted(function (Channel $channel) {
                $channel->playlist?->syncPairedPlaylist();
            });
            Group::saved(function (Group $group) {
                $group->playlist?->syncPairedPlaylist();
            });
            Group::deleted(function (Group $group) {
                $group->playlist?->syncPairedPlaylist();
            });
            Category::saved(function (Category $category) {
                $category->playlist?->syncPairedPlaylist();
            });
            Category::deleted(function (Category $category) {
                $category->playlist?->syncPairedPlaylist();
            });
            Series::saved(function (Series $series) {
                $series->playlist?->syncPairedPlaylist();
            });
            Series::deleted(function (Series $series) {
                $series->playlist?->syncPairedPlaylist();
            });

            // Failover channels
            ChannelFailover::creating(function (ChannelFailover $channelFailover) {
                if (!$channelFailover->user_id) {
                    $channelFailover->user_id = auth()->id();
                }
                return $channelFailover;
            });
        } catch (\Throwable $e) {
            // Log the error
            report($e);
        }
    }

    /**
     * Register the Filament hooks.
     */
    private function registerFilamentHooks(): void
    {
        // Add scroll to top event listener
        FilamentView::registerRenderHook(
            PanelsRenderHook::SCRIPTS_AFTER,
            fn(): string => new HtmlString('<script>document.addEventListener("scroll-to-top", () => window.scrollTo({top: 0, left: 0, behavior: "smooth"}))</script>'),
        );

        // Add footer view
        FilamentView::registerRenderHook(
            PanelsRenderHook::FOOTER,
            fn() => view('footer')
        );
    }

    /**
     * Setup the API.
     */
    private function setupApi(): void
    {
        // Add log viewer auth
        $userPreferences = app(GeneralSettings::class);
        try {
            $showApiDocs = $userPreferences->show_api_docs;
        } catch (Exception $e) {
            $showApiDocs = false;
        }

        // Allow access to api docs
        Gate::define('viewApiDocs', function (User $user) use ($showApiDocs) {
            return $showApiDocs && in_array($user->email, config('dev.admin_emails'), true);
        });

        // Configure the API
        Scramble::configure()
            ->routes(function (Route $route) {
                return Str::startsWith($route->uri, [
                    'playlist/',
                    'epg/',
                    'user/',
                    'player_api.php'
                ]);
            })
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(
                    SecurityScheme::http('bearer')
                );
            });
    }

    /**
     * Setup the services.
     */
    public function setupServices(): void
    {
        // Register the proxy service
        $this->app->singleton('proxy', function () {
            return new ProxyService();
        });

        // Register the playlist url service
        $this->app->singleton('playlistUrl', function () {
            return new PlaylistUrlService();
        });

        // Register the HLS stream service
        $this->app->singleton(HlsStreamService::class);

        // Register the FFmpeg codec service
        $this->app->singleton(FfmpegCodecService::class);
    }

    /**
     * Register Livewire components.
     */
    private function registerLivewireComponents(): void
    {
        // Register the backup destination list records component
        Livewire::component('backup-destination-list-records', BackupDestinationListRecords::class);

        // Register the stream player component
        Livewire::component('stream-player', StreamPlayer::class);
    }
}
