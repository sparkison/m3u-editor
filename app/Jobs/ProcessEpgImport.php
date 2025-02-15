<?php

namespace App\Jobs;

use Exception;
use XMLReader;
use Throwable;
use App\Enums\EpgStatus;
use App\Models\Epg;
use App\Models\Job;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;

class ProcessEpgImport implements ShouldQueue
{
    use Queueable;

    // Giving a timeout of 10 minutes to the Job to process the file
    public $timeout = 600;

    /**
     * Create a new job instance.
     * 
     * @param Epg $epg
     */
    public function __construct(
        public Epg $epg,
        public ?bool $force = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Don't update if currently processing
        if ($this->epg->processing) {
            return;
        }
        if (!$this->force) {
            // Check if auto sync is enabled, or the playlist hasn't been synced yet
            if (!$this->epg->auto_sync && $this->epg->synced) {
                return;
            }
        }

        // Update the playlist status to processing
        $this->epg->update([
            'processing' => true,
            'errors' => null,
            'progress' => 0,
        ]);

        // Flag job start time
        $start = now();

        // Process EPG XMLTV based on standard format
        // Info: https://wiki.xmltv.org/index.php/XMLTVFormat
        try {
            $epg = $this->epg;
            $epgId = $epg->id;
            $userId = $epg->user_id;
            $batchNo = Str::uuid7()->toString();

            $channelReader = null;
            $filePath = null;
            if ($this->epg->url) {
                // Normalize the playlist url and get the filename
                $url = str($this->epg->url)->replace(' ', '%20');

                // We need to grab the file contents first and set to temp file
                $userAgent = 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13';
                $response = Http::withUserAgent($userAgent)
                    ->timeout(60 * 5) // set timeout to five minues
                    ->throw()->get($url->toString());

                if ($response->ok()) {
                    // Remove previous saved files
                    Storage::disk('local')->deleteDirectory($epg->folder_path);

                    // Save the file to local storage
                    Storage::disk('local')->put(
                        $epg->file_path,
                        $response->body()
                    );

                    // Update the file path
                    $filePath = Storage::disk('local')->path($epg->file_path);
                }
            } else {
                // Get uploaded file contents
                if ($this->epg->uploads && Storage::disk('local')->exists($this->epg->uploads)) {
                    $filePath = Storage::disk('local')->path($this->epg->uploads);
                }
            }

            // Update progress
            $epg->update(['progress' => 5]); // set to 5% to start

            // If we have XML data, let's process it
            if ($filePath) {
                // Setup the XML readers
                $channelReader = new XMLReader();
                $channelReader->open('compress.zlib://' . $filePath);
            }

            // If reader valid, process the data!
            if ($channelReader) {
                // Default data structures
                $defaultChannelData = [
                    'name' => null,
                    'display_name' => null,
                    'lang' => null,
                    'icon' => null,
                    'channel_id' => null,
                    'epg_id' => $epgId,
                    'user_id' => $userId,
                    'import_batch_no' => $batchNo,
                ];

                // Update progress
                $epg->update(['progress' => 10]);

                // Create a lazy collection to process the XML data
                LazyCollection::make(function () use ($channelReader, $defaultChannelData) {
                    // Loop through the XML data
                    while ($channelReader->read()) {
                        // Only consider XML elements and channel nodes
                        if ($channelReader->nodeType == XMLReader::ELEMENT && $channelReader->name === 'channel') {
                            // Get the channel id
                            $channelId = trim($channelReader->getAttribute('id'));

                            // Setup parser for inner nodes
                            $innerXML = $channelReader->readOuterXml();
                            $innerReader = new XMLReader();
                            $innerReader->xml($innerXML);

                            // Set the default data
                            $elementData = [
                                ...$defaultChannelData
                            ];

                            // Get the node data
                            while ($innerReader->read()) {
                                if ($innerReader->nodeType == XMLReader::ELEMENT) {
                                    switch ($innerReader->name) {
                                        case 'channel':
                                            $elementData['channel_id'] = $channelId;
                                            $elementData['name'] = trim($innerReader->readString());
                                            break;
                                        case 'display-name':
                                            if (!$elementData['display_name']) {
                                                // Only use the first display-name element (could be multiple)
                                                $elementData['display_name'] = trim($innerReader->readString());
                                                $elementData['lang'] = trim($innerReader->getAttribute('lang'));
                                            }
                                            break;
                                        case 'icon':
                                            $elementData['icon'] = trim($innerReader->getAttribute('src'));
                                            break;
                                    }
                                }
                            }

                            // Close the inner XMLReader
                            $innerReader->close();

                            // Only return valid channels
                            if ($elementData['channel_id']) {
                                yield $elementData;
                            }
                        }
                    }
                })->chunk(50)->each(function (LazyCollection $chunk) use ($epg, $batchNo) {
                    Job::create([
                        'title' => "Processing import for EPG: {$epg->name}",
                        'batch_no' => $batchNo,
                        'payload' => $chunk->toArray(),
                        'variables' => [
                            'epgId' => $epg->id,
                        ]
                    ]);
                });

                // Close the XMLReaders, all done!
                $channelReader->close();

                // Update progress
                $epg->update(['progress' => 15]);

                // Get the jobs for the batch
                $jobs = [];
                $batchCount = Job::where('batch_no', $batchNo)->select('id')->count();
                $jobsBatch = Job::where('batch_no', $batchNo)->select('id')->cursor();
                $jobsBatch->chunk(50)->each(function ($chunk) use (&$jobs, $batchCount) {
                    $jobs[] = new ProcessEpgImportChunk($chunk->pluck('id')->toArray(), $batchCount);
                });

                // Last job in the batch
                $jobs[] = new ProcessEpgImportComplete($userId, $epgId, $batchNo, $start);
                Bus::chain($jobs)
                    ->onConnection('redis') // force to use redis connection
                    ->onQueue('import')
                    ->catch(function (Throwable $e) use ($epg) {
                        $error = "Error processing \"{$epg->name}\": {$e->getMessage()}";
                        Notification::make()
                            ->danger()
                            ->title("Error processing \"{$epg->name}\"")
                            ->body('Please view your notifications for details.')
                            ->broadcast($epg->user);
                        Notification::make()
                            ->danger()
                            ->title("Error processing \"{$epg->name}\"")
                            ->body($error)
                            ->sendToDatabase($epg->user);
                        $epg->update([
                            'status' => EpgStatus::Failed,
                            'channels' => 0, // not using...
                            'synced' => now(),
                            'errors' => $error,
                            'progress' => 100,
                            'processing' => false,
                        ]);
                    })->dispatch();
            } else {
                // Log the exception
                logger()->error("Error processing \"{$this->epg->name}\"");

                // Send notification
                $error = "Invalid EPG file. Unable to read or download your EPG file. Please check the URL or uploaded file and try again.";
                Notification::make()
                    ->danger()
                    ->title("Error processing \"{$this->epg->name}\"")
                    ->body('Please view your notifications for details.')
                    ->broadcast($this->epg->user);
                Notification::make()
                    ->danger()
                    ->title("Error processing \"{$this->epg->name}\"")
                    ->body($error)
                    ->sendToDatabase($this->epg->user);

                // Update the playlist
                $this->epg->update([
                    'status' => EpgStatus::Failed,
                    'synced' => now(),
                    'errors' => $error,
                    'progress' => 100,
                    'processing' => false,
                ]);
            }
        } catch (Exception $e) {
            // Log the exception
            logger()->error("Error processing \"{$this->epg->name}\": {$e->getMessage()}");

            // Send notification
            Notification::make()
                ->danger()
                ->title("Error processing \"{$this->epg->name}\"")
                ->body('Please view your notifications for details.')
                ->broadcast($this->epg->user);
            Notification::make()
                ->danger()
                ->title("Error processing \"{$this->epg->name}\"")
                ->body($e->getMessage())
                ->sendToDatabase($this->epg->user);

            // Update the playlist
            $this->epg->update([
                'status' => EpgStatus::Failed,
                'synced' => now(),
                'errors' => $e->getMessage(),
                'progress' => 100,
                'processing' => false,
            ]);
        }
        return;
    }
}
