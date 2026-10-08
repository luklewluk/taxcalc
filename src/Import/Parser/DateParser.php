<?php

declare(strict_types=1);

namespace App\Import\Parser;

use App\Exception\InvalidDateException;
use DateTimeImmutable;

/**
 * Parses the date notations of the broker exports and of dates typed into the
 * workbench form.
 *
 * The time component is always discarded: NBP rates are per day, so keeping a
 * time would only make dates compare unequal for no benefit.
 */
final class DateParser
{
    /**
     * Formats are tried in order. The leading `!` resets all unspecified
     * fields to zero so no "now" leaks into the value.
     *
     * The value is an optional shape the input must match first, used where
     * PHP's own parser is more permissive than the format it is given.
     *
     * @var array<string, string|null>
     */
    private const array FORMATS = [
        '!Y-m-d H:i:s' => null,
        '!Y-m-d\TH:i:s' => null,
        '!Y-m-d' => null,
        '!Ymd' => null,
        '!d.m.Y' => null,
        '!d/m/Y' => null,
        // DEGIRO writes every date day-first with dashes. It is tried after the
        // ISO formats, which cannot collide with it: `!Y-m-d` leaves trailing
        // data on a DD-MM-YYYY value and is rejected before we get here.
        //
        // The shape is enforced because `Y` happily reads a two-digit year:
        // "15-03-25" would otherwise become the year 25 instead of being
        // refused as ambiguous.
        '!d-m-Y' => '/^\d{1,2}-\d{1,2}-\d{4}$/',
    ];

    public static function parse(string $value): DateTimeImmutable
    {
        $trimmed = trim($value);

        if ('' === $trimmed) {
            throw InvalidDateException::unrecognised($value);
        }

        foreach (self::FORMATS as $format => $shape) {
            if (null !== $shape && 1 !== preg_match($shape, $trimmed)) {
                continue;
            }

            $date = DateTimeImmutable::createFromFormat($format, $trimmed);

            // createFromFormat silently accepts overflowing values such as
            // 2024-02-31 and rolls them over; the warning count catches that.
            $errors = DateTimeImmutable::getLastErrors();
            if (false === $date || (false !== $errors && (0 !== $errors['warning_count'] || 0 !== $errors['error_count']))) {
                continue;
            }

            // `Y` also reads a two-digit year, so "15-03-25" would come back as
            // the year 25 under an ISO format. A statement date can only be a
            // four-digit year; anything else means the columns were misread and
            // must not be settled.
            if ((int) $date->format('Y') < 1000) {
                continue;
            }

            return $date->setTime(0, 0);
        }

        throw InvalidDateException::unrecognised($value);
    }

    /**
     * A date plus the clock time from a separate column, as DEGIRO exports them.
     *
     * The time exists only to order trades that settled on the same day: without
     * it, a buy and a sell dated the same day can be ordered only by the order
     * the files happened to be uploaded in, which would decide whether a
     * position matched at all. It is deliberately *not* part of the settled
     * record - the day is what picks the NBP rate and the tax year.
     *
     * A blank time is accepted as midnight, because some exports omit it. A
     * malformed one is refused rather than rounded down to midnight: silently
     * moving an afternoon sale to the start of the day could reorder the queue.
     *
     * @throws InvalidDateException when the date or the time cannot be read
     */
    public static function parseWithTime(string $date, string $time): DateTimeImmutable
    {
        $parsed = self::parse($date);
        $trimmed = trim($time);

        if ('' === $trimmed) {
            return $parsed;
        }

        if (1 !== preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $trimmed, $matches)) {
            throw InvalidDateException::unrecognisedTime($time);
        }

        [$hour, $minute, $second] = [(int) $matches[1], (int) $matches[2], (int) ($matches[3] ?? 0)];

        if ($hour > 23 || $minute > 59 || $second > 59) {
            throw InvalidDateException::unrecognisedTime($time);
        }

        return $parsed->setTime($hour, $minute, $second);
    }
}
