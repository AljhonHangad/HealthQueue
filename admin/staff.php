<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

define('HQ_BASE_URL', '..');
requireRole(['Admin']);

const ROLE_NAME = 'Staff';

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: null;
$search = trim((string) ($_GET['q'] ?? ''));
$values = ['first_name' => '', 'last_name' => '', 'email' => '', 'contact_number' => '', 'clinic_id' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } elseif (($_POST['form_type'] ?? '') === 'save_member') {
        $memberId = filter_input(INPUT_POST, 'member_id', FILTER_VALIDATE_INT) ?: null;
        foreach ($values as $field => $value) $values[$field] = trim((string) ($_POST[$field] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($values['first_name'] === '' || $values['last_name'] === '') $errors[] = 'First and last name are required.';
        if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
        if ($values['contact_number'] === '') $errors[] = 'Contact number is required.';
        if (!filter_var($values['clinic_id'], FILTER_VALIDATE_INT)) $errors[] = 'Please select a clinic.';
        if (!$memberId && strlen($password) < 8) $errors[] = 'Password must be at least 8 characters long.';
        if ($memberId && $password !== '' && strlen($password) < 8) $errors[] = 'New password must be at least 8 characters long.';

        if (!$errors) {
            try {
                $clinicStmt = $pdo->prepare("SELECT ClinicID FROM Clinic WHERE ClinicID = ? AND archived = 0 AND Status = 'Active'");
                $clinicStmt->execute([(int) $values['clinic_id']]);
                $emailStmt = $pdo->prepare('SELECT UserID FROM Users WHERE Email = ? AND UserID <> ?');
                $emailStmt->execute([$values['email'], $memberId ?: 0]);

                if (!$clinicStmt->fetch()) {
                    $errors[] = 'Please select an active clinic.';
                } elseif ($emailStmt->fetch()) {
                    $errors[] = 'An account with this email already exists.';
                } elseif ($memberId) {
                    $sql = 'UPDATE Users SET FirstName = ?, LastName = ?, Email = ?, ContactNumber = ?, ClinicID = ?';
                    $params = [$values['first_name'], $values['last_name'], $values['email'], $values['contact_number'], (int) $values['clinic_id']];
                    if ($password !== '') { $sql .= ', PasswordHash = ?'; $params[] = password_hash($password, PASSWORD_DEFAULT); }
                    $sql .= ' WHERE UserID = ? AND RoleID = (SELECT RoleID FROM Roles WHERE RoleName = ?)';
                    $params[] = $memberId;
                    $params[] = ROLE_NAME;
                    $pdo->prepare($sql)->execute($params);
                    logActivity($pdo, $user['UserID'], (int) $values['clinic_id'], 'Updated staff account', $values['first_name'] . ' ' . $values['last_name']);
                    $flash = 'Staff account updated.';
                } else {
                    $roleStmt = $pdo->prepare('SELECT RoleID FROM Roles WHERE RoleName = ?');
                    $roleStmt->execute([ROLE_NAME]);
                    $role = $roleStmt->fetch();
                    $stmt = $pdo->prepare('INSERT INTO Users (ClinicID, RoleID, FirstName, LastName, Email, ContactNumber, PasswordHash) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([(int) $values['clinic_id'], $role['RoleID'], $values['first_name'], $values['last_name'], $values['email'], $values['contact_number'], password_hash($password, PASSWORD_DEFAULT)]);
                    assignUserIdNumber($pdo, (int) $pdo->lastInsertId());
                    logActivity($pdo, $user['UserID'], (int) $values['clinic_id'], 'Created staff account', $values['first_name'] . ' ' . $values['last_name']);
                    $flash = 'Staff account created.';
                }
                if (!$errors) {
                    header('Location: ' . HQ_BASE_URL . '/admin/staff.php');
                    exit;
                }
            } catch (PDOException $e) {
                error_log('Staff save failed: ' . $e->getMessage());
                $errors[] = 'We could not save this account.';
            }
        }
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
                    logActivity($pdo, $user['UserID'], null, 'Changed staff status', "UserID {$memberId} set to {$status}.");
                    $flash = 'Account status updated.';
                } else {
                    $errors[] = 'The account could not be found.';
                }
            } catch (PDOException $e) {
                error_log('Staff status update failed: ' . $e->getMessage());
                $errors[] = 'We could not update this account.';
            }
        }
    }
}

$activeClinics = [];
$members = [];
$editingMember = null;
if ($pdo) {
    try {
        $activeClinics = $pdo->query("SELECT ClinicID, ClinicName FROM Clinic WHERE archived = 0 AND Status = 'Active' ORDER BY ClinicName")->fetchAll();
        $sql = "SELECT u.UserID, u.FirstName, u.LastName, u.Email, u.ContactNumber, u.Status, c.ClinicName
                FROM Users u JOIN Roles r ON r.RoleID = u.RoleID LEFT JOIN Clinic c ON c.ClinicID = u.ClinicID
                WHERE r.RoleName = ? AND u.DeletedAt IS NULL";
        $params = [ROLE_NAME];
        if ($search !== '') {
            $sql .= " AND (CONCAT(u.FirstName, ' ', u.LastName) LIKE ? OR u.Email LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        $sql .= ' ORDER BY c.ClinicName, u.LastName';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $members = $stmt->fetchAll();

        if ($editId) {
            $editStmt = $pdo->prepare(
                "SELECT u.UserID, u.FirstName, u.LastName, u.Email, u.ContactNumber, u.ClinicID
                 FROM Users u JOIN Roles r ON r.RoleID = u.RoleID WHERE u.UserID = ? AND r.RoleName = ?"
            );
            $editStmt->execute([$editId, ROLE_NAME]);
            $editingMember = $editStmt->fetch();
            if ($editingMember) {
                $values = [
                    'first_name' => $editingMember['FirstName'], 'last_name' => $editingMember['LastName'],
                    'email' => $editingMember['Email'], 'contact_number' => $editingMember['ContactNumber'] ?? '',
                    'clinic_id' => (string) $editingMember['ClinicID'],
                ];
            }
        }
    } catch (PDOException $e) {
        error_log('Staff list failed: ' . $e->getMessage());
        $errors[] = 'Staff information is temporarily unavailable.';
    }
}

$pageTitle = 'Manage Staff — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="admin-shell"><div class="container">
  <section class="admin-hero"><div><span class="eyebrow">Team management</span><h1>Front-desk staff</h1><p>Create staff accounts, assign them to a clinic, and manage their status.</p></div></section>

  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

  <section class="admin-section" id="member-form">
    <div class="portal-heading"><div><span class="section-kicker"><?= $editingMember ? 'Edit staff account' : 'Add staff' ?></span><h2><?= $editingMember ? htmlspecialchars($editingMember['FirstName'] . ' ' . $editingMember['LastName']) : 'New staff account' ?></h2></div></div>
    <form class="registration-form" method="post" style="max-width:640px;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="form_type" value="save_member">
      <?php if ($editingMember): ?><input type="hidden" name="member_id" value="<?= (int) $editingMember['UserID'] ?>"><?php endif; ?>
      <div class="form-stack form-cols-2">
        <label>First name<input name="first_name" value="<?= htmlspecialchars($values['first_name']) ?>" required></label>
        <label>Last name<input name="last_name" value="<?= htmlspecialchars($values['last_name']) ?>" required></label>
      </div>
      <div class="form-stack">
        <label>Clinic<select name="clinic_id" required><option value="">Select an active clinic</option><?php foreach ($activeClinics as $clinic): ?><option value="<?= (int) $clinic['ClinicID'] ?>" <?= $values['clinic_id'] === (string) $clinic['ClinicID'] ? 'selected' : '' ?>><?= htmlspecialchars($clinic['ClinicName']) ?></option><?php endforeach; ?></select></label>
        <label>Email address<input type="email" name="email" value="<?= htmlspecialchars($values['email']) ?>" required></label>
        <label>Contact number<input type="tel" name="contact_number" value="<?= htmlspecialchars($values['contact_number']) ?>" required></label>
        <label><?= $editingMember ? 'New password ' : 'Temporary password' ?><span class="optional"><?= $editingMember ? '(leave blank to keep current)' : '' ?></span><input type="password" name="password" minlength="8" <?= $editingMember ? '' : 'required' ?>></label>
      </div>
      <button type="submit" class="btn btn-primary btn-block"><?= $editingMember ? 'Save changes' : 'Create account' ?></button>
      <?php if ($editingMember): ?><a href="<?= HQ_BASE_URL ?>/admin/staff.php" class="text-link" style="display:block;text-align:center;margin-top:12px;">Cancel edit</a><?php endif; ?>
    </form>
  </section>

  <section class="admin-section">
    <div class="portal-heading"><div><span class="section-kicker">Directory</span><h2>All staff</h2></div></div>
    <form method="get" class="admin-search">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name or email&hellip;">
      <button type="submit" class="btn btn-outline btn-sm">Search</button>
      <?php if ($search !== ''): ?><a href="<?= HQ_BASE_URL ?>/admin/staff.php" class="text-link">Clear</a><?php endif; ?>
    </form>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead><tr><th>Name</th><th>Clinic</th><th>Email</th><th>Contact</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($members as $member): ?>
          <tr>
            <td><strong><?= htmlspecialchars($member['FirstName'] . ' ' . $member['LastName']) ?></strong></td>
            <td><?= htmlspecialchars($member['ClinicName'] ?? 'Not assigned') ?></td>
            <td><?= htmlspecialchars($member['Email']) ?></td>
            <td><?= htmlspecialchars($member['ContactNumber'] ?? '') ?></td>
            <td><span class="status-badge status-<?= strtolower(htmlspecialchars($member['Status'])) ?>"><?= htmlspecialchars($member['Status']) ?></span></td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <a href="<?= HQ_BASE_URL ?>/admin/staff.php?edit=<?= (int) $member['UserID'] ?>#member-form" class="btn btn-outline btn-sm">Edit</a>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="update_status"><input type="hidden" name="member_id" value="<?= (int) $member['UserID'] ?>">
                  <?php if ($member['Status'] === 'Active'): ?><button class="btn btn-outline btn-sm" name="status" value="Inactive" onclick="return confirm('Deactivate this account? The staff member will no longer be able to sign in.');">Deactivate</button>
                  <?php else: ?><button class="btn btn-primary btn-sm" name="status" value="Active">Activate</button><?php endif; ?>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$members): ?><tr><td colspan="6" class="admin-empty"><?= $search !== '' ? 'No staff match "' . htmlspecialchars($search) . '".' : 'No staff accounts yet.' ?></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div></main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
