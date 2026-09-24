<?php
/**
 * Opening-hours helpers shared by the patient clinic browser, clinic details
 * and Live Queue pages. Clinic rows need OpenDays (ISO weekdays, comma-
 * separated), OpenTime and CloseTime.
 */

/** Current time from MySQL, so open/closed matches the same clock the queue uses. */
function clinicNow(PDO $pdo): DateTimeImmutable
{
    return new DateTimeImmutable((string) $pdo->query('SELECT NOW()')->fetchColumn());
}

/** "8 AM" / "8:30 AM". */
function clinicFormatTime(string $time): string
{
    $ts = strtotime($time);
    return date(date('i', $ts) === '00' ? 'g A' : 'g:i A', $ts);
}

/**
 * Returns ['open' => bool, 'label' => badge text, 'next' => closed-state hint or null].
 * e.g. ['open' => false, 'label' => 'Closed · opens 8 AM', 'next' => 'Book for tomorrow'].
 */
function clinicOpenStatus(array $clinic, DateTimeImmutable $now): array
{
    static $dayShort = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    static $dayLong = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    $today = (int) $now->format('N');
    $time = $now->format('H:i:s');
    $openDays = array_map('intval', explode(',', (string) $clinic['OpenDays']));
    $openToday = in_array($today, $openDays, true);

    if ($openToday && $time >= $clinic['OpenTime'] && $time < $clinic['CloseTime']) {
        return ['open' => true, 'label' => 'Open · until ' . clinicFormatTime($clinic['CloseTime']), 'next' => null];
    }
    if ($openToday && $time < $clinic['OpenTime']) {
        return ['open' => false, 'label' => 'Closed · opens ' . clinicFormatTime($clinic['OpenTime']), 'next' => 'Opens today at ' . clinicFormatTime($clinic['OpenTime'])];
    }
    for ($offset = 1; $offset <= 7; $offset++) {
        $day = ($today + $offset - 1) % 7 + 1;
        if (in_array($day, $openDays, true)) {
            return [
                'open'  => false,
                'label' => 'Closed · opens ' . ($offset === 1 ? '' : $dayShort[$day] . ' ') . clinicFormatTime($clinic['OpenTime']),
                'next'  => 'Book for ' . ($offset === 1 ? 'tomorrow' : $dayLong[$day]),
            ];
        }
    }
    return ['open' => false, 'label' => 'Closed', 'next' => 'Hours not available'];
}
