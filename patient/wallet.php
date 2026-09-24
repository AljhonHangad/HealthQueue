<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/wallet.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'top_up') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } else {
        $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT)
            ?: filter_input(INPUT_POST, 'custom_amount', FILTER_VALIDATE_FLOAT);
        if (!$amount || $amount <= 0) {
            $errors[] = 'Please enter a valid amount.';
        } elseif ($amount > 50000) {
            $errors[] = 'Please top up in amounts of PHP 50,000 or less at a time.';
        } else {
            try {
                $pdo->beginTransaction();
                walletTopUp($pdo, $user['UserID'], round($amount, 2), 'Simulated top-up');
                $pdo->commit();
                $flash = 'PHP ' . number_format($amount, 2) . ' added to your wallet.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Wallet top-up failed: ' . $e->getMessage());
                $errors[] = 'We could not process this top-up. Please try again.';
            }
        }
    }
}

$balance = 0.0;
$transactions = [];
$dataError = null;

$typeFilters = ['all' => null, 'topup' => 'TopUp', 'payment' => 'BookingPayment', 'refund' => 'Refund'];
$activeFilter = $_GET['type'] ?? 'all';
if (!isset($typeFilters[$activeFilter])) {
    $activeFilter = 'all';
}
$perPage = 15;
$page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1);
$totalCount = 0;

// The status an appointment is currently in decides which My Appointments
// tab it lives on, so a transaction's link can jump straight to it.
$statusToTab = ['Pending' => 'pending', 'Confirmed' => 'approved', 'Cancelled' => 'rejected', 'Completed' => 'done'];

if ($pdo) {
    try {
        $balStmt = $pdo->prepare('SELECT WalletBalance FROM Users WHERE UserID = ?');
        $balStmt->execute([$user['UserID']]);
        $balance = (float) $balStmt->fetchColumn();

        $whereType = $typeFilters[$activeFilter] ? ' AND Type = ?' : '';
        $countParams = [$user['UserID']];
        if ($typeFilters[$activeFilter]) $countParams[] = $typeFilters[$activeFilter];

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM WalletTransactions WHERE PatientID = ?{$whereType}");
        $countStmt->execute($countParams);
        $totalCount = (int) $countStmt->fetchColumn();

        $params = $countParams;
        $params[] = $perPage;
        $params[] = ($page - 1) * $perPage;
        $txStmt = $pdo->prepare(
            "SELECT t.TransactionID, t.Type, t.Amount, t.BalanceAfter, t.Description, t.CreatedAt,
                    t.RelatedAppointmentID, a.Status AS ApptStatus, c.ClinicName
             FROM WalletTransactions t
             LEFT JOIN Appointments a ON a.AppointmentID = t.RelatedAppointmentID
             LEFT JOIN Clinic c ON c.ClinicID = a.ClinicID
             WHERE t.PatientID = ?{$whereType}
             ORDER BY t.CreatedAt DESC
             LIMIT ? OFFSET ?"
        );
        $txStmt->execute($params);
        $transactions = $txStmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Wallet load failed: ' . $e->getMessage());
        $dataError = 'Your wallet is temporarily unavailable.';
    }
}

$totalPages = max(1, (int) ceil($totalCount / $perPage));
$typeLabels = ['TopUp' => 'Top-up', 'BookingPayment' => 'Booking payment', 'Refund' => 'Refund'];

$pageTitle = 'Wallet — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container">
  <section class="portal-hero wallet-hero">
    <div><span class="eyebrow">Wallet</span><h1>PHP <?= number_format($balance, 2) ?></h1><p>Your simulated HealthQueue wallet balance. Use it to pay booking fees instantly at checkout.</p></div>
    <button type="button" class="wallet-add-btn" data-modal-open="addFundsModal" aria-label="Add funds" title="Add funds">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
    </button>
  </section>

  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <section class="portal-section">
    <div class="portal-heading"><div><span class="section-kicker">History</span><h2>Transaction history</h2></div></div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
      <?php foreach (['all' => 'All', 'topup' => 'Top-ups', 'payment' => 'Payments', 'refund' => 'Refunds'] as $key => $label): ?>
        <a href="?type=<?= $key ?>" class="btn btn-sm <?= $activeFilter === $key ? 'btn-primary' : 'btn-outline' ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </div>

    <?php if ($transactions): ?>
      <div class="compact-list">
        <?php foreach ($transactions as $tx): ?>
          <?php
            $hasAppointment = $tx['RelatedAppointmentID'] && $tx['ApptStatus'];
            $tab = $hasAppointment ? ($statusToTab[$tx['ApptStatus']] ?? 'pending') : null;
            $link = $hasAppointment ? HQ_BASE_URL . '/patient/my-appointments.php?tab=' . $tab . '#appt-' . (int) $tx['RelatedAppointmentID'] : null;
          ?>
          <article>
            <div>
              <strong><?= htmlspecialchars($typeLabels[$tx['Type']] ?? $tx['Type']) ?></strong>
              <span>
                <?= htmlspecialchars($tx['Description'] ?: '') ?> &middot; <?= htmlspecialchars(date('M j, Y g:i A', strtotime($tx['CreatedAt']))) ?>
                <?php if ($link): ?> &middot; <a href="<?= $link ?>" class="text-link"><?= htmlspecialchars($tx['ClinicName'] ?: 'View appointment') ?> <span>&rarr;</span></a><?php endif; ?>
              </span>
            </div>
            <strong class="money" style="<?= $tx['Type'] === 'BookingPayment' ? 'color:#991b1b !important;' : '' ?>"><?= $tx['Type'] === 'BookingPayment' ? '-' : '+' ?>PHP <?= number_format((float) $tx['Amount'], 2) ?></strong>
          </article>
        <?php endforeach; ?>
      </div>

      <?php if ($totalPages > 1): ?>
        <div style="display:flex;justify-content:center;align-items:center;gap:14px;margin-top:18px;">
          <a href="?type=<?= $activeFilter ?>&page=<?= max(1, $page - 1) ?>" class="btn btn-outline btn-sm<?= $page <= 1 ? ' is-disabled' : '' ?>" <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1" style="pointer-events:none;opacity:.5;"' : '' ?>>&larr; Previous</a>
          <span style="color:var(--slate-500);font-size:13px;">Page <?= $page ?> of <?= $totalPages ?></span>
          <a href="?type=<?= $activeFilter ?>&page=<?= min($totalPages, $page + 1) ?>" class="btn btn-outline btn-sm" <?= $page >= $totalPages ? 'aria-disabled="true" tabindex="-1" style="pointer-events:none;opacity:.5;"' : '' ?>>Next &rarr;</a>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <p class="admin-empty">No wallet activity yet.</p>
    <?php endif; ?>
  </section>
</div></main>

<div class="modal-overlay" id="addFundsModal">
  <div class="modal-box">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <h2>Add Funds</h2>
    <p class="modal-subtitle">Pick an amount or enter your own (up to PHP 50,000).</p>
    <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <div class="dev-note" style="margin-bottom:16px;">
      <strong>Simulated wallet:</strong> HealthQueue is not connected to a real payment processor. Topping up instantly credits your balance for demonstration purposes — no real transaction occurs and no money changes hands.
    </div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="form_type" value="top_up">
      <div class="form-stack form-cols-2">
        <?php foreach ([200, 500, 1000, 2000] as $preset): ?>
          <button type="submit" name="amount" value="<?= $preset ?>" class="btn btn-outline btn-sm">+ PHP <?= number_format($preset) ?></button>
        <?php endforeach; ?>
      </div>
      <div class="form-stack" style="margin-top:10px;">
        <label>Custom amount (PHP)<input type="number" name="custom_amount" step="0.01" min="1" max="50000" placeholder="e.g. 750"></label>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Add Funds (Simulated)</button>
    </form>
  </div>
</div>

<?php if ($errors): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  window.hqOpenModal(document.getElementById('addFundsModal'));
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
