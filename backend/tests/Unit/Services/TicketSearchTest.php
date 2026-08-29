<?php

namespace Tests\Unit\Services;

use App\Services\TicketSearch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TicketSearchTest extends TestCase
{
    public function test_tokens_drop_short_terms_and_strip_boolean_operators(): void
    {
        $this->assertSame(['the', 'vpn'], TicketSearch::tokens('on the vpn'));
        $this->assertSame(['Q&A', 'urgent'], TicketSearch::tokens('Q&A (urgent)'));
        $this->assertSame([], TicketSearch::tokens('C++'));
    }

    public function test_boolean_query_builds_required_prefix_tokens(): void
    {
        $this->assertSame('+printer* +tray*', TicketSearch::booleanQuery('printer tray'));
    }

    #[DataProvider('emptyBooleanQueries')]
    public function test_boolean_query_is_empty_when_no_tokens_survive(string $raw): void
    {
        $this->assertSame('', TicketSearch::booleanQuery($raw));
    }

    public static function emptyBooleanQueries(): array
    {
        return [['C++'], ['on'], ['   '], ['***']];
    }

    public function test_tokens_count_characters_instead_of_bytes(): void
    {
        $this->assertSame(['كتب'], TicketSearch::tokens('لا كتب'));
    }
}
