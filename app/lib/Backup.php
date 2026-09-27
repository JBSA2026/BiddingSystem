<?php
declare(strict_types=1);

/** Pure-PHP database dump and file archive (works on shared hosting without mysqldump/shell). */
final class Backup
{
    /** Write a full SQL dump (structure + data + triggers) to $file. Returns the path. */
    public static function dumpDatabase(string $file): string
    {
        $fh = fopen($file, 'w');
        if (!$fh) {
            throw new RuntimeException('Cannot write backup file.');
        }
        $pdo = DB::pdo();
        fwrite($fh, "-- Cityland Online Property Bidding — database backup\n-- Generated: " . date('c') . "\n-- Database: " . config('db.name') . "\n\n");
        fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET time_zone = '" . (new DateTimeImmutable())->format('P') . "';\n\n");
        $tables = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_NUM);
        foreach ($tables as [$table]) {
            $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '', $table) . '`')->fetch(PDO::FETCH_NUM)[1];
            fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n{$create};\n\n");
            $stmt = $pdo->query('SELECT * FROM `' . str_replace('`', '', $table) . '`', PDO::FETCH_NUM);
            $batch = [];
            while ($row = $stmt->fetch()) {
                $vals = array_map(static fn($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)), $row);
                $batch[] = '(' . implode(',', $vals) . ')';
                if (count($batch) >= 200) {
                    fwrite($fh, "INSERT INTO `{$table}` VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                fwrite($fh, "INSERT INTO `{$table}` VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            fwrite($fh, "\n");
        }
        $triggers = $pdo->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC);
        if ($triggers) {
            fwrite($fh, "DELIMITER \$\$\n");
            foreach ($triggers as $t) {
                fwrite($fh, "DROP TRIGGER IF EXISTS `{$t['Trigger']}`\$\$\nCREATE TRIGGER `{$t['Trigger']}` {$t['Timing']} {$t['Event']} ON `{$t['Table']}` FOR EACH ROW {$t['Statement']}\$\$\n");
            }
            fwrite($fh, "DELIMITER ;\n");
        }
        fwrite($fh, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
        fclose($fh);
        return $file;
    }

    /** Create a backup set in storage/backups. Returns list of created files. */
    public static function create(bool $includeFiles = true): array
    {
        $dir = storage_path('backups');
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $stamp = date('Ymd-His');
        $sql = self::dumpDatabase($dir . "/db-{$stamp}.sql");
        $out = [];
        if (function_exists('gzencode')) {
            $gz = $sql . '.gz';
            $in = fopen($sql, 'rb');
            $zh = gzopen($gz, 'wb6');
            while (!feof($in)) {
                gzwrite($zh, (string) fread($in, 1 << 20));
            }
            fclose($in);
            gzclose($zh);
            unlink($sql);
            $out[] = $gz;
        } else {
            $out[] = $sql;
        }
        if ($includeFiles && class_exists('ZipArchive')) {
            $zipPath = $dir . "/files-{$stamp}.zip";
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $base = storage_path('uploads');
                if (is_dir($base)) {
                    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
                    foreach ($it as $f) {
                        if ($f->isFile()) {
                            $zip->addFile($f->getPathname(), 'uploads/' . substr($f->getPathname(), strlen($base) + 1));
                        }
                    }
                }
                $zip->addFromString('README.txt', "Restore: extract the 'uploads' folder into your storage path (" . storage_path() . ").\n");
                $zip->close();
                $out[] = $zipPath;
            }
        }
        return $out;
    }

    public static function list(): array
    {
        $dir = storage_path('backups');
        $files = glob($dir . '/{db,files}-*.{sql,sql.gz,zip}', GLOB_BRACE) ?: [];
        rsort($files);
        return array_map(static fn($f) => ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)], $files);
    }

    /** Keep the newest $keep backup sets. */
    public static function rotate(int $keep = 14): void
    {
        foreach (['db-*', 'files-*'] as $pattern) {
            $files = glob(storage_path('backups') . '/' . $pattern) ?: [];
            rsort($files);
            foreach (array_slice($files, $keep) as $old) {
                @unlink($old);
            }
        }
    }
}
