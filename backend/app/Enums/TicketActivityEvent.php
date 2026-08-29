<?php

namespace App\Enums;

enum TicketActivityEvent: string
{
    case Created = 'created';
    case CategoryChanged = 'category_changed';
    case NoteAdded = 'note_added';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Assigned = 'assigned';
    case Claimed = 'claimed';
    case Unassigned = 'unassigned';
    case StatusChanged = 'status_changed';
    case Reopened = 'reopened';
    case Escalated = 'escalated';
    case Stale = 'stale';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
