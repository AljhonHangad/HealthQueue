<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);

// A prescription counts as "Active" for this many days after the visit --
// prescriptions are free text, so there's no structured end date to use.
const PRESCRIPTION_ACTIVE_DAYS = 30;

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';

$tabs = ['consultations' => 'Consultations', 'prescriptions' => 'Prescriptions', 'uploads' => 'My uploads'];
$activeTab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'consultations';
$recordCategories = ['Lab result', 'Imaging', 'Prescription', 'Medical certificate', 'Other'];
$storageDir = __DIR__ . '/../storage/medical-records';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = $_POST['form_type'] ?? '';
    if (in_array($formType, ['upload_record', 'delete_record'], true)) $activeTab = 'uploads';

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } elseif ($formType === 'upload_record') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $category = (string) ($_POST['category'] ?? 'Other');
        $description = trim((string) ($_POST['description'] ?? ''));
        $file = $_FILES['record_file'] ?? null;
        $allowedTypes = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

        if (!in_array($category, $recordCategories, true)) $category = 'Other';

        if ($title === '') {
            $errors[] = 'Please give this record a title.';
        } elseif (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] <= 0) {
            $errors[] = 'Please choose a file to upload.';
        } elseif ($file['size'] > 10 * 1024 * 1024) {
            $errors[] = 'File is too large (10 MB limit).';
        } else {
            $mime = mime_content_type($file['tmp_name']);
            if (!isset($allowedTypes[$mime])) {
                $errors[] = 'Please upload a PDF, JPG, or PNG file.';
            } else {
                try {
                    if (!is_dir($storageDir)) {
                        mkdir($storageDir, 0750, true);
                    }
                    $filename = 'record_' . $user['UserID'] . '_' . bin2hex(random_bytes(8)) . '.' . $allowedTypes[$mime];

                    if (!move_uploaded_file($file['tmp_name'], $storageDir . '/' . $filename)) {
                        $errors[] = 'We could not save this file. Please try again.';
                    } else {
                        $pdo->prepare('INSERT INTO MedicalRecords (PatientID, Title, Category, Description, FilePath, FileType) VALUES (?, ?, ?, ?, ?, ?)')
                            ->execute([$user['UserID'], $title, $category, $description ?: null, $filename, $allowedTypes[$mime]]);
                        $flash = 'Medical record uploaded.';
                    }
                } catch (PDOException $e) {
                    error_log('Medical record upload failed: ' . $e->getMessage());
                    $errors[] = 'We could not save this record. Please try again.';
                }
            }
        }
    } elseif ($formType === 'delete_record') {
        $recordId = filter_input(INPUT_POST, 'record_id', FILTER_VALIDATE_INT);
        try {
            // Scoped to this patient's own records.
            $stmt = $pdo->prepare('SELECT FilePath FROM MedicalRecords WHERE RecordID = ? AND PatientID = ?');
            $stmt->execute([$recordId, $user['UserID']]);
            $path = $stmt->fetchColumn();
            if (!$path) {
                $errors[] = 'That record could not be found.';
            } else {
                $pdo->prepare('DELETE FROM MedicalRecords WHERE RecordID = ? AND PatientID = ?')->execute([$recordId, $user['UserID']]);
                $fullPath = $storageDir . '/' . basename($path);
                if (is_file($fullPath)) unlink($fullPath);
                $flash = 'Record deleted.';
            }
        } catch (PDOException $e) {
            error_log('Medical record delete failed: ' . $e->getMessage());
            $errors[] = 'We could not delete this record.';
        }
    }
}

$consultations = [];
$prescriptions = [];
$records = [];
$dataError = null;

if ($pdo) {
    try {
        // Completed visits with the physician's latest finalized notes.
        $stmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, a.Concern, c.ClinicName, u.FirstName, u.LastName,
                    v.ClinicalNotes, v.PrescriptionText, v.UpdatedAt,
                    DATEDIFF(CURDATE(), a.AppointmentDate) AS DaysAgo
             FROM Appointments a
             JOIN Clinic c ON c.ClinicID = a.ClinicID
             LEFT JOIN Users u ON u.UserID = a.PhysicianID
             LEFT JOIN Consultations cons ON cons.AppointmentID = a.AppointmentID
             LEFT JOIN ConsultationVersions v ON v.VersionID = (
                 SELECT v2.VersionID FROM ConsultationVersions v2
                 WHERE v2.ConsultationID = cons.ConsultationID AND v2.Status <> 'Draft'
                 ORDER BY v2.RevisionNumber DESC LIMIT 1
             )
             WHERE a.PatientID = ? AND a.Status = 'Completed'
             ORDER BY a.AppointmentDate DESC, a.AppointmentTime DESC"
        );
        $stmt->execute([$user['UserID']]);
        $consultations = $stmt->fetchAll();

        foreach ($consultations as $consult) {
            if (trim((string) $consult['PrescriptionText']) !== '') {
                $prescriptions[] = $consult + ['IsActive' => (int) $consult['DaysAgo'] <= PRESCRIPTION_ACTIVE_DAYS];
            }
        }

        $recStmt = $pdo->prepare('SELECT RecordID, Title, Category, Description, FilePath, FileType, UploadedAt FROM MedicalRecords WHERE PatientID = ? ORDER BY UploadedAt DESC');
        $recStmt->execute([$user['UserID']]);
        $records = $recStmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Medical records load failed: ' . $e->getMessage());
        $dataError = 'Some medical record information is temporarily unavailable.';
    }
}

$lastVisit = $consultations[0]['AppointmentDate'] ?? null;
$activePrescriptionCount = count(array_filter($prescriptions, static fn(array $rx): bool => $rx['IsActive']));
$tabCounts = ['consultations' => count($consultations), 'prescriptions' => count($prescriptions), 'uploads' => count($records)];

$formatBytes = static function (int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    return max(1, (int) round($bytes / 1024)) . ' KB';
};
$preview = static function (?string $text, int $length = 140): string {
    $text = trim(preg_replace('/\s+/', ' ', (string) $text));
    return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '…' : $text;
};
$doctorLabel = static fn(array $row): string => $row['LastName'] ? 'Dr. ' . $row['LastName'] : 'Physician';

$pageTitle = 'Medical Records — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container mr-page">
  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <div class="mr-stats">
    <div><span>Last visit</span><strong><?= $lastVisit ? htmlspecialchars(date('M j, Y', strtotime($lastVisit))) : '—' ?></strong></div>
    <div><span>Active prescriptions</span><strong><?= $activePrescriptionCount ?></strong></div>
    <div><span>Uploaded files</span><strong><?= count($records) ?></strong></div>
  </div>

  <div class="mr-toolbar">
    <nav class="ma-tabs" aria-label="Medical record sections">
      <?php foreach ($tabs as $key => $label): ?>
        <a href="?tab=<?= $key ?>" class="ma-tab<?= $activeTab === $key ? ' is-active' : '' ?>"<?= $activeTab === $key ? ' aria-current="page"' : '' ?>><?= $label ?> <span><?= $tabCounts[$key] ?></span></a>
      <?php endforeach; ?>
    </nav>
    <a href="?tab=uploads#upload" class="btn btn-outline btn-sm mr-upload-btn" data-open-upload>
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
      Upload record
    </a>
  </div>

  <?php if ($activeTab === 'consultations'): ?>
    <?php if ($consultations): ?>
      <div class="mr-list">
        <?php foreach ($consultations as $consult): ?>
          <?php $id = (int) $consult['AppointmentID']; ?>
          <article class="mr-item">
            <span class="mr-icon mr-icon-blue"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4.8 2.3A.3.3 0 1 0 5 2H4a2 2 0 0 0-2 2v5a6 6 0 0 0 6 6 6 6 0 0 0 6-6V4a2 2 0 0 0-2-2h-1a.2.2 0 1 0 .3.3"/><path d="M8 15v1a6 6 0 0 0 6 6 6 6 0 0 0 6-6v-4"/><circle cx="20" cy="10" r="2"/></svg></span>
            <div class="mr-body">
              <div class="mr-title-row">
                <strong><?= htmlspecialchars($preview($consult['Concern'], 50) ?: 'General consultation') ?></strong>
                <span class="mr-date"><?= htmlspecialchars(date('M j, Y', strtotime($consult['AppointmentDate']))) ?></span>
              </div>
              <p class="mr-sub"><?= htmlspecialchars($consult['ClinicName'] . ' · ' . $doctorLabel($consult)) ?></p>
              <?php if ($consult['ClinicalNotes']): ?><p class="mr-text"><?= htmlspecialchars($preview($consult['ClinicalNotes'])) ?></p><?php endif; ?>
              <div class="ma-links">
                <button type="button" data-view-record="<?= $id ?>">View full notes</button>
                <?php if (trim((string) $consult['PrescriptionText']) !== ''): ?><a href="?tab=prescriptions#rx-<?= $id ?>">Prescription</a><?php endif; ?>
                <a href="<?= HQ_BASE_URL ?>/patient/consultation.php?appointment_id=<?= $id ?>&amp;print=1" target="_blank" rel="noopener">Download PDF</a>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="ma-empty"><h3>No consultations yet</h3><p>Your visit notes will appear here after each completed consultation.</p><a href="<?= HQ_BASE_URL ?>/patient/clinics.php" class="btn btn-outline btn-sm">Find a clinic</a></div>
    <?php endif; ?>

  <?php elseif ($activeTab === 'prescriptions'): ?>
    <?php if ($prescriptions): ?>
      <div class="mr-list">
        <?php foreach ($prescriptions as $rx): ?>
          <?php
            $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $rx['PrescriptionText'])), 'strlen'));
            // Structured prescriptions store one medicine per line as
            // "Name — how often — how long"; show each medicine as its own row.
            // Older free-text ones: first line is the title, the rest details.
            if ($lines && str_contains(implode("\n", $lines), ' — ')) {
                $rxItems = array_map(static fn(string $line): array => explode(' — ', $line), $lines);
            } else {
                $rxItems = [[$lines[0] ?? 'Prescription', ...array_slice($lines, 1)]];
            }
          ?>
          <?php foreach ($rxItems as $itemIndex => $parts): ?>
          <?php
            $rxTitle = array_shift($parts);
            $rxDetail = $parts;
            $rxDetail[] = $doctorLabel($rx);
            $rxDetail[] = date('M j', strtotime($rx['AppointmentDate']));
          ?>
          <article class="mr-item<?= $rx['IsActive'] ? '' : ' is-ended' ?>"<?= $itemIndex === 0 ? ' id="rx-' . (int) $rx['AppointmentID'] . '"' : '' ?>>
            <span class="mr-icon <?= $rx['IsActive'] ? 'mr-icon-green' : 'mr-icon-muted' ?>"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="m8.5 8.5 7 7"/></svg></span>
            <div class="mr-body">
              <div class="mr-title-row">
                <strong><?= htmlspecialchars($preview($rxTitle, 60)) ?></strong>
                <span class="ma-pill <?= $rx['IsActive'] ? 'ma-pill-approved' : 'ma-pill-completed' ?>"><?= $rx['IsActive'] ? 'Active' : 'Ended' ?></span>
              </div>
              <p class="mr-sub"><?= htmlspecialchars(implode(' · ', $rxDetail)) ?></p>
            </div>
          </article>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
      <p class="mr-note">Prescriptions show as active for <?= PRESCRIPTION_ACTIVE_DAYS ?> days after your visit. Always follow your physician's instructions.</p>
    <?php else: ?>
      <div class="ma-empty"><h3>No prescriptions on file</h3><p>Prescriptions from your physicians will appear here.</p></div>
    <?php endif; ?>

  <?php else: ?>
    <div class="mr-list">
      <form method="post" enctype="multipart/form-data" class="mr-upload" id="upload">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
        <input type="hidden" name="form_type" value="upload_record">
        <label class="mr-dropzone" id="mrDropzone">
          <input type="file" name="record_file" id="mrFileInput" accept=".pdf,.jpg,.jpeg,.png" required>
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14.9A7 7 0 1 1 15.7 8h1.8a4.5 4.5 0 0 1 2.5 8.2M12 12v9M16 16l-4-4-4 4"/></svg>
          <strong id="mrDropLabel">Drag a file here or <u>browse</u></strong>
          <span>PDF, JPG, or PNG · up to 10 MB</span>
        </label>
        <div class="mr-upload-fields" id="mrUploadFields" hidden>
          <div class="form-stack form-cols-2">
            <label>Title<input type="text" name="title" id="mrTitleInput" maxlength="150" required placeholder="e.g. CBC blood test results"></label>
            <label>Category
              <select name="category">
                <?php foreach ($recordCategories as $category): ?><option><?= htmlspecialchars($category) ?></option><?php endforeach; ?>
              </select>
            </label>
          </div>
          <div class="form-stack">
            <label>Description <span class="optional">(optional)</span><textarea name="description" rows="2"></textarea></label>
          </div>
          <div class="mr-upload-actions">
            <button type="button" class="btn btn-outline btn-sm" id="mrUploadCancel">Cancel</button>
            <button type="submit" class="btn btn-primary btn-sm">Upload record</button>
          </div>
        </div>
      </form>

      <?php foreach ($records as $record): ?>
        <?php
          $rid = (int) $record['RecordID'];
          $fullPath = $storageDir . '/' . basename($record['FilePath']);
          $size = is_file($fullPath) ? $formatBytes((int) filesize($fullPath)) : null;
          $isPdf = $record['FileType'] === 'pdf';
          $meta = array_filter([$record['Category'], date('M j, Y', strtotime($record['UploadedAt'])), $size]);
        ?>
        <article class="mr-item">
          <span class="mr-icon <?= $isPdf ? 'mr-icon-red' : 'mr-icon-purple' ?>">
            <?php if ($isPdf): ?>
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M9 15h6M9 18h4"/></svg>
            <?php else: ?>
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/></svg>
            <?php endif; ?>
          </span>
          <div class="mr-body">
            <strong><?= htmlspecialchars($record['Title']) ?></strong>
            <p class="mr-sub"><?= htmlspecialchars(implode(' · ', $meta)) ?></p>
            <?php if ($record['Description']): ?><p class="mr-text"><?= htmlspecialchars($preview($record['Description'])) ?></p><?php endif; ?>
            <div class="ma-links">
              <a href="<?= HQ_BASE_URL ?>/patient/record-stream.php?record_id=<?= $rid ?>" target="_blank" rel="noopener">View</a>
              <a href="<?= HQ_BASE_URL ?>/patient/record-stream.php?record_id=<?= $rid ?>&amp;download=1">Download</a>
              <form method="post" onsubmit="return confirm('Delete this record? This cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="form_type" value="delete_record">
                <input type="hidden" name="record_id" value="<?= $rid ?>">
                <button type="submit" class="ma-danger">Delete</button>
              </form>
            </div>
          </div>
        </article>
      <?php endforeach; ?>

      <?php if (!$records): ?>
        <p class="mr-empty-line">You haven't uploaded any records yet.</p>
      <?php endif; ?>

      <div class="mr-privacy">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        Only you can see the files you upload here.
      </div>
    </div>
  <?php endif; ?>
</div></main>

<div class="modal-overlay" id="viewRecordModal">
  <div class="modal-box modal-box-wide" style="max-height:85vh;">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <div id="viewRecordContent"><p class="admin-empty">Loading…</p></div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // "View full notes" opens the consultation record (consultation.php) in a modal.
  var viewRecordModal = document.getElementById('viewRecordModal');
  var viewRecordContent = document.getElementById('viewRecordContent');
  document.querySelectorAll('[data-view-record]').forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      viewRecordContent.innerHTML = '<p class="admin-empty">Loading…</p>';
      window.hqOpenModal(viewRecordModal);
      fetch('<?= HQ_BASE_URL ?>/patient/consultation.php?appointment_id=' + encodeURIComponent(trigger.getAttribute('data-view-record')), { headers: { 'X-Requested-With': 'fetch' } })
        .then(function (res) { return res.text(); })
        .then(function (html) { viewRecordContent.innerHTML = html; })
        .catch(function () {
          viewRecordContent.innerHTML = '<p class="form-message error" role="alert">We could not load this record. Please try again.</p>';
        });
    });
  });
  viewRecordContent.addEventListener('submit', function (e) {
    if (!e.target.matches('.consultation-feedback-form')) return;
    e.preventDefault();
    var form = e.target;
    fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'fetch' } })
      .then(function (res) { return res.text(); })
      .then(function (html) { viewRecordContent.innerHTML = html; })
      .catch(function () {
        var err = document.createElement('p');
        err.className = 'form-message error';
        err.textContent = 'We could not save your feedback. Please try again.';
        form.prepend(err);
      });
  });

  // Upload: drag-and-drop or browse, then fill in the title/category.
  var dropzone = document.getElementById('mrDropzone');
  var fileInput = document.getElementById('mrFileInput');
  var fields = document.getElementById('mrUploadFields');
  var dropLabel = document.getElementById('mrDropLabel');
  var titleInput = document.getElementById('mrTitleInput');
  var cancelBtn = document.getElementById('mrUploadCancel');

  function showChosenFile() {
    var file = fileInput.files[0];
    if (!file) return;
    dropLabel.textContent = file.name;
    fields.hidden = false;
    if (!titleInput.value) titleInput.value = file.name.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ');
    titleInput.focus();
  }

  if (dropzone && fileInput) {
    fileInput.addEventListener('change', showChosenFile);
    ['dragenter', 'dragover'].forEach(function (type) {
      dropzone.addEventListener(type, function (e) { e.preventDefault(); dropzone.classList.add('is-dragging'); });
    });
    ['dragleave', 'drop'].forEach(function (type) {
      dropzone.addEventListener(type, function (e) { e.preventDefault(); dropzone.classList.remove('is-dragging'); });
    });
    dropzone.addEventListener('drop', function (e) {
      if (!e.dataTransfer.files.length) return;
      fileInput.files = e.dataTransfer.files;
      showChosenFile();
    });
    cancelBtn.addEventListener('click', function () {
      fileInput.value = '';
      titleInput.value = '';
      fields.hidden = true;
      dropLabel.innerHTML = 'Drag a file here or <u>browse</u>';
    });
  }

  // "Upload record" button: when already on the uploads tab, open the file picker.
  document.querySelectorAll('[data-open-upload]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      if (!fileInput) return;
      e.preventDefault();
      fileInput.click();
    });
  });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
