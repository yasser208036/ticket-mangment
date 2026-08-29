<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    public function test_the_command_is_scheduled_daily(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'tickets:flag-stale'));

        $this->assertNotNull($event, 'tickets:flag-stale is not registered on the scheduler.');
        $this->assertSame('0 2 * * *', $event->expression);
    }
}
