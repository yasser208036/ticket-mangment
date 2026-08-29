<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use LogicException;

class TicketReferenceGenerator
{
    public function next(?int $year = null): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('TicketReferenceGenerator must be called inside a database transaction.');
        }

        $referenceYear = $year ?? (int) now()->year;
        DB::statement(
            'INSERT INTO ticket_sequences (year, next_number) VALUES (?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE next_number = LAST_INSERT_ID(next_number + 1)',
            [$referenceYear],
        );
        $sequenceNumber = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS number', [], false)->number;

        if ($sequenceNumber > 999999) {
            throw new LogicException("Ticket references for {$referenceYear} are exhausted: the TKT-YYYY-NNNNNN format holds 999999 per year.");
        }

        return sprintf('TKT-%04d-%06d', $referenceYear, $sequenceNumber);
    }
}
