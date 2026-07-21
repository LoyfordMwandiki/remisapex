<?php

/**
 * Validate that a date string (Y-m-d) is not in the future.
 */
function validateNotFutureDate(string $date, string $fieldLabel = 'Date'): ?string
{
    if ($date === '') {
        return null;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        return $fieldLabel . ' must be a valid date (YYYY-MM-DD).';
    }

    // Keep server validation aligned with the application's local calendar.
    $today = new DateTime('today', new DateTimeZone('Africa/Nairobi'));
    if ($dt > $today) {
        return $fieldLabel . ' cannot be in the future.';
    }

    return null;
}

/**
 * Validate end date is on or after start date when both are provided.
 */
function validateDateRange(?string $startDate, ?string $endDate, string $startLabel = 'Start date', string $endLabel = 'End date'): ?string
{
    if ($startDate === '' || $endDate === '') {
        return null;
    }

    $start = DateTime::createFromFormat('Y-m-d', $startDate);
    $end = DateTime::createFromFormat('Y-m-d', $endDate);

    if (!$start || !$end) {
        return null;
    }

    if ($end < $start) {
        return $endLabel . ' must be on or after ' . strtolower($startLabel) . '.';
    }

    return null;
}

/**
 * Resolve from/to dates for periodic report presets.
 */
function resolveReportDateRange(string $period, ?string $fromDate = null, ?string $toDate = null): array
{
    $today = new DateTime('today');

    if ($fromDate !== null && $fromDate !== '' && $toDate !== null && $toDate !== '') {
        return ['from' => $fromDate, 'to' => $toDate];
    }

    switch ($period) {
        case 'daily':
            return ['from' => $today->format('Y-m-d'), 'to' => $today->format('Y-m-d')];
        case 'weekly':
            $from = clone $today;
            $from->modify('-6 days');
            return ['from' => $from->format('Y-m-d'), 'to' => $today->format('Y-m-d')];
        case 'monthly':
            return [
                'from' => $today->format('Y-m-01'),
                'to' => $today->format('Y-m-d'),
            ];
        case 'quarterly':
            $month = (int) $today->format('n');
            $quarterStart = (int) floor(($month - 1) / 3) * 3 + 1;
            $from = DateTime::createFromFormat('Y-n-j', $today->format('Y') . '-' . $quarterStart . '-1');
            return ['from' => $from->format('Y-m-d'), 'to' => $today->format('Y-m-d')];
        case 'yearly':
            return [
                'from' => $today->format('Y-01-01'),
                'to' => $today->format('Y-m-d'),
            ];
        default:
            $from = clone $today;
            $from->modify('-29 days');
            return ['from' => $from->format('Y-m-d'), 'to' => $today->format('Y-m-d')];
    }
}
