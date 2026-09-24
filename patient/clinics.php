<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/clinic-hours.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);

// Same rough per-patient estimate the dashboard and Live Queue pages use.
const MINUTES_PER_PATIENT = 15;

$user = currentUser();
$pdo = getDbConnection();
$search = trim((string) ($_GET['q'] ?? ''));
$openOnly = ($_GET['open'] ?? '') === '1';
$specFilter = trim((string) ($_GET['spec'] ?? ''));
$sortOptions = ['wait' => 'Shortest wait', 'fee' => 'Lowest fee', 'name' => 'Name (A–Z)'];
$sort = isset($sortOptions[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'wait';

$clinics = [];
$allSpecialties = [];
$dataError = null;

if ($pdo) {
    try {
        $now = clinicNow($pdo);

        $sql = "SELECT ClinicID, ClinicName, Address, BaseConsultationFee, PhotoUrl, Specialties, OpenDays, OpenTime, CloseTime
                FROM Clinic WHERE archived = 0 AND Status = 'Active'";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll();

        $queueCounts = $pdo->query(
            "SELECT ClinicID, COUNT(*) FROM Queue
             WHERE DATE(CreatedAt) = CURDATE() AND Status IN ('Waiting', 'Calling')
             GROUP BY ClinicID"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($rows as $row) {
            $specialties = array_values(array_filter(array_map('trim', explode(',', (string) $row['Specialties'])), 'strlen'));
            foreach ($specialties as $spec) {
                $allSpecialties[$spec] = ($allSpecialties[$spec] ?? 0) + 1;
            }

            $hours = clinicOpenStatus($row, $now);
            $isOpen = $hours['open'];
            $hoursLabel = $hours['label'];
            $nextOpenLabel = $hours['next'];

            $inQueue = (int) ($queueCounts[$row['ClinicID']] ?? 0);
            $waitMinutes = $inQueue * MINUTES_PER_PATIENT;

            $words = preg_split('/\s+/', trim($row['ClinicName']));
            $initials = strtoupper(mb_substr($words[0] ?? '', 0, 1) . mb_substr($words[1] ?? '', 0, 1));

            $photo = !empty($row['PhotoUrl']) && is_file(__DIR__ . '/../assets/uploads/clinics/' . basename($row['PhotoUrl']))
                ? $row['PhotoUrl'] : null;

            $clinics[] = $row + [
                'SpecialtyList' => $specialties,
                'IsOpen'        => $isOpen,
                'HoursLabel'    => $hoursLabel,
                'NextOpenLabel' => $nextOpenLabel,
                'InQueue'       => $inQueue,
                'WaitMinutes'   => $waitMinutes,
                'WaitTone'      => $waitMinutes <= 30 ? 'short' : ($waitMinutes <= 60 ? 'medium' : 'long'),
                'Initials'      => $initials,
                'Photo'         => $photo,
            ];
        }

        // Filters run in PHP -- the clinic directory is small, and "open now"
        // depends on the computed hours above anyway.
        $clinics = array_values(array_filter($clinics, static function (array $c) use ($search, $openOnly, $specFilter): bool {
            if ($openOnly && !$c['IsOpen']) return false;
            if ($specFilter !== '' && !in_array(mb_strtolower($specFilter), array_map('mb_strtolower', $c['SpecialtyList']), true)) return false;
            if ($search !== '') {
                $haystack = mb_strtolower($c['ClinicName'] . ' ' . $c['Address'] . ' ' . implode(' ', $c['SpecialtyList']));
                if (!str_contains($haystack, mb_strtolower($search))) return false;
            }
            return true;
        }));

        usort($clinics, static function (array $a, array $b) use ($sort): int {
            if ($sort === 'fee') return [(float) $a['BaseConsultationFee'], $a['ClinicName']] <=> [(float) $b['BaseConsultationFee'], $b['ClinicName']];
            if ($sort === 'name') return strcasecmp($a['ClinicName'], $b['ClinicName']);
            // Shortest wait: open clinics first, then by current wait.
            return [!$a['IsOpen'], $a['WaitMinutes'], $a['ClinicName']] <=> [!$b['IsOpen'], $b['WaitMinutes'], $b['ClinicName']];
        });

        arsort($allSpecialties);
        $allSpecialties = array_slice(array_keys($allSpecialties), 0, 6);
    } catch (PDOException $e) {
        error_log('Clinics browse failed: ' . $e->getMessage());
        $dataError = 'Clinics are temporarily unavailable.';
    }
}

// Builds a link to this page with one filter changed, keeping the others.
$filterUrl = static function (array $changes) use ($search, $openOnly, $specFilter, $sort): string {
    $params = array_merge(['q' => $search, 'open' => $openOnly ? '1' : '', 'spec' => $specFilter, 'sort' => $sort], $changes);
    $params = array_filter($params, static fn($v): bool => $v !== '' && $v !== null);
    if (($params['sort'] ?? '') === 'wait') unset($params['sort']);
    return '?' . http_build_query($params);
};

$pageTitle = 'Clinics — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container">
  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <section class="portal-section">
    <form method="get" class="cb-toolbar">
      <?php if ($openOnly): ?><input type="hidden" name="open" value="1"><?php endif; ?>
      <?php if ($specFilter !== ''): ?><input type="hidden" name="spec" value="<?= htmlspecialchars($specFilter) ?>"><?php endif; ?>
      <label class="cb-search">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        <input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search clinics, specialties, or areas" aria-label="Search clinics">
      </label>
      <select name="sort" class="cb-sort" aria-label="Sort clinics" onchange="this.form.submit()">
        <?php foreach ($sortOptions as $key => $label): ?>
          <option value="<?= $key ?>"<?= $sort === $key ? ' selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </form>

    <div class="cb-chips">
      <a href="<?= htmlspecialchars($filterUrl(['open' => $openOnly ? '' : '1'])) ?>" class="cb-chip<?= $openOnly ? ' is-active' : '' ?>">Open now</a>
      <?php foreach ($allSpecialties as $spec): ?>
        <?php $active = mb_strtolower($spec) === mb_strtolower($specFilter); ?>
        <a href="<?= htmlspecialchars($filterUrl(['spec' => $active ? '' : $spec])) ?>" class="cb-chip<?= $active ? ' is-active' : '' ?>"><?= htmlspecialchars($spec) ?></a>
      <?php endforeach; ?>
    </div>

    <p class="cb-count">Showing <?= count($clinics) ?> clinic<?= count($clinics) === 1 ? '' : 's' ?><?= ($search !== '' || $openOnly || $specFilter !== '') ? ' · <a href="?" class="text-link">Clear filters</a>' : '' ?></p>

    <?php if ($clinics): ?>
      <div class="cb-grid">
        <?php foreach ($clinics as $clinic): ?>
          <?php
            $tint = $clinic['IsOpen'] ? 'tint-' . ((int) $clinic['ClinicID'] % 5) : 'tint-closed';
          ?>
          <article class="cb-card" data-clinic-url="<?= HQ_BASE_URL ?>/patient/clinic-detail.php?clinic_id=<?= (int) $clinic['ClinicID'] ?>" tabindex="0" role="link" aria-label="View details for <?= htmlspecialchars($clinic['ClinicName']) ?>">
            <div class="cb-cover <?= $tint ?>">
              <?php if ($clinic['Photo']): ?>
                <img src="<?= HQ_BASE_URL ?>/assets/uploads/clinics/<?= htmlspecialchars($clinic['Photo']) ?>" alt="">
              <?php else: ?>
                <span class="cb-initials"><?= htmlspecialchars($clinic['Initials']) ?></span>
              <?php endif; ?>
              <span class="cb-status <?= $clinic['IsOpen'] ? 'is-open' : 'is-closed' ?>"><?= htmlspecialchars($clinic['HoursLabel']) ?></span>
            </div>
            <div class="cb-body">
              <h3><?= htmlspecialchars($clinic['ClinicName']) ?></h3>
              <p class="cb-address">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                <?= htmlspecialchars($clinic['Address']) ?>
              </p>
              <?php if ($clinic['SpecialtyList']): ?>
                <div class="cb-tags">
                  <?php foreach (array_slice($clinic['SpecialtyList'], 0, 3) as $spec): ?><span><?= htmlspecialchars($spec) ?></span><?php endforeach; ?>
                </div>
              <?php endif; ?>

              <?php if ($clinic['IsOpen']): ?>
                <div class="cb-queue wait-<?= $clinic['WaitTone'] ?>">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                  <?= $clinic['InQueue'] === 0 ? 'No queue · walk right in' : $clinic['InQueue'] . ' in queue · ~' . $clinic['WaitMinutes'] . ' min' ?>
                </div>
              <?php else: ?>
                <div class="cb-queue wait-closed">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                  <?= htmlspecialchars($clinic['NextOpenLabel']) ?>
                </div>
              <?php endif; ?>

              <div class="cb-footer">
                <span class="cb-fee">from <strong>₱<?= number_format((float) $clinic['BaseConsultationFee']) ?></strong></span>
                <?php if ($clinic['IsOpen'] && $clinic['WaitTone'] === 'short'): ?>
                  <button type="button" class="btn btn-primary btn-sm cb-book" data-book-clinic="<?= (int) $clinic['ClinicID'] ?>">Book now</button>
                <?php else: ?>
                  <button type="button" class="btn btn-outline btn-sm cb-book" data-book-clinic="<?= (int) $clinic['ClinicID'] ?>">Book</button>
                <?php endif; ?>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="admin-empty">No clinics match your filters.</p>
    <?php endif; ?>
  </section>
</div></main>

<?php require __DIR__ . '/../includes/booking-modal.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Clicking anywhere on a clinic card opens its details page; the Book
  // button inside a card opens the booking modal instead (booking-modal.php).
  document.querySelectorAll('[data-clinic-url]').forEach(function (card) {
    card.addEventListener('click', function (e) {
      if (e.target.closest('.cb-book')) return;
      window.location.href = card.getAttribute('data-clinic-url');
    });
    card.addEventListener('keydown', function (e) {
      if (e.target !== card) return;
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); window.location.href = card.getAttribute('data-clinic-url'); }
    });
  });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
