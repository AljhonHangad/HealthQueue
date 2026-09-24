<?php
/**
 * Shared "Pay booking fee" UI, used by the booking modal's pay step and by
 * patient/checkout.php. Payments are simulated: GCash, Maya and card are demo
 * methods that mark the fee paid without charging anything; the wallet
 * really deducts from the patient's (simulated) HealthQueue balance.
 *
 * Behaviour (method selection, card fields, hold countdown) is in main.js.
 */

const PAYMENT_METHODS = [
    'wallet' => 'HealthQueue wallet',
    'gcash'  => 'GCash',
    'maya'   => 'Maya',
    'card'   => 'Credit or debit card',
];

/** Step indicator: Choose clinic ✓ — Pick schedule ✓ — 3 Pay booking fee — 4 Clinic confirms. */
function renderPaySteps(): void
{
    $steps = ['Choose clinic' => 'done', 'Pick schedule' => 'done', 'Pay booking fee' => 'current', 'Clinic confirms' => 'todo'];
    echo '<ol class="pay-steps">';
    $n = 0;
    foreach ($steps as $label => $state) {
        $n++;
        $mark = $state === 'done' ? '✓' : $n;
        echo '<li class="is-' . $state . '"><span>' . $mark . '</span>' . htmlspecialchars($label) . '</li>';
    }
    echo '</ol>';
}

/**
 * Payment method list. $walletBalance/$fee may be null when the values are
 * filled in later by JavaScript (booking modal); $inputName is the radio name.
 */
function renderPaymentMethods(string $inputName, ?float $walletBalance, ?float $fee, string $topUpUrl): void
{
    $walletOk = $walletBalance !== null && $fee !== null && $walletBalance >= $fee;
    $icons = [
        'wallet' => '<rect x="2" y="6" width="20" height="14" rx="2"/><path d="M2 10h20"/><circle cx="17" cy="15" r="1.3"/>',
        'gcash'  => '<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/>',
        'maya'   => '<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/>',
        'card'   => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/>',
    ];
    $default = $walletOk ? 'wallet' : 'card';
    ?>
    <div class="pay-methods" data-pay-methods>
      <p class="pay-methods-title">Payment method</p>
      <?php foreach (PAYMENT_METHODS as $key => $label): ?>
        <?php $disabled = $key === 'wallet' && $walletBalance !== null && !$walletOk; ?>
        <label class="pay-method pay-method-<?= $key ?><?= $key === $default ? ' is-selected' : '' ?><?= $disabled ? ' is-disabled' : '' ?>" data-method="<?= $key ?>">
          <input type="radio" name="<?= htmlspecialchars($inputName) ?>" value="<?= $key ?>"<?= $key === $default ? ' checked' : '' ?><?= $disabled ? ' disabled' : '' ?>>
          <span class="pay-radio"></span>
          <span class="pay-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $icons[$key] ?></svg></span>
          <span class="pay-label">
            <?= htmlspecialchars($label) ?>
            <?php if ($key === 'wallet'): ?>
              <small data-wallet-note class="<?= $walletBalance !== null && !$walletOk ? 'is-short' : '' ?>"><?= $walletBalance !== null ? '₱' . number_format($walletBalance, 2) . ' balance' . ($walletOk ? '' : ' · not enough') : '' ?></small>
            <?php endif; ?>
          </span>
          <?php if ($key === 'wallet'): ?>
            <a href="<?= htmlspecialchars($topUpUrl) ?>" class="pay-topup">Top up</a>
          <?php else: ?>
            <span class="pay-demo">Demo</span>
          <?php endif; ?>
        </label>
      <?php endforeach; ?>

      <div class="pay-card-fields" data-card-fields<?= $default === 'card' ? '' : ' hidden' ?>>
        <input type="text" inputmode="numeric" autocomplete="off" value="4242 4242 4242 4242" aria-label="Card number" data-card-number>
        <div>
          <input type="text" inputmode="numeric" autocomplete="off" value="12 / 28" aria-label="Expiry (MM / YY)">
          <input type="text" inputmode="numeric" autocomplete="off" value="123" aria-label="CVC">
        </div>
        <p><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg> Demo mode: no real charge. Use the test card shown.</p>
      </div>
      <p class="pay-demo-note" data-ewallet-note hidden><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg> Demo mode: no real charge — you won't be redirected to the e-wallet app.</p>
    </div>
    <?php
}
