<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

class OperationalDate
{
    public function timezone(): string
    {
        $timezone = config('checklist.timezone', 'Asia/Kuala_Lumpur');

        return is_string($timezone) && $timezone !== ''
            ? $timezone
            : 'Asia/Kuala_Lumpur';
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->startOfDay();
    }

    public function nowUtc(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    public function fromDateString(string $date): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, $this->timezone());

        if ($parsed === false) {
            throw new InvalidArgumentException('Tarikh senarai semak mesti menggunakan format YYYY-MM-DD.');
        }

        return $parsed->startOfDay();
    }

    public function isToday(string $date): bool
    {
        return hash_equals($this->today()->toDateString(), $date);
    }

    public function isWithinMaterializationWindow(CarbonImmutable $date): bool
    {
        $today = $this->today();
        $pastDays = max(0, (int) config('checklist.past_materialization_days', 365));
        $futureDays = max(0, (int) config('checklist.future_materialization_days', 365));

        return $date->greaterThanOrEqualTo($today->subDays($pastDays))
            && $date->lessThanOrEqualTo($today->addDays($futureDays));
    }
}
