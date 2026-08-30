<?php

namespace Tests\Feature\Security;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class RawSqlAllowlistTest extends TestCase
{
    /**
     * Every whereRaw/DB::raw/selectRaw/orderByRaw call site in app/, reviewed
     * for string-concatenated user input. All seven below build their raw
     * fragment from a static string and pass any variable as a bound
     * parameter or via a whitelisted column — none interpolates user input.
     *
     * A removed call site must also update this list, so it never silently
     * grows stale in either direction.
     */
    private const ALLOWLIST = [
        'app/Http/Controllers/Api/V1/TicketActivityController.php:68',
        'app/Services/AgentWorkload.php:33',
        'app/Services/TicketSearch.php:44',
        'app/Services/TicketSearch.php:46',
        'app/Services/TicketStats.php:18',
        'app/Services/TicketStats.php:19',
        'app/Services/TicketStats.php:20',
    ];

    public function test_every_raw_sql_call_site_is_reviewed_and_allowlisted(): void
    {
        $found = [];
        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            foreach (file($file->getPathname()) as $lineNumber => $line) {
                if (preg_match('/\b(whereRaw|DB::raw|selectRaw|orderByRaw)\(/', $line)) {
                    $found[] = 'app/'.$file->getRelativePathname().':'.($lineNumber + 1);
                }
            }
        }
        sort($found);
        $expected = self::ALLOWLIST;
        sort($expected);
        $this->assertSame($expected, $found, 'A raw-SQL call site was added or removed. Review it for string-concatenated user input, then update RawSqlAllowlistTest::ALLOWLIST.');
    }
}
