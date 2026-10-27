<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class BookingRules
{
    public const TIMEZONE = 'Asia/Tbilisi';

    // Backward-compatible default when an older client omits the duration.
    public const DURATION_MINUTES = 120;

    public const DURATIONS = [90, 120, 150, 180];

    public const MAX_DURATION_MINUTES = 180;

    public const SLOT_MINUTES = 30;

    public const ADVANCE_DAYS = 90;

    public const MAX_GUESTS = 255;

    public function validateDuration(int $minutes): void
    {
        if (! in_array($minutes, self::DURATIONS, true)) {
            throw ValidationException::withMessages(['duration_minutes' => 'Choose a duration of 1.5, 2, 2.5 or 3 hours.']);
        }
    }

    public function validateGuests(int $guests): void
    {
        if ($guests < 1 || $guests > self::MAX_GUESTS) {
            throw ValidationException::withMessages(['guest_count' => 'Choose a whole number of guests between 1 and '.self::MAX_GUESTS.'.']);
        }
    }

    public function start(string $date, string $time): CarbonImmutable
    {
        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$time, self::TIMEZONE);
        } catch (\InvalidArgumentException) {
            $start = null;
        }

        if (! $start || $start->format('Y-m-d H:i') !== $date.' '.$time || $start->minute % self::SLOT_MINUTES !== 0) {
            throw ValidationException::withMessages(['time' => 'Choose a valid date and a 30-minute time slot.']);
        }

        $this->validateStart($start);

        return $start->utc();
    }

    public function validateStart(CarbonImmutable $start): void
    {
        $local = $start->setTimezone(self::TIMEZONE);
        if ($local->minute % self::SLOT_MINUTES !== 0 || $local->second !== 0 || $local->micro !== 0) {
            throw ValidationException::withMessages(['time' => 'Choose a valid 30-minute time slot.']);
        }

        if ($start->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw ValidationException::withMessages(['time' => 'The booking must start in the future.']);
        }

        if ($start->greaterThan(CarbonImmutable::now(self::TIMEZONE)->addDays(self::ADVANCE_DAYS)->endOfDay())) {
            throw ValidationException::withMessages(['date' => 'Choose a date within the next 90 days.']);
        }
    }
}
