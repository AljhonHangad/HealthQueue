<?php
/**
 * Simulated patient wallet. Every change to Users.WalletBalance goes
 * through one of these functions so a WalletTransactions row is always
 * written alongside it -- the balance is never updated on its own.
 * No real money moves anywhere in this file.
 *
 * Each function reads the balance with SELECT ... FOR UPDATE, so the
 * caller must already be inside a PDO transaction (beginTransaction/
 * commit/rollBack) before calling -- these functions never manage the
 * transaction themselves, since callers often need to combine a wallet
 * change with another update (e.g. marking BookingFeePaid) atomically.
 */

/** Adds funds to a patient's wallet (a simulated top-up). Caller must be inside a transaction. */
function walletTopUp(PDO $pdo, int $patientId, float $amount, string $description): float
{
    $stmt = $pdo->prepare('SELECT WalletBalance FROM Users WHERE UserID = ? FOR UPDATE');
    $stmt->execute([$patientId]);
    $newBalance = round((float) $stmt->fetchColumn() + $amount, 2);

    $pdo->prepare('UPDATE Users SET WalletBalance = ? WHERE UserID = ?')->execute([$newBalance, $patientId]);
    $pdo->prepare(
        'INSERT INTO WalletTransactions (PatientID, Type, Amount, BalanceAfter, Description) VALUES (?, ?, ?, ?, ?)'
    )->execute([$patientId, 'TopUp', $amount, $newBalance, $description]);

    return $newBalance;
}

/** Deducts funds for a booking payment. Returns false (no changes made) if the balance is insufficient. Caller must be inside a transaction. */
function walletDeduct(PDO $pdo, int $patientId, float $amount, ?int $appointmentId, string $description): bool
{
    $stmt = $pdo->prepare('SELECT WalletBalance FROM Users WHERE UserID = ? FOR UPDATE');
    $stmt->execute([$patientId]);
    $currentBalance = (float) $stmt->fetchColumn();

    if ($currentBalance < $amount) {
        return false;
    }

    $newBalance = round($currentBalance - $amount, 2);
    $pdo->prepare('UPDATE Users SET WalletBalance = ? WHERE UserID = ?')->execute([$newBalance, $patientId]);
    $pdo->prepare(
        'INSERT INTO WalletTransactions (PatientID, Type, Amount, BalanceAfter, RelatedAppointmentID, Description) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$patientId, 'BookingPayment', $amount, $newBalance, $appointmentId, $description]);

    return true;
}

/** Refunds funds back into a patient's wallet (e.g. after a cancellation). Caller must be inside a transaction. */
function walletRefund(PDO $pdo, int $patientId, float $amount, ?int $appointmentId, string $description): float
{
    $stmt = $pdo->prepare('SELECT WalletBalance FROM Users WHERE UserID = ? FOR UPDATE');
    $stmt->execute([$patientId]);
    $newBalance = round((float) $stmt->fetchColumn() + $amount, 2);

    $pdo->prepare('UPDATE Users SET WalletBalance = ? WHERE UserID = ?')->execute([$newBalance, $patientId]);
    $pdo->prepare(
        'INSERT INTO WalletTransactions (PatientID, Type, Amount, BalanceAfter, RelatedAppointmentID, Description) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$patientId, 'Refund', $amount, $newBalance, $appointmentId, $description]);

    return $newBalance;
}
