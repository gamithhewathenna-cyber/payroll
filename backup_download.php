<?php
require_once 'config.php';
require_once 'includes/backup.php';
requireAdmin();

$file = basename($_GET['file'] ?? ''); // basename() blocks any path traversal
$path = backupDirPath() . $file;

if (!$file || !preg_match('/^backup_[\d-]+_[\d-]+\.zip$/', $file) || !file_exists($path)) {
    http_response_code(404);
    exit('Backup file not found.');
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
