<?php

namespace App\Enums;

enum ComponentStatus: string
{
    case Operational = 'operational';
    case Degraded = 'degraded_performance';
    case PartialOutage = 'partial_outage';
    case MajorOutage = 'major_outage';
    case Maintenance = 'under_maintenance';
    case Unknown = 'unknown';

    public function weight(): int
    {
        return match ($this) {
            self::Operational => 0,
            self::Unknown => 1,
            self::Maintenance => 2,
            self::Degraded => 3,
            self::PartialOutage => 4,
            self::MajorOutage => 5,
        };
    }
}
