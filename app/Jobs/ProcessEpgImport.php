<?php

namespace App\Jobs;

use Exception;
use XMLReader;
use Throwable;
use App\Enums\EpgStatus;
use App\Models\Epg;
use App\Models\EpgChannel;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Illuminate\Bus\Batch;
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
        public Epg $epg
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Don't update if currently processing
        if ($this->epg->status === EpgStatus::Processing) {
            return;
        }

        // Update the playlist status to processing
        $this->epg->update([
            'status' => EpgStatus::Processing,
            'errors' => null,
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

            $xmlData = null;
            $channelReader = null;
            if ($this->epg->url) {
                // Normalize the playlist url and get the filename
                $url = str($this->epg->url)->replace(' ', '%20');

                // We need to grab the file contents first and set to temp file
                $userAgent = 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13';
                $response = Http::withUserAgent($userAgent)
                    ->timeout(60 * 5) // set timeout to five minues
                    ->throw()->get($url->toString());

                if ($response->ok()) {
                    // Get the contents
                    $output = $response->body();

                    // Attempt to decode the gzipped content
                    $xmlData = gzdecode($output);
                    if (!$xmlData) {
                        // If false, the content was not gzipped, use the original output
                        $xmlData = $output;
                    }
                }
            } else {
                // Get uploaded file contents
                if ($this->epg->uploads) {
                    $output = file_get_contents($this->epg->uploads[0]);

                    // Attempt to decode the gzipped content
                    $xmlData = gzdecode($output);
                    if (!$xmlData) {
                        // If false, the content was not gzipped, use the original output
                        $xmlData = $output;
                    }
                }
            }

            // If we have XML data, let's process it
            if ($xmlData) {
                // Setup the XML readers
                $channelReader = new XMLReader();
                $channelReader->xml($xmlData);

                // Save the contents to a file
                $filename = Str::slug($epg->name) . '.xml';

                // Remove previous saved files
                Storage::disk('local')->deleteDirectory("epg/{$epg->uuid}");

                // Save the file to local storage
                Storage::disk('local')->put("epg/{$epg->uuid}/{$filename}", $xmlData);
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

                // Create a lazy collection to process the XML data
                $channelData = LazyCollection::make(function () use ($channelReader, $defaultChannelData) {
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
                });

                // Process the data
                $jobs = [];
                $channelData->chunk(100)->each(function (LazyCollection $chunk) use (&$jobs) {
                    $jobs[] = new ProcessEpgChannelImport($chunk->toArray());
                });

                // Close the XMLReaders, all done!
                $channelReader->close();

                // Last job in the batch
                $jobs[] = new ProcessEpgImportComplete($userId, $epgId, $batchNo, $start);
                Bus::chain($jobs)
                    ->onConnection('redis') // force to use redis connection
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
            ]);
        }
        return;
    }
}
