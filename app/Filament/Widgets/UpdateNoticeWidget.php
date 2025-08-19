<?php

namespace App\Filament\Widgets;

use App\Facades\GitInfo;
use App\Providers\VersionServiceProvider;
use Filament\Widgets\Widget;

class UpdateNoticeWidget extends Widget
{
    protected static string $view = 'filament.widgets.update-notice-widget';

    public static ?int $sort = -5;

    public array $versionData = [];

    public function mount(): void
    {
        // Get the branch
        $branch = GitInfo::getBranch();
        switch ($branch) {
            case 'dev':
                $version = config('dev.dev_version');
                break;
            case 'experimental':
                $version = config('dev.experimental_version');
                break;
            default:
                $version = config('dev.version');
        }
        $latestVersion = VersionServiceProvider::getRemoteVersion();
        $updateAvailable = VersionServiceProvider::updateAvailable();
        $this->versionData = [
            'version' => $version,
            'repo' => config('dev.repo'),
            'latestVersion' => $latestVersion,
            'updateAvailable' => $updateAvailable,
            'branch' => GitInfo::getBranch() ?? null,
            'commit' => GitInfo::getCommit() ?? null,
        ];
    }
}
