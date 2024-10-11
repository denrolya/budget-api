<?php

namespace App\Tests;

use PHPUnit\Runner\AfterTestHook;

class ResourceUsageListener implements AfterTestHook
{
    public function executeAfterTest(string $test, float $time): void
    {
        $memoryUsage = memory_get_usage(true) / 1024 / 1024;
        $peakMemoryUsage = memory_get_peak_usage(true) / 1024 / 1024;

        echo sprintf(
            "\n[INFO] Test '%s' finished. Time: %.2f seconds. Memory usage: %.2f MB (Peak: %.2f MB)\n",
            $test,
            $time,
            $memoryUsage,
            $peakMemoryUsage
        );
    }
}
