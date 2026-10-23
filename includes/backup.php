<?php
// Full-system backup: a SQL dump of every database table plus the uploaded-files
// directories (invoices/, uploads/), zipped together and saved to backups/.
// Triggered either manually (Settings page) or automatically once a week, piggybacked
// on a normal admin page load (shared hosting here has no guaranteed cron) — see
// maybeRunAutoBackup(), called from includes/layout.php's pageFooter().

define('BACKUP_DIR', __DIR__ . '/../backups/');
define('BACKUP_KEEP', 8); // ~2 months of weekly backups

function backupDirPath() {
    if (!is_dir(BACKUP_DIR)) {
        mkdir(BACKUP_DIR, 0755, true);
    }
    // Keep the folder itself out of reach even if .htaccess support is ever misconfigured
    $htaccess = BACKUP_DIR . '.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }
    return BACKUP_DIR;
}

// Dumps every table's structure + data as a single .sql string.
function dumpDatabaseToSql($db) {
    $sql = "-- PayrollPro database backup — " . date('Y-m-d H:i:s') . "\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $create = $db->query("SHOW CREATE TABLE `{$table}`")->fetch();
        $sql .= "-- --------------------------------------------------\n";
        $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $sql .= $create['Create Table'] . ";\n\n";

        $rows = $db->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) continue;

        $columns = array_keys($rows[0]);
        $colList = '`' . implode('`,`', $columns) . '`';
        foreach (array_chunk($rows, 200) as $chunk) {
            $valueRows = [];
            foreach ($chunk as $row) {
                $vals = array_map(function ($v) use ($db) {
                    if ($v === null) return 'NULL';
                    return $db->quote($v);
                }, array_values($row));
                $valueRows[] = '(' . implode(',', $vals) . ')';
            }
            $sql .= "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $valueRows) . ";\n";
        }
        $sql .= "\n";
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $sql;
}

// Adds a directory's contents to the zip, recursively, under $zipPrefix.
function addDirToZip($zip, $dir, $zipPrefix) {
    if (!is_dir($dir)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isDir()) continue;
        $localPath = $zipPrefix . '/' . substr($file->getPathname(), strlen($dir));
        $zip->addFile($file->getPathname(), str_replace('\\', '/', $localPath));
    }
}

// Runs a full backup right now: DB dump + invoices/ + uploads/, zipped to backups/.
// Returns the created filename on success, or throws on failure.
function runSystemBackup($db) {
    if (!class_exists('ZipArchive')) {
        throw new Exception('The PHP "zip" extension is not enabled on this server — ask your host to enable it.');
    }
    @set_time_limit(300);
    $dir = backupDirPath();
    $filename = 'backup_' . date('Y-m-d_H-i-s') . '.zip';
    $zipPath = $dir . $filename;

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new Exception('Could not create backup zip file.');
    }

    $sql = dumpDatabaseToSql($db);
    $zip->addFromString('database.sql', $sql);

    addDirToZip($zip, __DIR__ . '/../invoices', 'invoices');
    addDirToZip($zip, __DIR__ . '/../uploads', 'uploads');

    $zip->close();

    saveSetting($db, 'last_backup_at', date('Y-m-d H:i:s'));
    saveSetting($db, 'last_backup_file', $filename);
    saveSetting($db, 'last_backup_size', (string)filesize($zipPath));

    pruneOldBackups();
    return $filename;
}

// Keeps only the most recent BACKUP_KEEP zip files.
function pruneOldBackups() {
    $dir = backupDirPath();
    $files = glob($dir . 'backup_*.zip');
    if (!$files) return;
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
    foreach (array_slice($files, BACKUP_KEEP) as $old) {
        @unlink($old);
    }
}

function listBackups() {
    $dir = backupDirPath();
    $files = glob($dir . 'backup_*.zip');
    if (!$files) return [];
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
    return array_map(fn($f) => [
        'name' => basename($f),
        'size' => filesize($f),
        'date' => filemtime($f),
    ], $files);
}

// Called on every admin page load (see pageFooter()) — cheap no-op unless a week has
// actually passed, and never allowed to break the page it's piggybacking on.
function maybeRunAutoBackup($db) {
    try {
        if (getSetting('auto_backup_enabled', '1') !== '1') return;
        $last = getSetting('last_backup_at', '');
        if ($last && (time() - strtotime($last)) < 7 * 86400) return;
        runSystemBackup($db);
        clearSettingsCache();
    } catch (Exception $e) {
        // Never let a failed backup take down the page it piggybacked on.
    }
}
