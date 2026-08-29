<?php

namespace App\Data;

final readonly class AssignmentChange
{
    public function __construct(
        public int $actorId,
        public ?int $targetId,
        public ?string $reason,
    ) {}
}
