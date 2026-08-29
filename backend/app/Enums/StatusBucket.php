<?php

namespace App\Enums;

enum StatusBucket: string
{
    case Open = 'open';
    case Pending = 'pending';
    case Done = 'done';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
