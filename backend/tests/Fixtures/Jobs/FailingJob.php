<?php

namespace Tests\Fixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class FailingJob implements ShouldQueue
{
    use Queueable;

    public const MESSAGE = 'TM-51 fixture job failed on purpose';

    public function handle(): void
    {
        throw new RuntimeException(self::MESSAGE);
    }
}
