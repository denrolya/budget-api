<?php

namespace App\DTO;

class CurrentPrevious
{

    public function __construct($currentPeriodData, $previousPeriodData)
    {
        $this->current = $currentPeriodData;
        $this->previous = $previousPeriodData;
    }

    /**
     * @var mixed
     */
    public $current;

    /**
     * @var mixed
     */
    public $previous;
}
