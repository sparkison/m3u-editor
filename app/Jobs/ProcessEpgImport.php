<?php

namespace App\Jobs;

use Exception;
use XMLReader;
use App\Enums\EpgStatus;
use App\Models\Epg;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

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

        // Process EPG XMLTV based on standard format
        // Info: https://wiki.xmltv.org/index.php/XMLTVFormat
        try {
            $epg = $this->epg;
            $epgId = $epg->id;
            $userId = $epg->user_id;
            $batchNo = Str::uuid7()->toString();

            $xmlData = null;
            $reader = null;
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
                $reader = new XMLReader();
                $reader->xml($xmlData);
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
                $reader = new XMLReader();
                $reader->xml($xmlData);
            }

            // If reader valid, process the data!
            if ($reader) {
                // Keep track of channels and programmes
                $channels = [];
                $programmes = [];

                // Default channel data structure
                $defaultChannelData = [
                    'name' => null,
                    'display_name' => null,
                    'lang' => null,
                    'icon' => null,
                    'channel_id' => null,
                    'epg_id' => $epgId,
                    'user_id' => $userId,
                    'import_batch_no' => $batchNo,
                    'programmes' => [],
                ];

                // Loop through the XML data
                while ($reader->read()) {
                    // Only consider XML elements
                    if ($reader->nodeType == XMLReader::ELEMENT) {
                        /*
                         * Process channel data
                         */
                        if ($reader->name === 'channel') {
                            $channel_id = trim($reader->getAttribute('id'));
                            $innerXML = $reader->readOuterXml();
                            $innerReader = new XMLReader();
                            $innerReader->xml($innerXML);
                            $elementData = [
                                ...$defaultChannelData
                            ];

                            // Get the node data
                            while ($innerReader->read()) {
                                if ($innerReader->nodeType == XMLReader::ELEMENT) {
                                    if (!array_key_exists($channel_id, $channels)) {
                                        switch ($innerReader->name) {
                                            case 'channel':
                                                $elementData['channel_id'] = $channel_id;
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
                            }

                            // Add the channel to the collection
                            $channels[$channel_id] = $elementData;

                            // Close the inner XMLReader
                            $innerReader->close();
                        } elseif ($reader->name === 'programme') {
                            /* 
                             * Process program data 
                             */
                            $channel_id = trim($reader->getAttribute('channel'));
                            $xmlData = trim($reader->readOuterXml());
                            if (!array_key_exists($channel_id, $programmes)) {
                                $programmes[$channel_id] = [];
                            }
                            $programmes[$channel_id][] = $xmlData;
                        }
                    }
                }
                // Close the main XMLReader
                $reader->close();


                // @TODO: add to database...


                // Update the EPG status
                $this->epg->update([
                    'status' => EpgStatus::Completed,
                    'errors' => null,
                ]);
            } else {
                throw new Exception('Failed to read the XML data.');
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
