<?php
/**
 * Booking availability, driven by physicians' date-based calendars
 * (PhysicianDateAvailability). A time is bookable when an eligible
 * physician -- the chosen one, or any active physician at the clinic when
 * the patient has no preference -- has an hour block covering it that day
 * and there's capacity left in that hour.
 *
 * Capacity rules:
 *  - Each block allows PatientsPerHour bookings per clock hour (8:00 and
 *    8:30 share the 8 AM hour); blank means DEFAULT_PATIENTS_PER_HOUR.
 *  - Counted bookings: Confirmed ones (incl. walk-ins), paid Pending ones,
 *    and unpaid Pending ones made in the last UNPAID_HOLD_MINUTES (a short
 *    hold while the patient pays). Abandoned unpaid requests free up.
 *  - "No preference" bookings (no physician yet) count against the clinic's
 *    combined capacity for that hour, so they can't overbook it either.
 * Dates with no hours ("not set") and days off are never bookable.
 */

const BOOKING_SLOT_MINUTES = 30;
const DEFAULT_PATIENTS_PER_HOUR = 4;
const UNPAID_HOLD_MINUTES = 15;

/** SQL condition for appointments that currently take up a slot. */
function occupyingAppointmentSql(string $alias = ''): string
{
    $a = $alias !== '' ? $alias . '.' : '';
    return "({$a}Status = 'Confirmed' OR ({$a}Status = 'Pending' AND ({$a}BookingFeePaid = 1 OR {$a}CreatedAt >= NOW() - INTERVAL " . UNPAID_HOLD_MINUTES . " MINUTE)))";
}

/** Active physician IDs at a clinic. */
function clinicPhysicianIds(PDO $pdo, int $clinicId): array
{
    $stmt = $pdo->prepare(
        "SELECT u.UserID FROM Users u JOIN Roles r ON r.RoleID = u.RoleID
         WHERE r.RoleName = 'Physician' AND u.ClinicID = ? AND u.Status = 'Active' AND u.DeletedAt IS NULL"
    );
    $stmt->execute([$clinicId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Open start times ('HH:MM') on a date, in BOOKING_SLOT_MINUTES steps.
 * $excludeAppointmentId skips that appointment when counting capacity (for
 * rescheduling an existing booking). Returns [] for unknown physicians.
 */
function bookableTimes(PDO $pdo, int $clinicId, ?int $physicianId, string $date, ?int $excludeAppointmentId = null): array
{
    $clinicIds = clinicPhysicianIds($pdo, $clinicId);
    if (!$clinicIds || ($physicianId && !in_array($physicianId, $clinicIds, true))) return [];
    $in = implode(',', array_fill(0, count($clinicIds), '?'));

    $blocksStmt = $pdo->prepare(
        "SELECT PhysicianID, StartTime, EndTime, PatientsPerHour FROM PhysicianDateAvailability
         WHERE PhysicianID IN ({$in}) AND AvailDate = ? AND IsDayOff = 0"
    );
    $blocksStmt->execute([...$clinicIds, $date]);
    $blocks = $blocksStmt->fetchAll();
    if (!$blocks) return [];

    // Bookings taking up capacity that day, per physician (0 = unassigned) per hour.
    $bookedStmt = $pdo->prepare(
        "SELECT COALESCE(PhysicianID, 0) AS Pid, HOUR(AppointmentTime) AS Hr, COUNT(*) AS Booked
         FROM Appointments
         WHERE ClinicID = ? AND AppointmentDate = ? AND AppointmentID <> ? AND " . occupyingAppointmentSql() . "
         GROUP BY COALESCE(PhysicianID, 0), HOUR(AppointmentTime)"
    );
    $bookedStmt->execute([$clinicId, $date, $excludeAppointmentId ?? 0]);
    $booked = [];
    foreach ($bookedStmt->fetchAll() as $row) $booked[(int) $row['Pid']][(int) $row['Hr']] = (int) $row['Booked'];

    [$today, $nowTime] = explode(' ', (string) $pdo->query("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i')")->fetchColumn());
    if ($date < $today) return [];

    // Capacity per physician per slot start time.
    $capacity = []; // time => [physicianId => perHour]
    foreach ($blocks as $block) {
        $limit = $block['PatientsPerHour'] ? (int) $block['PatientsPerHour'] : DEFAULT_PATIENTS_PER_HOUR;
        $end = strtotime($block['EndTime']);
        for ($t = strtotime($block['StartTime']); $t + BOOKING_SLOT_MINUTES * 60 <= $end; $t += BOOKING_SLOT_MINUTES * 60) {
            $capacity[date('H:i', $t)][(int) $block['PhysicianID']] = $limit;
        }
    }
    ksort($capacity);

    $times = [];
    foreach ($capacity as $time => $perPhysician) {
        if ($date === $today && $time <= $nowTime) continue;
        $hour = (int) substr($time, 0, 2);

        // Clinic-wide: everyone covering this time, plus unassigned bookings.
        $clinicCap = array_sum($perPhysician);
        $clinicBooked = $booked[0][$hour] ?? 0;
        foreach ($perPhysician as $pid => $cap) $clinicBooked += $booked[$pid][$hour] ?? 0;
        if ($clinicBooked >= $clinicCap) continue;

        if ($physicianId) {
            if (!isset($perPhysician[$physicianId])) continue;
            if (($booked[$physicianId][$hour] ?? 0) >= $perPhysician[$physicianId]) continue;
        }
        $times[] = $time;
    }
    return $times;
}

/**
 * Dates in [from, to] (today onwards) with hours for an eligible physician.
 * Returns ['open' => [dates with at least one bookable time], 'full' => [dates with hours but no capacity left]].
 */
function bookableDates(PDO $pdo, int $clinicId, ?int $physicianId, string $from, string $to): array
{
    $ids = $physicianId ? [$physicianId] : clinicPhysicianIds($pdo, $clinicId);
    if (!$ids) return ['open' => [], 'full' => []];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT DISTINCT AvailDate FROM PhysicianDateAvailability
         WHERE PhysicianID IN ({$in}) AND IsDayOff = 0 AND AvailDate BETWEEN GREATEST(?, CURDATE()) AND ?
         ORDER BY AvailDate"
    );
    $stmt->execute([...$ids, $from, $to]);

    $result = ['open' => [], 'full' => []];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $date) {
        $result[bookableTimes($pdo, $clinicId, $physicianId, $date) ? 'open' : 'full'][] = $date;
    }
    return $result;
}

/** Whether a specific date + time ('HH:MM' or 'HH:MM:SS') can be booked. */
function isBookable(PDO $pdo, int $clinicId, ?int $physicianId, string $date, string $time, ?int $excludeAppointmentId = null): bool
{
    return in_array(substr($time, 0, 5), bookableTimes($pdo, $clinicId, $physicianId, $date, $excludeAppointmentId), true);
}

/**
 * Serialises bookings per clinic so two patients can't take the last spot
 * at the same moment: check + insert must happen between these calls.
 */
function lockClinicBooking(PDO $pdo, int $clinicId): bool
{
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $stmt->execute(['hq_booking_clinic_' . $clinicId]);
    return (int) $stmt->fetchColumn() === 1;
}

function unlockClinicBooking(PDO $pdo, int $clinicId): void
{
    $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(['hq_booking_clinic_' . $clinicId]);
}
