<?php
/**
 * Writes one row to Notifications for a clinic's staff feed. Read state is
 * shared across the whole clinic team rather than tracked per user, which
 * keeps "Mark all read" simple. Failures here are logged but never allowed
 * to break the action that triggered them.
 */
function notifyClinic(PDO $pdo, int $clinicId, string $message, ?int $relatedAppointmentId = null): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO Notifications (ClinicID, Message, RelatedAppointmentID) VALUES (?, ?, ?)'
        );
        $stmt->execute([$clinicId, $message, $relatedAppointmentId]);
    } catch (PDOException $e) {
        error_log('notifyClinic failed: ' . $e->getMessage());
    }
}

/** Writes one row to PatientNotifications for one patient's personal feed. */
function notifyPatient(PDO $pdo, int $patientId, string $message, ?int $relatedAppointmentId = null): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO PatientNotifications (PatientID, Message, RelatedAppointmentID) VALUES (?, ?, ?)'
        );
        $stmt->execute([$patientId, $message, $relatedAppointmentId]);
    } catch (PDOException $e) {
        error_log('notifyPatient failed: ' . $e->getMessage());
    }
}
