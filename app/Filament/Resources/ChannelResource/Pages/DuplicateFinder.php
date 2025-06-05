<?php

namespace App\Filament\Resources\ChannelResource\Pages;

use App\Filament\Resources\ChannelResource;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Services\ChannelSimilarityMatchingService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DuplicateFinder extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = ChannelResource::class;

    protected static string $view = 'filament.resources.channel-resource.pages.duplicate-finder';

    protected static ?string $title = 'Channel Duplicate Finder';

    protected static ?string $navigationLabel = 'Find Duplicates';

    public $duplicateGroups = [];
    public $similarityThreshold = 0.75;
    
    public function mount(): void
    {
        $this->loadDuplicateGroups();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('refresh')
                ->label('Refresh Analysis')
                ->icon('heroicon-o-arrow-path')
                ->action(function () {
                    $this->loadDuplicateGroups();
                    Notification::make()
                        ->success()
                        ->title('Analysis Refreshed')
                        ->body('Duplicate channel analysis has been updated.')
                        ->send();
                }),
            Actions\Action::make('configure')
                ->label('Configure')
                ->icon('heroicon-o-cog-6-tooth')
                ->form([
                    Forms\Components\Slider::make('similarity_threshold')
                        ->label('Similarity Threshold')
                        ->default($this->similarityThreshold)
                        ->min(0.5)
                        ->max(1.0)
                        ->step(0.05)
                        ->helperText('Higher values require more exact matches'),
                ])
                ->action(function (array $data) {
                    $this->similarityThreshold = $data['similarity_threshold'];
                    $this->loadDuplicateGroups();
                    Notification::make()
                        ->success()
                        ->title('Settings Updated')
                        ->body('Similarity threshold updated and analysis refreshed.')
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->columns([
                Tables\Columns\TextColumn::make('group_id')
                    ->label('Group')
                    ->badge()
                    ->color('primary'),
                Tables\Columns\ImageColumn::make('logo')
                    ->label('Icon')
                    ->checkFileExistence(false)
                    ->height(30)
                    ->width('auto'),
                Tables\Columns\TextColumn::make('display_title')
                    ->label('Title')
                    ->getStateUsing(fn ($record) => $record->title_custom ?: $record->title),
                Tables\Columns\TextColumn::make('display_name')
                    ->label('Name')
                    ->getStateUsing(fn ($record) => $record->name_custom ?: $record->name),
                Tables\Columns\TextColumn::make('playlist.name')
                    ->label('Playlist'),
                Tables\Columns\ToggleColumn::make('is_fallback_candidate')
                    ->label('Fallback')
                    ->tooltip('Toggle fallback candidate status'),
                Tables\Columns\TextColumn::make('similarity_score')
                    ->label('Similarity')
                    ->getStateUsing(function ($record) {
                        return isset($record->similarity_score) 
                            ? number_format($record->similarity_score * 100, 1) . '%' 
                            : 'N/A';
                    })
                    ->badge()
                    ->color(function ($state) {
                        $numeric = floatval(str_replace('%', '', $state)) / 100;
                        if ($numeric >= 0.9) return 'success';
                        if ($numeric >= 0.8) return 'warning';
                        return 'danger';
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('playlist')
                    ->relationship('playlist', 'name')
                    ->multiple()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\Action::make('set_primary')
                    ->label('Set Primary')
                    ->icon('heroicon-o-star')
                    ->color('warning')
                    ->action(function ($record) {
                        // Mark this channel as NOT a fallback candidate
                        $record->update(['is_fallback_candidate' => false]);
                        
                        // Mark other channels in the same group as fallback candidates
                        $groupChannels = $this->getChannelsInGroup($record->group_id);
                        foreach ($groupChannels as $channel) {
                            if ($channel->id !== $record->id) {
                                $channel->update(['is_fallback_candidate' => true]);
                            }
                        }
                        
                        $this->loadDuplicateGroups();
                        
                        Notification::make()
                            ->success()
                            ->title('Primary Channel Set')
                            ->body('Channel marked as primary and others set as fallback candidates.')
                            ->send();
                    })
                    ->requiresConfirmation(),
                Tables\Actions\Action::make('create_failovers')
                    ->label('Auto-Create Failovers')
                    ->icon('heroicon-o-link')
                    ->color('success')
                    ->action(function ($record) {
                        $groupChannels = $this->getChannelsInGroup($record->group_id);
                        $primaryChannel = $groupChannels->where('is_fallback_candidate', false)->first();
                        
                        if (!$primaryChannel) {
                            Notification::make()
                                ->danger()
                                ->title('No Primary Channel')
                                ->body('Please set a primary channel first.')
                                ->send();
                            return;
                        }
                        
                        $created = 0;
                        foreach ($groupChannels as $channel) {
                            if ($channel->id !== $primaryChannel->id && $channel->is_fallback_candidate) {
                                ChannelFailover::updateOrCreate([
                                    'channel_id' => $primaryChannel->id,
                                    'channel_failover_id' => $channel->id,
                                ], [
                                    'user_id' => auth()->id(),
                                    'auto_matched' => true,
                                    'match_quality' => $channel->similarity_score ?? 0.8,
                                    'match_type' => 'duplicate_finder'
                                ]);
                                $created++;
                            }
                        }
                        
                        Notification::make()
                            ->success()
                            ->title('Failovers Created')
                            ->body("Created {$created} failover relationships.")
                            ->send();
                    })
                    ->requiresConfirmation(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('mark_fallback_candidates')
                    ->label('Mark as Fallback Candidates')
                    ->action(function (Collection $records) {
                        $records->each->update(['is_fallback_candidate' => true]);
                        
                        Notification::make()
                            ->success()
                            ->title('Channels Updated')
                            ->body('Selected channels marked as fallback candidates.')
                            ->send();
                    })
                    ->requiresConfirmation(),
            ]);
    }

    protected function getTableQuery(): Builder
    {
        // Return channels that are part of duplicate groups
        $channelIds = collect($this->duplicateGroups)->flatten(1)->pluck('id');
        
        return Channel::query()
            ->whereIn('id', $channelIds)
            ->with(['playlist'])
            ->where('user_id', auth()->id());
    }

    private function loadDuplicateGroups(): void
    {
        $similarityService = new ChannelSimilarityMatchingService();
        
        $channels = Channel::where('user_id', auth()->id())
            ->with(['playlist'])
            ->get();
            
        $duplicateGroups = [];
        $processedChannels = [];
        $groupId = 1;
        
        foreach ($channels as $channel) {
            if (in_array($channel->id, $processedChannels)) {
                continue;
            }
            
            // Find similar channels
            $similarChannels = $similarityService->findSimilarChannels(
                $channel,
                $channels->except($channel->id),
                false
            );
            
            // Filter by threshold
            $matches = $similarChannels->filter(function ($match) {
                return $match['similarity'] >= $this->similarityThreshold;
            });
            
            if ($matches->isNotEmpty()) {
                // Create a group with the original channel and its matches
                $group = collect([$channel]);
                
                foreach ($matches as $match) {
                    $matchChannel = $match['channel'];
                    $matchChannel->similarity_score = $match['similarity'];
                    $group->push($matchChannel);
                    $processedChannels[] = $matchChannel->id;
                }
                
                // Assign group ID to all channels in the group
                $group->each(function ($ch) use ($groupId) {
                    $ch->group_id = $groupId;
                });
                
                $duplicateGroups[$groupId] = $group;
                $processedChannels[] = $channel->id;
                $groupId++;
            }
        }
        
        $this->duplicateGroups = $duplicateGroups;
    }
    
    private function getChannelsInGroup(int $groupId): Collection
    {
        return collect($this->duplicateGroups[$groupId] ?? []);
    }
}
