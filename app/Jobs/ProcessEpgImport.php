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



        return;



        // @TODO: update status...






        // Update the playlist status to processing
        $this->epg->update([
            //'status' => EpgStatus::Processing,
            'errors' => null,
        ]);














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
                // Fetch the file contents
                $ch = curl_init($this->epg->url);
                curl_setopt($ch, CURLOPT_ENCODING, "");
                curl_setopt(
                    $ch,
                    CURLOPT_RETURNTRANSFER,
                    1
                );
                $output = curl_exec($ch);
                curl_close($ch);

                // Attempt to decode the gzipped content
                $xmlData = gzdecode($output);
                if (!$xmlData) {
                    // If false, the content was not gzipped, so use the original output
                    $xmlData = $output;
                }

                // Parse the XML data
                $channelReader = new XMLReader();
                $channelReader->xml($xmlData);

                $programmeReader = new XMLReader();
                $programmeReader->xml($xmlData);
            } else {
                // Get uploaded file contents
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

                $programmeReader = new XMLReader();
                $programmeReader->xml($xmlData);
            }

            // If reader valid, process the data!
            if ($channelReader && $programmeReader) {
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
                foreach ($channelData->chunk(100)->all() as $chunk) {
                    $jobs[] = new ProcessEpgChannelImport($chunk->toArray());
                }
                foreach ($programmData->chunk(100)->all() as $chunk) {
                    $jobs[] = new ProcessEpgProgrammeImport($chunk->toArray());
                }

                // Close the XMLReaders, all done!
                $channelReader->close();
                $programmeReader->close();

                // Batch the jobs
                Bus::chain($jobs)
                    ->onConnection('redis') // force to use redis connection
                    ->catch(function (Throwable $e) {
                        // ...
                    })->dispatch();






                // dump("EPG Processed! 🎉");


                // $epg->update([
                //     'status' => EpgStatus::Completed,
                //     'synced' => now(),
                //     'errors' => null,
                // ]);



                // // Attach the programmes to the channels
                // $channels = array_map(function ($channel) use ($programmes) {
                //     return [
                //         ...$channel,
                //         'programmes' => $programmes[$channel['channel_id']] ?? []
                //     ];
                // }, $channels);






                // // Collect, chunk, and disatch the import jobs
                // $jobs = [];
                // foreach (array_chunk($channels, 100) as $chunk) {
                //     $jobs[] = new ProcessEpgChannelImport($chunk);
                // }
                // Bus::batch($jobs)
                //     ->then(function (Batch $batch) use ($epg, $batchNo) {
                //         // All jobs completed successfully...

                //         // Send notification
                //         Notification::make()
                //             ->success()
                //             ->title('EPG Synced')
                //             ->body("\"{$epg->name}\" has been synced successfully.")
                //             ->broadcast($epg->user);
                //         Notification::make()
                //             ->success()
                //             ->title('EPG Synced')
                //             ->body("\"{$epg->name}\" has been synced successfully.")
                //             ->sendToDatabase($epg->user);

                //         // Clear out invalid groups (if any)
                //         EpgChannel::where([
                //             ['epg_id', $epg->id],
                //             ['import_batch_no', '!=', $batchNo],
                //         ])->delete();

                //         // Update the playlist
                //         $epg->update([
                //             'status' => EpgStatus::Completed,
                //             'synced' => now(),
                //             'errors' => null,
                //         ]);
                //     })->catch(function (Batch $batch, Throwable $e) {
                //         // First batch job failure detected...
                //     })->finally(function (Batch $batch) {
                //         // The batch has finished executing...
                //     })->name('EPG programme import')->dispatch();
            } else {
                // Log the exception
                logger()->error("Error processing \"{$this->epg->name}\"");

                // Send notification
                $error = "Invalid EPG file. Unable to read or download your EPG file. Please check the URL or uploaded file amd try again.";
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
