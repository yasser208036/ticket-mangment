<?php

namespace App\Services;

use App\Models\Requester;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;

class TicketSearch
{
    public const MIN_TOKEN_LENGTH = 3;

    private const OPERATORS = '/[+\-><()~*"@]+/';

    public static function tokens(string $raw): array
    {
        $cleaned = preg_replace(self::OPERATORS, ' ', $raw) ?? '';

        return array_values(array_filter(preg_split('/\s+/', trim($cleaned)) ?: [], fn (string $token): bool => mb_strlen($token) >= self::MIN_TOKEN_LENGTH));
    }

    public static function booleanQuery(string $raw): string
    {
        return implode(' ', array_map(fn (string $token): string => '+'.$token.'*', self::tokens($raw)));
    }

    /** @param Builder<Ticket> $query */
    public function apply(Builder $query, string $raw): void
    {
        $boolean = self::booleanQuery($raw);
        $like = '%'.addcslashes($raw, '%_\\').'%';
        $query->where(function (Builder $search) use ($boolean, $like): void {
            if ($boolean !== '') {
                $search->whereIn('id', Ticket::query()->select('id')->whereFullText(['subject', 'description'], $boolean, ['mode' => 'boolean']));
            }
            $search->orWhere('reference', 'like', $like)
                ->orWhereIn('requester_id', Requester::query()->select('id')->where(fn (Builder $requester) => $requester->whereLike('name', $like)->orWhereLike('email', $like)));
        });
    }

    /** @param Builder<Ticket> $query */
    public function applyRelevanceOrder(Builder $query, string $raw): void
    {
        $query->orderByRaw('(reference = ?) desc', [$raw]);
        if (($boolean = self::booleanQuery($raw)) !== '') {
            $query->orderByRaw('match (subject, description) against (? in boolean mode) desc', [$boolean]);
        }
    }
}
