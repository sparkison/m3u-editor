<?php

namespace App\Traits;

use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Illuminate\Console\Scheduling\ManagesFrequencies;

trait Schedulable
{

    use ManagesFrequencies;

    // Info: https://crontab.guru/
    protected $expression = '0 0 * * *'; // Default to daily

    protected $timezone;

    public function isDue()
    {
        $date = Carbon::now();

        if ($this->timezone) {
            $date->setTimezone($this->timezone);
        }

        return (new CronExpression($this->expression))->isDue($date->toDateTimeString());
    }

    public function nextDue()
    {
        return Carbon::instance((new CronExpression($this->expression))->getNextRunDate());
    }

    public function lastDue()
    {
        return Carbon::instance((new CronExpression($this->expression))->getPreviousRunDate());
    }
}
