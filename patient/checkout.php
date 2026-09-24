<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/wallet.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);

$user = currentUser();
$pdo = getDbConnection();
$errors = [];

$appointmentId = filter_input(INPUT_GET, 'appointment_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);

if (!$appointmentId || !$pdo) {
    header('Location: ' . HQ_BASE_URL . '/patient/dashboard.php');
    exit;
}

// Scoped to this patient's own UserID -- one patient can never pay for or
// even see another patient's booking through this page.
$stmt = $pdo->prepare(
    "SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Concern, a.BookingFeePaid,
            c.ClinicID, c.ClinicName, c.BaseConsultationFee,
            phy.FirstName AS PhyFirstName, phy.LastName AS PhyLastName
     FROM Appointments a
     JOIN Clinic c ON c.ClinicID = a.ClinicID
     LEFT JOIN Users phy ON phy.UserID = a.PhysicianID
     WHERE a.AppointmentID = ? AND a.PatientID = ?"
);
$stmt->execute([$appointmentId, $user['UserID']]);
$appointment = $stmt->fetch();

if (!$appointment) {
    header('Location: ' . HQ_BASE_URL . '/patient/dashboard.php');
    exit;
}

if ($appointment['BookingFeePaid']) {
    header('Location: ' . HQ_BASE_URL . '/patient/my-appointments.php?paid=1');
    exit;
}

$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

// One handler for both modes of payment -- which one applies is decided by
// the "payment_mode" field (wallet or card), not by a different form_type
// per button, so the UI only ever needs a single Pay Now action.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'submit_payment') {
    $paymentMode = ($_POST['payment_mode'] ?? '') === 'wallet' ? 'wallet' : 'card';

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        try {
            $pdo->beginTransaction();
            $fee = round((float) $appointment['BaseConsultationFee'], 2);

            if ($paymentMode === 'wallet') {
                $paid = walletDeduct($pdo, $user['UserID'], $fee, $appointmentId, 'Booking fee for ' . $appointment['ClinicName']);
                if (!$paid) {
                    $pdo->rollBack();
                    $errors[] = 'Your wallet balance is not enough to cover this fee.';
                }
            } else {
                $paid = true;
            }

            if ($paid ?? false) {
                $pdo->prepare("UPDATE Appointments SET BookingFeePaid = 1, BookingFeePaidAt = NOW() WHERE AppointmentID = ? AND PatientID = ?")
                    ->execute([$appointmentId, $user['UserID']]);
                $pdo->commit();

                notifyClinic(
                    $pdo,
                    (int) $appointment['ClinicID'],
                    'New appointment request from ' . $user['FirstName'] . ' ' . $user['LastName'] . ' for ' . $appointment['AppointmentDate'] . '.',
                    $appointmentId
                );

                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => true]);
                    exit;
                }

                header('Location: ' . HQ_BASE_URL . '/patient/my-appointments.php?paid=1');
                exit;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Booking payment failed: ' . $e->getMessage());
            $errors[] = 'We could not process your payment right now. Please try again.';
        }
    }

    if ($errors && $isAjax) {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode(['ok' => false, 'errors' => $errors]);
        exit;
    }
}

$walletBalance = 0.0;
try {
    $walletStmt = $pdo->prepare('SELECT WalletBalance FROM Users WHERE UserID = ?');
    $walletStmt->execute([$user['UserID']]);
    $walletBalance = (float) $walletStmt->fetchColumn();
} catch (PDOException $e) {
    error_log('Wallet balance load failed: ' . $e->getMessage());
}

$pageTitle = 'Checkout — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container">
  <a href="<?= HQ_BASE_URL ?>/patient/dashboard.php" class="back-link">&larr; Back to dashboard</a>

  <section class="portal-hero" style="margin-top:18px;">
    <div>
      <span class="eyebrow">Checkout</span>
      <h1>Pay Booking Fee</h1>
      <p>Paying the booking fee sends your request to <?= htmlspecialchars($appointment['ClinicName']) ?> for confirmation.</p>
    </div>
  </section>

  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

  <section class="portal-section">
    <div class="portal-heading"><div><span class="section-kicker">Summary</span><h2>Appointment request</h2></div></div>
    <div class="compact-list" style="margin-bottom:18px;">
      <article>
        <div>
          <strong><?= htmlspecialchars($appointment['ClinicName']) ?></strong>
          <span><?= $appointment['PhyFirstName'] ? 'Dr. ' . htmlspecialchars($appointment['PhyFirstName'] . ' ' . $appointment['PhyLastName']) : 'No physician preference' ?> &middot; <?= htmlspecialchars(date('M j, Y', strtotime($appointment['AppointmentDate']))) ?> at <?= htmlspecialchars(date('g:i A', strtotime($appointment['AppointmentTime']))) ?></span>
        </div>
        <strong class="money">PHP <?= number_format((float) $appointment['BaseConsultationFee'], 2) ?></strong>
      </article>
    </div>

    <div class="dev-note" style="margin-bottom:18px;">
      <strong>Simulated payment:</strong> HealthQueue is not yet connected to a real payment processor or wallet provider. Either option below instantly marks this booking fee as paid for demonstration purposes — no real transaction occurs and no money changes hands.
    </div>

    <?php $canPayWithWallet = $walletBalance >= (float) $appointment['BaseConsultationFee']; ?>
    <form method="post" style="max-width:420px;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="form_type" value="submit_payment">
      <input type="hidden" name="appointment_id" value="<?= (int) $appointmentId ?>">

      <label style="display:block;margin-bottom:8px;font-size:13.5px;font-weight:600;color:var(--slate-700);">Mode of Payment</label>
      <div class="payment-mode-options">
        <label class="payment-mode-option<?= $canPayWithWallet ? '' : ' is-disabled' ?>">
          <input type="radio" name="payment_mode" value="wallet" <?= $canPayWithWallet ? 'checked' : 'disabled' ?>>
          <span>Wallet <span style="color:var(--slate-400);">— Balance PHP <?= number_format($walletBalance, 2) ?></span></span>
        </label>
        <label class="payment-mode-option">
          <input type="radio" name="payment_mode" value="card" <?= $canPayWithWallet ? '' : 'checked' ?>>
          <span>Simulated Card</span>
        </label>
      </div>
      <?php if (!$canPayWithWallet): ?>
        <p class="admin-empty" style="text-align:left;margin:10px 0 0;">Wallet balance not enough to cover this fee. <a href="<?= HQ_BASE_URL ?>/patient/wallet.php" class="text-link">Top up your wallet <span>&rarr;</span></a></p>
      <?php endif; ?>

      <button type="submit" class="btn btn-primary btn-block" style="margin-top:16px;">Pay Now — PHP <?= number_format((float) $appointment['BaseConsultationFee'], 2) ?></button>
    </form>
  </section>
</div></main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
