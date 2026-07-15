<?php

namespace App\Http\Requests\Concerns;

use App\Services\OperationalDate;
use Carbon\CarbonImmutable;

trait InteractsWithOperationalDate
{
    public function selectedDate(OperationalDate $dates): CarbonImmutable
    {
        $validated = $this->validated();

        return $dates->fromDateString($validated['date'] ?? $dates->today()->toDateString());
    }
}
