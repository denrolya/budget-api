<?php

namespace App\DTO;

class CurrentPrevious
{

    public function __construct($currentPeriodData, $previousPeriodData)
    {
        $this->current = $currentPeriodData;
        $this->previous = $previousPeriodData;
    }
    
    public mixed $current;

    public mixed $previous;
}
