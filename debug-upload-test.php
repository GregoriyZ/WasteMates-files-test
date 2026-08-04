<?php
// Temporary diagnostic script — not linked from anywhere, not part of the
// live enquiry flow. Reports exactly what PHP sees for an uploaded file on
// this specific GoDaddy environment, to track down why attachments aren't
// making it through send-enquiry.php. Delete after use.

declare(strict_types=1);
header('Content-Type: application/json');

$out = [
    'php_version'    => PHP_VERSION,
    'sapi'           => php_sapi_name(),
    'open_basedir'   => ini_get('open_basedir'),
    'upload_tmp_dir' => ini_get('upload_tmp_dir'),
    'sys_temp_dir'   => sys_get_temp_dir(),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size'  => ini_get('post_max_size'),
    'max_file_uploads' => ini_get('max_file_uploads'),
    'fileinfo_loaded' => extension_loaded('fileinfo'),
    'curl_loaded'    => extension_loaded('curl'),
    'files_raw'      => $_FILES,
];

if (!empty($_FILES['test_photo']) && is_array($_FILES['test_photo']['name'])) {
    $count = count($_FILES['test_photo']['name']);
    $details = [];
    for ($i = 0; $i < $count; $i++) {
        $tmp = $_FILES['test_photo']['tmp_name'][$i] ?? null;
        $d = [
            'name'  => $_FILES['test_photo']['name'][$i] ?? null,
            'error' => $_FILES['test_photo']['error'][$i] ?? null,
            'size_reported' => $_FILES['test_photo']['size'][$i] ?? null,
            'tmp_name' => $tmp,
        ];
        if ($tmp) {
            $d['file_exists'] = file_exists($tmp);
            $d['is_readable'] = is_readable($tmp);
            $d['is_uploaded_file'] = is_uploaded_file($tmp);
            $d['filesize'] = @filesize($tmp);
            try {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $d['finfo_mime'] = $finfo->file($tmp);
            } catch (\Throwable $e) {
                $d['finfo_error'] = $e->getMessage();
            }
        }
        $details[] = $d;
    }
    $out['test_photo_details'] = $details;
}

echo json_encode($out, JSON_PRETTY_PRINT);
