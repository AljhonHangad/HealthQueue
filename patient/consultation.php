<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);

$user = currentUser();
$pdo = getDbConnection();

$appointmentId = filter_input(INPUT_GET, 'appointment_id', FILTER_VALIDATE_INT);

if (!$appointmentId || !$pdo) {
    header('Location: ' . HQ_BASE_URL . '/patient/dashboard.php');
    exit;
}

// Scoped to this patient's own UserID -- one patient can never view
// another patient's consultation record.
$stmt = $pdo->prepare(
    "SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.ClinicID, a.PhysicianID, c.ClinicName,
            doc.FirstName AS DoctorFirstName, doc.LastName AS DoctorLastName
     FROM Appointments a
     JOIN Clinic c ON c.ClinicID = a.ClinicID
     JOIN Users doc ON doc.UserID = a.PhysicianID
     WHERE a.AppointmentID = ? AND a.PatientID = ? AND a.Status = 'Completed'"
);
$stmt->execute([$appointmentId, $user['UserID']]);
$appointment = $stmt->fetch();

if (!$appointment) {
    header('Location: ' . HQ_BASE_URL . '/patient/dashboard.php');
    exit;
}

$errors = [];
$flash = '';
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'submit_feedback') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $rating = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_INT);
        $comment = trim((string) ($_POST['comment'] ?? ''));
        if (!$rating || $rating < 1 || $rating > 5) {
            $errors[] = 'Please choose a rating from 1 to 5.';
        } else {
            try {
                $pdo->prepare(
                    'INSERT INTO Feedback (AppointmentID, PatientID, ClinicID, PhysicianID, Rating, Comment) VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$appointmentId, $user['UserID'], $appointment['ClinicID'], $appointment['PhysicianID'], $rating, $comment ?: null]);
                $flash = 'Thanks for your feedback!';
            } catch (PDOException $e) {
                error_log('Feedback submission failed: ' . $e->getMessage());
                $errors[] = 'We could not save your feedback. Please try again.';
            }
        }
    }
}

$feedback = null;
try {
    $fbStmt = $pdo->prepare('SELECT Rating, Comment, CreatedAt FROM Feedback WHERE AppointmentID = ? AND PatientID = ?');
    $fbStmt->execute([$appointmentId, $user['UserID']]);
    $feedback = $fbStmt->fetch();
} catch (PDOException $e) {
    error_log('Feedback load failed: ' . $e->getMessage());
}

$latest = null;
$aiSummary = null;
try {
    $versionStmt = $pdo->prepare(
        "SELECT v.ClinicalNotes, v.PrescriptionText, v.UpdatedAt
         FROM ConsultationVersions v
         JOIN Consultations cons ON cons.ConsultationID = v.ConsultationID
         WHERE cons.AppointmentID = ? AND v.Status <> 'Draft'
         ORDER BY v.RevisionNumber DESC
         LIMIT 1"
    );
    $versionStmt->execute([$appointmentId]);
    $latest = $versionStmt->fetch();

    // Patients only ever see the AI_Summary type -- the raw speech-to-text
    // transcript and the doctor's corrected version are clinical working
    // material, not something meant for patient consumption.
    $summaryStmt = $pdo->prepare(
        "SELECT t.TranscriptText, t.GeneratedAt
         FROM AITranscriptVersions t
         JOIN Consultations cons ON cons.ConsultationID = t.ConsultationID
         WHERE cons.AppointmentID = ? AND t.TranscriptType = 'AI_Summary'
         ORDER BY t.GeneratedAt DESC
         LIMIT 1"
    );
    $summaryStmt->execute([$appointmentId]);
    $aiSummary = $summaryStmt->fetch();
} catch (PDOException $e) {
    error_log('Patient consultation view failed: ' . $e->getMessage());
}

if (!$isAjax) {
    $pageTitle = 'Consultation Record — HealthQueue';
    require __DIR__ . '/../includes/header.php';
}
?>

<?php if (!$isAjax): ?>
<main class="portal-shell"><div class="container">
  <a href="<?= HQ_BASE_URL ?>/patient/medical-records.php" class="back-link no-print">&larr; Back to medical records</a>
<?php endif; ?>

  <section class="portal-hero" style="margin-top:18px;">
    <div>
      <span class="eyebrow">Consultation record</span>
      <h1><?= htmlspecialchars($appointment['ClinicName']) ?></h1>
      <p>Dr. <?= htmlspecialchars($appointment['DoctorFirstName'] . ' ' . $appointment['DoctorLastName']) ?> &middot; <?= htmlspecialchars(date('l, F j, Y', strtotime($appointment['AppointmentDate']))) ?> at <?= htmlspecialchars(date('g:i A', strtotime($appointment['AppointmentTime']))) ?></p>
    </div>
  </section>

  <?php if ($latest): ?>
    <section class="portal-section">
      <div class="portal-heading"><div><span class="section-kicker">Notes</span><h2>What your physician recorded</h2></div></div>
      <div class="compact-list">
        <article style="align-items:flex-start;flex-direction:column;gap:10px;">
          <div>
            <strong>Clinical notes</strong>
            <p style="margin:6px 0 0;color:var(--slate-600);font-size:14px;white-space:pre-line;"><?= htmlspecialchars($latest['ClinicalNotes']) ?></p>
          </div>
          <?php if ($latest['PrescriptionText']): ?>
            <div>
              <strong>Prescription</strong>
              <p style="margin:6px 0 0;color:var(--slate-600);font-size:14px;white-space:pre-line;"><?= htmlspecialchars($latest['PrescriptionText']) ?></p>
            </div>
          <?php endif; ?>
        </article>
      </div>
      <p style="margin-top:14px;color:var(--slate-400);font-size:12.5px;">Last updated <?= htmlspecialchars(date('M j, Y g:i A', strtotime($latest['UpdatedAt']))) ?>. This summary is for your reference only and is not a substitute for medical advice.</p>
    </section>
  <?php else: ?>
    <section class="portal-section">
      <p class="admin-empty">No notes have been recorded for this visit yet.</p>
    </section>
  <?php endif; ?>

  <section class="portal-section no-print" id="ai-summary">
    <div class="portal-heading"><div><span class="section-kicker">AI visit summary</span><h2>Plain-language summary of your visit</h2></div></div>
    <?php if ($aiSummary): ?>
      <div class="compact-list">
        <article style="align-items:flex-start;flex-direction:column;gap:10px;">
          <p style="margin:0;color:var(--slate-600);font-size:14px;white-space:pre-line;"><?= htmlspecialchars($aiSummary['TranscriptText']) ?></p>
        </article>
      </div>
      <p style="margin-top:14px;color:var(--slate-400);font-size:12.5px;">Generated <?= htmlspecialchars(date('M j, Y g:i A', strtotime($aiSummary['GeneratedAt']))) ?>.</p>
      <div class="dev-note">
        <strong>Note:</strong> AI transcription/summarization is not connected to a real speech-to-text or language model yet — the text above is placeholder output from the pipeline being built ahead of that integration. Always rely on the notes from your physician above, not this summary.
      </div>
    <?php else: ?>
      <p class="admin-empty">No AI-generated summary is available for this visit yet.</p>
      <div class="dev-note">
        <strong>Coming soon:</strong> after your consultation, your physician's session recording can be run through an AI transcription and summarization step to produce a plain-language recap here. That AI service isn't connected yet, so this section is currently a placeholder.
      </div>
    <?php endif; ?>
  </section>

  <section class="portal-section no-print">
    <div class="portal-heading"><div><span class="section-kicker">Feedback</span><h2>Rate this visit</h2></div></div>
    <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
    <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <?php if ($feedback): ?>
      <div class="compact-list">
        <article style="align-items:flex-start;flex-direction:column;gap:6px;">
          <strong style="color:#f59e0b;font-size:18px;letter-spacing:2px;"><?= str_repeat('★', (int) $feedback['Rating']) . str_repeat('☆', 5 - (int) $feedback['Rating']) ?></strong>
          <?php if ($feedback['Comment']): ?><p style="margin:0;color:var(--slate-600);font-size:14px;white-space:pre-line;"><?= htmlspecialchars($feedback['Comment']) ?></p><?php endif; ?>
          <span style="color:var(--slate-400);font-size:12px;">Submitted <?= htmlspecialchars(date('M j, Y', strtotime($feedback['CreatedAt']))) ?></span>
        </article>
      </div>
    <?php else: ?>
      <form method="post" action="<?= HQ_BASE_URL ?>/patient/consultation.php?appointment_id=<?= (int) $appointmentId ?>" class="consultation-feedback-form" style="max-width:560px;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
        <input type="hidden" name="form_type" value="submit_feedback">
        <input type="hidden" name="appointment_id" value="<?= (int) $appointmentId ?>">
        <div class="form-stack">
          <label>Your rating
            <div style="display:flex;gap:16px;margin-top:6px;">
              <?php for ($i = 1; $i <= 5; $i++): ?>
                <label style="display:flex;flex-direction:column;align-items:center;gap:4px;font-weight:400;"><input type="radio" name="rating" value="<?= $i ?>" required><?= $i ?></label>
              <?php endfor; ?>
            </div>
          </label>
          <label>Comment <span class="optional">(optional)</span><textarea name="comment" rows="3" placeholder="How was your visit?"></textarea></label>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Submit Feedback</button>
      </form>
    <?php endif; ?>
  </section>
<?php if (!$isAjax): ?>
</div></main>

<?php if (isset($_GET['print'])): ?>
<script>
// Opened from Medical Records' "Download PDF": show the browser's print
// dialog, where the patient can choose "Save as PDF".
window.addEventListener('load', function () { window.print(); });
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
<?php endif; ?>
