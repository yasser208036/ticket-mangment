<?php

namespace Tests\Feature\Queue;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Jobs\FailingJob;
use Tests\TestCase;

class QueueInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_default_queue_connection_uses_the_database_driver(): void
    {
        $this->assertSame('database', config('queue.default'));
        $this->assertSame('database', config('queue.connections.database.driver'));
    }

    public function test_the_queue_tables_exist_with_the_columns_the_driver_needs(): void
    {
        $this->assertTrue(Schema::hasColumns('jobs', ['queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at']));
        $this->assertTrue(Schema::hasColumns('failed_jobs', ['uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at']));
        $this->assertTrue(Schema::hasTable('job_batches'));
    }

    public function test_a_dispatched_job_is_stored_rather_than_run_inline(): void
    {
        FailingJob::dispatch();

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_a_failing_job_lands_in_failed_jobs_with_its_exception(): void
    {
        FailingJob::dispatch();

        $this->artisan('queue:work', ['--once' => true]);

        $this->assertSame(0, DB::table('jobs')->count());
        $failed = DB::table('failed_jobs')->get();
        $this->assertCount(1, $failed);
        $this->assertSame('database', $failed[0]->connection);
        $this->assertSame('default', $failed[0]->queue);
        $this->assertStringContainsString(FailingJob::MESSAGE, $failed[0]->exception);
    }

    public function test_a_failed_job_can_be_pushed_back_onto_the_queue_by_command(): void
    {
        FailingJob::dispatch();
        $this->artisan('queue:work', ['--once' => true]);

        $this->artisan('queue:retry', ['id' => ['all']])->assertExitCode(0);

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(1, DB::table('jobs')->count());
    }
}
