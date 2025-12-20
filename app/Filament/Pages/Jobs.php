<?php

namespace App\Filament\Pages;

use App\Models\JobProgress;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use RyanChandler\FilamentProgressColumn\ProgressColumn;

class Jobs extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected string $view = 'filament.pages.jobs';

    protected static ?string $navigationLabel = 'Background Jobs';

    protected static ?string $title = 'Background Jobs';

    protected ?string $subheading = 'Monitor the progress of background jobs like playlist syncs, EPG imports, and metadata fetching.';

    public static function getNavigationSort(): ?int
    {
        return 1;
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Tools';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = JobProgress::active()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'primary';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Background Jobs';
    }

    protected function getActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon(Heroicon::ArrowPath)
                ->action(fn () => $this->resetTable())
                ->color('gray'),
            Action::make('clear_completed')
                ->label('Clear Completed')
                ->icon(Heroicon::Trash)
                ->action(function () {
                    JobProgress::finished()
                        ->where('completed_at', '<', now()->subHours(1))
                        ->delete();

                    Notification::make()
                        ->success()
                        ->title('Completed jobs cleared')
                        ->body('Jobs completed more than 1 hour ago have been removed.')
                        ->send();

                    $this->resetTable();
                })
                ->requiresConfirmation()
                ->modalDescription('This will remove all completed, failed, and cancelled jobs that finished more than 1 hour ago.')
                ->color('danger'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(JobProgress::query()->latest())
            ->poll('5s')
            ->columns([
                TextColumn::make('name')
                    ->label('Job')
                    ->searchable()
                    ->sortable()
                    ->description(fn (JobProgress $record): ?string => $record->trackable?->name ?? null),
                TextColumn::make('job_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->afterLast('\\')->toString())
                    ->color('gray'),
                ProgressColumn::make('progress_percent')
                    ->label('Progress')
                    ->progress(fn (JobProgress $record): float => $record->progress_percent)
                    ->color(fn (JobProgress $record): string => match ($record->status) {
                        JobProgress::STATUS_COMPLETED => 'success',
                        JobProgress::STATUS_FAILED => 'danger',
                        JobProgress::STATUS_CANCELLED => 'warning',
                        JobProgress::STATUS_RUNNING => 'primary',
                        default => 'gray',
                    }),
                TextColumn::make('processed_items')
                    ->label('Items')
                    ->formatStateUsing(fn (JobProgress $record): string => $record->total_items > 0
                        ? "{$record->processed_items} / {$record->total_items}"
                        : (string) $record->processed_items
                    )
                    ->alignCenter(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        JobProgress::STATUS_COMPLETED => 'success',
                        JobProgress::STATUS_FAILED => 'danger',
                        JobProgress::STATUS_CANCELLED => 'warning',
                        JobProgress::STATUS_RUNNING => 'primary',
                        JobProgress::STATUS_PENDING => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('message')
                    ->label('Status Message')
                    ->limit(50)
                    ->tooltip(fn (JobProgress $record): ?string => $record->message)
                    ->placeholder('—'),
                TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime('M j, H:i:s')
                    ->sortable()
                    ->placeholder('Not started'),
                TextColumn::make('completed_at')
                    ->label('Finished')
                    ->dateTime('M j, H:i:s')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Queued')
                    ->dateTime('M j, H:i:s')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                //
            ])
            ->bulkActions([
                //
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No background jobs')
            ->emptyStateDescription('Background jobs will appear here when playlist syncs, EPG imports, or other long-running tasks are started.')
            ->emptyStateIcon('heroicon-o-queue-list');
    }
}
