<?php

namespace App\Jobs;

use Exception;
use XMLReader;
use Throwable;
use App\Enums\EpgStatus;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgProgramme;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
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






        // @TODO: update status...






        // Update the playlist status to processing
        $this->epg->update([
            //'status' => EpgStatus::Processing,
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
            $programmeReader = null;
            if ($this->epg->url) {
                // // Fetch the file contents
                // $ch = curl_init($this->epg->url);
                // curl_setopt($ch, CURLOPT_ENCODING, "");
                // curl_setopt(
                //     $ch,
                //     CURLOPT_RETURNTRANSFER,
                //     1
                // );
                // $output = curl_exec($ch);
                // curl_close($ch);

                // // Attempt to decode the gzipped content
                // $xmlData = gzdecode($output);
                // if (!$xmlData) {
                //     // If false, the content was not gzipped, so use the original output
                //     $xmlData = $output;
                // }

                // // Parse the XML data
                // $channelReader = new XMLReader();
                // $channelReader->xml($xmlData);

                // $programmeReader = new XMLReader();
                // $programmeReader->xml($xmlData);

                // Normalize the playlist url and get the filename
                $url = str($this->epg->url)->replace(' ', '%20');

                // We need to grab the file contents first and set to temp file
                $userAgent = 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13';
                $response = Http::withUserAgent($userAgent)
                    ->timeout(60 * 5) // set timeout to five minues
                    ->throw()
                    ->get($url->toString());

                if ($response->ok()) {
                    // Get the contents
                    $output = $response->body();

                    // Attempt to decode the gzipped content
                    $xmlData = gzdecode($output);
                    if (!$xmlData) {
                        // If false, the content was not gzipped, so use the original output
                        $xmlData = $output;
                    }

                    // Parse the XML data
                    $channelReader = new XMLReader();
                    $channelReader->xml($xmlData);

                    // $programmeReader = new XMLReader();
                    // $programmeReader->xml($xmlData);
                }
            } else {
                // Get uploaded file contents
                if ($this->epg->uploads) {
                    $output = file_get_contents($this->epg->uploads[0]);

                    // Attempt to decode the gzipped content
                    $xmlData = gzdecode($output);
                    if (!$xmlData) {
                        // If false, the content was not gzipped, so use the original output
                        $xmlData = $output;
                    }

                    // Parse the XML data
                    $channelReader = new XMLReader();
                    $channelReader->xml($xmlData);

                    // $programmeReader = new XMLReader();
                    // $programmeReader->xml($xmlData);
                }
            }

            // If reader valid, process the data!
            if ($channelReader ) {
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
                $defaultProgrammeData = [
                    'name' => null,
                    'data' => null,
                    'channel_id' => null,
                    'epg_id' => $epgId,
                    'user_id' => $userId,
                    'import_batch_no' => $batchNo,
                ];

                // Create a lazy collection to process the XML data
                $channelData = LazyCollection::make(function () use ($channelReader, $defaultChannelData) {
                    // Loop through the XML data
                    while ($channelReader->read()) {
                        // Only consider XML elements
                        if ($channelReader->nodeType == XMLReader::ELEMENT) {
                            /*
                             * Process channel data
                             */
                            if ($channelReader->name === 'channel') {
                                $channelId = trim($channelReader->getAttribute('id'));
                                $innerXML = $channelReader->readOuterXml();
                                $innerReader = new XMLReader();
                                $innerReader->xml($innerXML);
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
                                                $elementData['display_name'] = trim($innerReader->readString());
                                                $elementData['lang'] = trim($innerReader->getAttribute('lang'));
                                                break;
                                            case 'icon':
                                                $elementData['icon'] = trim($innerReader->readString());
                                                break;
                                        }
                                    }
                                }

                                // Close the inner XMLReader
                                $innerReader->close();

                                // Add the channel to the collection
                                yield $elementData;
                            }
                        }
                    }
                });
                $programmData = LazyCollection::make(function () use ($programmeReader, $defaultProgrammeData) {
                    // Loop through the XML data
                    while ($programmeReader->read()) {
                        // Only consider XML elements
                        if ($programmeReader->nodeType == XMLReader::ELEMENT) {
                            /* 
                             * Process program data 
                             */
                            if ($programmeReader->name === 'programme') {
                                $channelId = trim($programmeReader->getAttribute('channel'));
                                $xmlData = trim($programmeReader->readOuterXml());
                                yield [
                                    ...$defaultProgrammeData,
                                    'name' => trim($programmeReader->getAttribute('title')),
                                    'data' => $xmlData ?? '',
                                    'channel_id' => $channelId,
                                ];
                            }
                        }
                    }
                });

                // Process the data
                $jobs = [];
                $channelData->chunk(10)->each(function (LazyCollection $chunk) use (&$jobs) {
                    $jobs[] = new ProcessEpgChannelImport($chunk->toArray());
                });
                // $programmData->chunk(10)->each(function (LazyCollection $chunk) use (&$jobs) {
                //     $jobs[] = new ProcessEpgProgrammeImport($chunk->toArray());
                // });

                // Close the XMLReaders, all done!
                $channelReader->close();
                // $programmeReader->close();

                // Last job in the batch
                $jobs[] = new ProcessEpgImportComplete($userId, $epgId, $batchNo, $start);
                Bus::chain($jobs)
                    ->onConnection('redis') // force to use redis connection
                    ->catch(function (Throwable $e) use ($epg) {
                        $error = "Unable to process the provided epg: {$e->getMessage()}";
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
            logger()->error($e->getMessage());

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
