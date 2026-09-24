<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

define('HQ_BASE_URL', '..');
requireRole(['Admin']);

const ROLE_NAME = 'Patient';

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';
$search = trim((string) ($_GET['q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } elseif (($_POST['form_type'] ?? '') === 'update_status') {
        $memberId = filter_input(INPUT_POST, 'member_id', FILTER_VALIDATE_INT);
        $status = $_POST['status'] ?? '';
        if (!$memberId || !in_array($status, ['Active', 'Inactive'], true)) {
            $errors[] = 'Invalid account status update.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE Users u JOIN Roles r ON r.RoleID = u.RoleID SET u.Status = ? WHERE u.UserID = ? AND r.RoleName = ? AND u.DeletedAt IS NULL");
                $stmt->execute([$status, $memberId, ROLE_NAME]);
                if ($stmt->rowCount()) {
                    logActivity($pdo, $user['UserID'], null, 'Changed patient status', "UserID {$memberId} set to {$status}.");
                    $flash = 'Account status updated.';
                } else {
                    $errors[] = 'The account could not be found.';
                }
            } catch (PDOException $e) {
                error_log('Patient status update failed: ' . $e->getMessage());
                $errors[] = 'We could not update this account right now.';
            }
        }
    }
}

$members = [];
if ($pdo) {
    try {
        $sql = "SELECT u.UserID, u.FirstName, u.LastName, u.Email, u.ContactNumber, u.Status, u.CreatedAt,
                       (SELECT COUNT(*) FROM Appointments a WHERE a.PatientID = u.UserID) AS AppointmentCount
                FROM Users u JOIN Roles r ON r.RoleID = u.RoleID
                WHERE r.RoleName = ? AND u.DeletedAt IS NULL";
        $params = [ROLE_NAME];
        if ($search !== '') {
            $sql .= " AND (CONCAT(u.FirstName, ' ', u.LastName) LIKE ? OR u.Email LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        $sql .= ' ORDER BY u.CreatedAt DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $members = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Patients list failed: ' . $e->getMessage());
        $errors[] = 'Patient information is temporarily unavailable.';
    }
}

$pageTitle = 'Manage Patients — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="admin-shell"><div class="container">
  <section class="admin-hero"><div><span class="eyebrow">Accounts</span><h1>Patients</h1><p>Patients register themselves — this is a read-only directory with account status control.</p></div></section>

  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

  <section class="admin-section">
    <div class="portal-heading"><div><span class="section-kicker">Directory</span><h2>All patients</h2></div></div>
    <form method="get" class="admin-search">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name or email&hellip;">
      <button type="submit" class="btn btn-outline btn-sm">Search</button>
      <?php if ($search !== ''): ?><a href="<?= HQ_BASE_URL ?>/admin/patients.php" class="text-link">Clear</a><?php endif; ?>
    </form>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead><tr><th>Name</th><th>Email</th><th>Contact</th><th>Appointments</th><th>Joined</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($members as $member): ?>
          <tr>
            <td><strong><?= htmlspecialchars($member['FirstName'] . ' ' . $member['LastName']) ?></strong></td>
            <td><?= htmlspecialchars($member['Email']) ?></td>
            <td><?= htmlspecialchars($member['ContactNumber'] ?? '') ?></td>
            <td><?= (int) $member['AppointmentCount'] ?></td>
            <td><?= htmlspecialchars(date('M j, Y', strtotime($member['CreatedAt']))) ?></td>
            <td><span class="status-badge status-<?= strtolower(htmlspecialchars($member['Status'])) ?>"><?= htmlspecialchars($member['Status']) ?></span></td>
            <td>
              <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="update_status"><input type="hidden" name="member_id" value="<?= (int) $member['UserID'] ?>">
                <?php if ($member['Status'] === 'Active'): ?><button class="btn btn-outline btn-sm" name="status" value="Inactive" onclick="return confirm('Deactivate this account? The patient will no longer be able to sign in.');">Deactivate</button>
                <?php else: ?><button class="btn btn-primary btn-sm" name="status" value="Active">Activate</button><?php endif; ?>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$members): ?><tr><td colspan="7" class="admin-empty"><?= $search !== '' ? 'No patients match "' . htmlspecialchars($search) . '".' : 'No patient accounts yet.' ?></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div></main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
