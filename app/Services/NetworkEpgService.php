<?php

namespace App\Services;

use App\Models\Network;
use App\Models\NetworkProgramme;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Service to generate XMLTV EPG data for Networks
 */
class NetworkEpgService
{
    /**
     * Generate XMLTV output for a single network.
     */
    public function generateXmltvForNetwork(Network $network): string
    {
        $output = $this->getXmlHeader();
        $output .= $this->generateChannelXml($network);
        $output .= $this->generateProgrammesXml($network);
        $output .= $this->getXmlFooter();

        return $output;
    }

    /**
     * Generate XMLTV output for multiple networks.
     */
    public function generateXmltvForNetworks(Collection $networks): string
    {
        $output = $this->getXmlHeader();

        // Output all channel tags first
        foreach ($networks as $network) {
            $output .= $this->generateChannelXml($network);
        }

        // Then output all programme tags
        foreach ($networks as $network) {
            $output .= $this->generateProgrammesXml($network);
        }

        $output .= $this->getXmlFooter();

        return $output;
    }

    /**
     * Stream XMLTV output for a network (for large schedules).
     */
    public function streamXmltvForNetwork(Network $network): void
    {
        echo $this->getXmlHeader();
        echo $this->generateChannelXml($network);

        // Stream programmes in chunks to manage memory
        $network->programmes()
            ->orderBy('start_time')
            ->chunk(100, function ($programmes) use ($network) {
                foreach ($programmes as $programme) {
                    echo $this->formatProgrammeXml($network, $programme);
                }
            });

        echo $this->getXmlFooter();
    }

    /**
     * Stream XMLTV output for multiple networks (for large schedules).
     */
    public function streamXmltvForNetworks(Collection $networks): void
    {
        echo $this->getXmlHeader();

        // Output all channel tags first
        foreach ($networks as $network) {
            echo $this->generateChannelXml($network);
        }

        // Stream programmes for each network
        foreach ($networks as $network) {
            $network->programmes()
                ->orderBy('start_time')
                ->chunk(100, function ($programmes) use ($network) {
                    foreach ($programmes as $programme) {
                        echo $this->formatProgrammeXml($network, $programme);
                    }
                });
        }

        echo $this->getXmlFooter();
    }

    /**
     * Get XMLTV header.
     */
    protected function getXmlHeader(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'.PHP_EOL.
            '<!DOCTYPE tv SYSTEM "xmltv.dtd">'.PHP_EOL.
            '<tv generator-info-name="M3U Editor Networks" generator-info-url="'.url('').'">'.PHP_EOL;
    }

    /**
     * Get XMLTV footer.
     */
    protected function getXmlFooter(): string
    {
        return '</tv>'.PHP_EOL;
    }

    /**
     * Generate channel XML for a network.
     */
    protected function generateChannelXml(Network $network): string
    {
        $channelId = $this->getChannelId($network);
        $name = htmlspecialchars($network->name, ENT_XML1);
        $logo = $network->logo ? htmlspecialchars($network->logo, ENT_XML1) : null;

        $xml = '  <channel id="'.$channelId.'">'.PHP_EOL;
        $xml .= '    <display-name>'.$name.'</display-name>'.PHP_EOL;

        if ($network->channel_number) {
            $xml .= '    <display-name>'.$network->channel_number.'</display-name>'.PHP_EOL;
        }

        if ($logo) {
            $xml .= '    <icon src="'.$logo.'"/>'.PHP_EOL;
        }

        $xml .= '  </channel>'.PHP_EOL;

        return $xml;
    }

    /**
     * Generate programmes XML for a network.
     */
    protected function generateProgrammesXml(Network $network): string
    {
        $xml = '';

        $programmes = $network->programmes()
            ->where('end_time', '>', Carbon::now()->subDay())
            ->orderBy('start_time')
            ->get();

        foreach ($programmes as $programme) {
            $xml .= $this->formatProgrammeXml($network, $programme);
        }

        return $xml;
    }

    /**
     * Format a single programme as XML.
     */
    protected function formatProgrammeXml(Network $network, NetworkProgramme $programme): string
    {
        $channelId = $this->getChannelId($network);
        $start = $this->formatXmltvDateTime($programme->start_time);
        $stop = $this->formatXmltvDateTime($programme->end_time);
        $title = htmlspecialchars($programme->title, ENT_XML1);

        $xml = '  <programme channel="'.$channelId.'" start="'.$start.'" stop="'.$stop.'">'.PHP_EOL;
        $xml .= '    <title>'.$title.'</title>'.PHP_EOL;

        // Try to get description from programme or contentable's info field
        $description = $programme->description;
        $content = $programme->contentable;
        $info = null;

        if ($content) {
            $info = $content->info ?? null;
            if (! $description && $info) {
                $description = $info['plot'] ?? $info['description'] ?? null;
            }
        }

        if ($description) {
            $desc = htmlspecialchars($description, ENT_XML1);
            $xml .= '    <desc>'.$desc.'</desc>'.PHP_EOL;
        }

        // Add credits (director, actors)
        if ($info) {
            $hasCredits = ! empty($info['director']) || ! empty($info['cast']) || ! empty($info['actors']);
            if ($hasCredits) {
                $xml .= '    <credits>'.PHP_EOL;

                if (! empty($info['director'])) {
                    $directors = explode(',', $info['director']);
                    foreach ($directors as $director) {
                        $director = trim($director);
                        if ($director) {
                            $xml .= '      <director>'.htmlspecialchars($director, ENT_XML1).'</director>'.PHP_EOL;
                        }
                    }
                }

                $actors = $info['cast'] ?? $info['actors'] ?? null;
                if ($actors) {
                    $actorList = explode(',', $actors);
                    foreach (array_slice($actorList, 0, 10) as $actor) { // Limit to 10 actors
                        $actor = trim($actor);
                        if ($actor) {
                            $xml .= '      <actor>'.htmlspecialchars($actor, ENT_XML1).'</actor>'.PHP_EOL;
                        }
                    }
                }

                $xml .= '    </credits>'.PHP_EOL;
            }
        }

        // Add year/date
        if ($info && ! empty($info['release_date'])) {
            $year = substr($info['release_date'], 0, 4);
            if ($year && is_numeric($year)) {
                $xml .= '    <date>'.$year.'</date>'.PHP_EOL;
            }
        }

        if ($programme->image) {
            $image = htmlspecialchars($programme->image, ENT_XML1);
            $xml .= '    <icon src="'.$image.'"/>'.PHP_EOL;
        }

        // Add episode info if it's an Episode
        if ($programme->contentable_type === 'App\\Models\\Episode') {
            if ($content) {
                $seasonNum = ($content->season ?? 1) - 1; // XMLTV uses 0-based
                $episodeNum = ($content->episode_num ?? 1) - 1;
                $xml .= '    <episode-num system="xmltv_ns">'.$seasonNum.'.'.$episodeNum.'.</episode-num>'.PHP_EOL;
            }
        }

        // Add category/genre
        $genre = null;
        if ($info && ! empty($info['genre'])) {
            $genre = $info['genre'];
        } else {
            $genre = $programme->contentable_type === 'App\\Models\\Episode' ? 'Series' : 'Movie';
        }
        $xml .= '    <category>'.htmlspecialchars($genre, ENT_XML1).'</category>'.PHP_EOL;

        // Add country if available
        if ($info && ! empty($info['country'])) {
            $xml .= '    <country>'.htmlspecialchars($info['country'], ENT_XML1).'</country>'.PHP_EOL;
        }

        $xml .= '  </programme>'.PHP_EOL;

        return $xml;
    }

    /**
     * Get channel ID for XMLTV.
     */
    protected function getChannelId(Network $network): string
    {
        // Use channel number if available, otherwise use network ID
        return $network->channel_number
            ? 'network-'.$network->channel_number
            : 'network-'.$network->id;
    }

    /**
     * Format datetime for XMLTV format.
     */
    protected function formatXmltvDateTime(Carbon $datetime): string
    {
        return $datetime->format('YmdHis O');
    }
}
