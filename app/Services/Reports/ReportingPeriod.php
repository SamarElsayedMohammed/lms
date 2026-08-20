<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Carbon\Carbon;

final class ReportingPeriod
{
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly Carbon $previousStart,
        public readonly Carbon $previousEnd,
        public readonly string $preset,
        public readonly string $timezone,
    ) {
    }

    /**
     * @return array{from: string, to: string}
     */
    public function currentIso(): array
    {
        return [
            'from' => $this->start->toIso8601String(),
            'to' => $this->end->toIso8601String(),
        ];
    }

    /**
     * @return array{from: string, to: string}
     */
    public function previousIso(): array
    {
        return [
            'from' => $this->previousStart->toIso8601String(),
            'to' => $this->previousEnd->toIso8601String(),
        ];
    }
}
