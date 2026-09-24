<?php
/**
 * Writes one row to AuditLogs. Call this after any admin-visible mutation
 * (clinic changes, team account changes, announcements, payments, etc.)
 * so the Activity Log page has something to show. Failures here are
 * logged but never allowed to break the action that triggered them.
 */
function logActivity(PDO $pdo, ?int $userId, ?int $clinicId, string $action, ?string $description = null): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO AuditLogs (ClinicID, UserID, ActionExecuted, Description) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$clinicId, $userId, $action, $description]);
    } catch (PDOException $e) {
        error_log('logActivity failed: ' . $e->getMessage());
    }
}
