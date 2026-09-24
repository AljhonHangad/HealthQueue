<?php
/**
 * Session management, CSRF protection, and role-based access control (RBAC).
 * Require this at the top of any page that needs to know who is signed in.
 */

require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Returns the signed-in user's session data, or null if no one is signed in. */
function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

/** True if a user is currently signed in. */
function isLoggedIn(): bool
{
    return currentUser() !== null;
}

/** Stores a user's session data after successful login/registration. */
function loginUser(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'UserID'    => (int) $user['UserID'],
        'ClinicID'  => $user['ClinicID'] !== null ? (int) $user['ClinicID'] : null,
        'RoleID'    => (int) $user['RoleID'],
        'RoleName'  => $user['RoleName'],
        'FirstName' => $user['FirstName'],
        'LastName'  => $user['LastName'],
        'Email'     => $user['Email'],
    ];
}

/** Destroys the current session (logout). */
function logoutUser(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie('PHPSESSID', '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/** The relative prefix ('' at root, '..' one level deep) used to build redirect URLs. */
function hqBaseUrl(): string
{
    return defined('HQ_BASE_URL') ? HQ_BASE_URL : '';
}

/** Redirects a guest to the login page; call at the top of any protected page. */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: ' . hqBaseUrl() . '/auth/login.php');
        exit;
    }
}

/** Redirects an already-logged-in visitor away from guest-only pages (login/register). */
function requireGuest(): void
{
    if (isLoggedIn()) {
        header('Location: ' . hqBaseUrl() . '/' . dashboardPathFor(currentUser()['RoleName']));
        exit;
    }
}

/**
 * Restricts a page to one or more roles. Call requireLogin() first, or use
 * this after it — an unauthenticated visitor is sent to the login page and
 * a wrong-role visitor is sent to their own dashboard instead of a blank 403.
 */
function requireRole(array $allowedRoles): void
{
    requireLogin();
    $user = currentUser();
    if (!in_array($user['RoleName'], $allowedRoles, true)) {
        header('Location: ' . hqBaseUrl() . '/' . dashboardPathFor($user['RoleName']));
        exit;
    }
}

/** Maps a role name to its dashboard's relative path from the app root. */
function dashboardPathFor(string $roleName): string
{
    return match ($roleName) {
        'Patient'   => 'patient/dashboard.php',
        'Staff'     => 'staff/dashboard.php',
        'Physician' => 'physician/dashboard.php',
        'Admin'     => 'admin/dashboard.php',
        default     => 'index.php',
    };
}

/**
 * Creates a password reset token for a user: generates a random raw token,
 * stores only its SHA-256 hash (valid for 30 minutes), and returns the raw
 * token so the caller can build a reset link with it. The raw token is
 * never persisted — only its hash is, mirroring how PasswordHash works.
 */
function createPasswordResetToken(PDO $pdo, int $userId): string
{
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);

    $stmt = $pdo->prepare(
        'INSERT INTO PasswordResets (UserID, TokenHash, ExpiresAt)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))'
    );
    $stmt->execute([$userId, $tokenHash]);

    return $rawToken;
}

/**
 * Looks up a raw reset token and returns the matching {ResetID, UserID,
 * Email, FirstName} row if it exists, hasn't expired, and hasn't been used
 * yet — otherwise null.
 */
function findValidPasswordReset(PDO $pdo, string $rawToken): ?array
{
    $tokenHash = hash('sha256', $rawToken);

    $stmt = $pdo->prepare(
        'SELECT pr.ResetID, u.UserID, u.Email, u.FirstName
         FROM PasswordResets pr
         JOIN Users u ON u.UserID = pr.UserID
         WHERE pr.TokenHash = ? AND pr.UsedAt IS NULL AND pr.ExpiresAt > NOW()'
    );
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/** Marks a password reset token as used so it cannot be replayed. */
function consumePasswordResetToken(PDO $pdo, int $resetId): void
{
    $stmt = $pdo->prepare('UPDATE PasswordResets SET UsedAt = NOW() WHERE ResetID = ?');
    $stmt->execute([$resetId]);
}

/** Generates (and caches in-session) a CSRF token for the current session. */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Verifies a submitted CSRF token against the session's token. */
function verifyCsrfToken(?string $submitted): bool
{
    return is_string($submitted)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $submitted);
}
