<?php

namespace Tests\Feature\Database;

use App\Enums\TicketActivityEvent;
use App\Services\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class ActivityRecorderGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_calling_outside_a_transaction_throws(): void
    {
        // Same idiom as TicketReferenceTest::test_calling_outside_a_transaction_throws:
        // RefreshDatabase holds an open transaction for the whole test, so
        // DB::transactionLevel() is never 0 unless we commit it first and
        // reopen one before tearDown() tries to roll it back.
        DB::commit();
        try {
            $this->expectException(LogicException::class);
            app(ActivityRecorder::class)->recordMany([1], TicketActivityEvent::Created);
        } finally {
            DB::beginTransaction();
        }
    }
}
