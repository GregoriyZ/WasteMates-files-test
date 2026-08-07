<?php
/**
 * WasteMates enquiry handler.
 *
 * Replaces formsubmit.co: accepts the hero/contact/pricing forms, emails the
 * enquiry (with however many photos were attached) via the Resend API, and
 * pushes instant Telegram + Discord notifications to the owner so a lead
 * never sits unseen in an inbox.
 */

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/lib/enquiry-notify.php';

const MAX_PHOTOS = 8;
const MAX_PHOTO_BYTES = 6 * 1024 * 1024;   // 6MB per photo
const MAX_TOTAL_BYTES = 15 * 1024 * 1024;  // 15MB combined, keeps the emailed
                                            // (base64) attachment size under
                                            // common 25MB inbox limits
const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif'];

const ALLOWED_REDIRECTS = [
    'index.html'   => '/index.html?sent=1',
    'contact.html' => '/contact.html?sent=1',
    'pricing.html' => '/pricing.html?sent=1',
];

function redirect_to(string $key): void
{
    $target = ALLOWED_REDIRECTS[$key] ?? '/contact.html?sent=1';
    header('Location: ' . $target, true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /contact.html', true, 303);
    exit;
}

$redirectKey = $_POST['_redirect'] ?? 'contact.html';

// Honeypot — bots fill every field including hidden ones. Pretend success
// so we don't tip them off, but do nothing further.
if (!empty($_POST['_honey'])) {
    redirect_to($redirectKey);
}

$config = @include __DIR__ . '/config.php';
if (!is_array($config)) {
    error_log('WasteMates enquiry: config.php missing or invalid');
    redirect_to($redirectKey);
}

$fields = [
    'name'               => clean_field((string) ($_POST['name'] ?? '')),
    'mobile'             => clean_field((string) ($_POST['mobile'] ?? '')),
    'email'              => clean_field((string) ($_POST['email'] ?? '')),
    'suburb'             => clean_field((string) ($_POST['suburb'] ?? '')),
    'contact_preference' => clean_field((string) ($_POST['contact_preference'] ?? '')),
    'job'                => clean_field((string) ($_POST['job'] ?? '')),
    'details'            => trim((string) ($_POST['details'] ?? '')),
];
$source = clean_field((string) ($_POST['_source'] ?? 'WasteMates website'));

// Nothing worth acting on — don't bother sending an empty lead.
if ($fields['name'] === '' && $fields['mobile'] === '' && $fields['email'] === '') {
    redirect_to($redirectKey);
}

// ── Collect + validate photo uploads ───────────────────────────────────────

$attachments = [];   // [['tmp' => ..., 'name' => ..., 'mime' => ...], ...]
$uploadNotes = [];    // human-readable notes about skipped photos
$totalBytes = 0;

if (!empty($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $count = count($_FILES['photos']['name']);

    for ($i = 0; $i < $count; $i++) {
        if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $origName = basename((string) $_FILES['photos']['name'][$i]);

        if (count($attachments) >= MAX_PHOTOS) {
            $uploadNotes[] = "Only the first " . MAX_PHOTOS . " photos were attached (more were selected).";
            break;
        }

        if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) {
            $uploadNotes[] = "\"{$origName}\" failed to upload and was skipped.";
            continue;
        }

        $tmp = $_FILES['photos']['tmp_name'][$i];
        $size = (int) $_FILES['photos']['size'][$i];

        if ($size > MAX_PHOTO_BYTES) {
            $uploadNotes[] = "\"{$origName}\" was skipped — over the 6MB per-photo limit.";
            continue;
        }
        if ($totalBytes + $size > MAX_TOTAL_BYTES) {
            $uploadNotes[] = "\"{$origName}\" was skipped — total photo size limit reached.";
            continue;
        }

        $mime = $finfo->file($tmp) ?: '';
        if (!in_array($mime, ALLOWED_MIMES, true)) {
            $uploadNotes[] = "\"{$origName}\" was skipped — not a supported image type.";
            continue;
        }

        $totalBytes += $size;
        $attachments[] = ['tmp' => $tmp, 'name' => $origName, 'mime' => $mime];
    }
}

wm_send_enquiry_notifications($config, $fields, $source, $attachments, $uploadNotes);

redirect_to($redirectKey);
