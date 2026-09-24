<?php
/**
 * Shared announcement helpers for the admin (platform-wide) and staff
 * (own clinic) posting pages.
 */

const ANNOUNCEMENT_CATEGORIES = ['General', 'Closure', 'Schedule change', 'Event', 'Health advisory'];
const ANNOUNCEMENT_PHOTO_DIR = __DIR__ . '/../assets/uploads/announcements';

/**
 * Validates and stores an optional announcement photo from $_FILES['photo'].
 * Returns [filename|null, error|null]; [null, null] when no file was chosen.
 */
function saveAnnouncementPhoto(?array $file): array
{
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) return [null, null];
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] <= 0) return [null, 'We could not read the uploaded photo. Please try again.'];
    if ($file['size'] > 5 * 1024 * 1024) return [null, 'Announcement photo is too large (5 MB limit).'];

    $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = mime_content_type($file['tmp_name']);
    if (!isset($allowedTypes[$mime])) return [null, 'Please upload a JPG, PNG, WEBP, or GIF image.'];

    if (!is_dir(ANNOUNCEMENT_PHOTO_DIR)) mkdir(ANNOUNCEMENT_PHOTO_DIR, 0755, true);
    $filename = 'announcement_' . bin2hex(random_bytes(8)) . '.' . $allowedTypes[$mime];
    if (!move_uploaded_file($file['tmp_name'], ANNOUNCEMENT_PHOTO_DIR . '/' . $filename)) {
        return [null, 'We could not save the photo. Please try again.'];
    }
    return [$filename, null];
}

/** Deletes a stored announcement photo, if any. */
function deleteAnnouncementPhoto(?string $filename): void
{
    if ($filename && is_file(ANNOUNCEMENT_PHOTO_DIR . '/' . basename($filename))) {
        unlink(ANNOUNCEMENT_PHOTO_DIR . '/' . basename($filename));
    }
}

/** Public URL for an announcement photo (relative to HQ_BASE_URL). */
function announcementPhotoUrl(?string $filename): ?string
{
    return $filename ? HQ_BASE_URL . '/assets/uploads/announcements/' . rawurlencode(basename($filename)) : null;
}
