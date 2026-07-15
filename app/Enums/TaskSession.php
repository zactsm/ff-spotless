<?php

namespace App\Enums;

enum TaskSession: string
{
    case Morning = 'morning';
    case Afternoon = 'afternoon';
    case Evening = 'evening';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $session): string => $session->value,
            self::cases(),
        );
    }
}
