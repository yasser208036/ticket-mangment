<?php

namespace Tests\Feature\Database;

use App\Services\TicketReferenceGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class TicketReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_reference_of_a_year(): void
    {
        $reference = DB::transaction(fn () => app(TicketReferenceGenerator::class)->next(2026));
        $this->assertSame('TKT-2026-000001', $reference);
    }

    public function test_references_increment(): void
    {
        $references = DB::transaction(fn () => [
            app(TicketReferenceGenerator::class)->next(2026),
            app(TicketReferenceGenerator::class)->next(2026),
            app(TicketReferenceGenerator::class)->next(2026),
        ]);
        $this->assertSame(['TKT-2026-000001', 'TKT-2026-000002', 'TKT-2026-000003'], $references);
    }

    public function test_years_have_independent_sequences(): void
    {
        $references = DB::transaction(fn () => [app(TicketReferenceGenerator::class)->next(2026), app(TicketReferenceGenerator::class)->next(2027)]);
        $this->assertSame(['TKT-2026-000001', 'TKT-2027-000001'], $references);
    }

    public function test_calling_outside_a_transaction_throws(): void
    {
        // RefreshDatabase itself wraps every test in a transaction, so
        // DB::transactionLevel() is already 1 the moment this method starts.
        // Commit that wrapper first so "outside a transaction" is real, then
        // reopen it so RefreshDatabase's own rollback at tearDown still has a
        // transaction to close. Nothing is written before next() throws, so
        // there is nothing for that rollback to undo either way.
        DB::commit();
        try {
            $this->expectException(LogicException::class);
            app(TicketReferenceGenerator::class)->next(2026);
        } finally {
            DB::beginTransaction();
        }
    }

    public function test_rollback_returns_the_number(): void
    {
        try {
            DB::transaction(function (): never {
                app(TicketReferenceGenerator::class)->next(2026);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        $reference = DB::transaction(fn () => app(TicketReferenceGenerator::class)->next(2026));
        $this->assertSame('TKT-2026-000001', $reference);
    }
}
