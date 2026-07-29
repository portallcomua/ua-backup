<?php
defined('ABSPATH') || exit;

class UA_Backup_Engine {
    public static function backup_dir(): string {
        return WP_CONTENT_DIR . '/ua-backups';
    }

    public static function list_backups(): array {
        $dir = self::backup_dir();
        if (!is_dir($dir)) return [];
        $files = glob($dir . '/ua-backup-*.zip') ?: [];
        usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));
        return array_map(static function ($file) {
            return [
                'name' => basename($file),
                'size' => filesize($file),
                'time' => filemtime($file),
            ];
        }, $files);
    }

    public static function create_backup(): array {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('На сервері не увімкнено PHP ZipArchive.');
        }
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $dir = self::backup_dir();
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            throw new RuntimeException('Не вдалося створити папку резервних копій.');
        }

        $stamp = gmdate('Y-m-d_H-i-s');
        $filename = 'ua-backup-' . sanitize_title(parse_url(home_url(), PHP_URL_HOST)) . '-' . $stamp . '.zip';
        $path = $dir . '/' . $filename;
        $tmp_sql = wp_tempnam('ua-backup-db');
        if (!$tmp_sql) throw new RuntimeException('Не вдалося створити тимчасовий файл бази.');

        self::export_database($tmp_sql);

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp_sql);
            throw new RuntimeException('Не вдалося створити ZIP-архів.');
        }

        $manifest = [
            'plugin' => 'UA Backup',
            'version' => UAB_VERSION,
            'created_at' => gmdate('c'),
            'site_url' => site_url(),
            'home_url' => home_url(),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'table_prefix' => $GLOBALS['table_prefix'],
        ];
        $zip->addFromString('ua-backup.json', wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $zip->addFile($tmp_sql, 'database.sql');
        $zip->addFromString('installer.php', self::installer_code());

        $root = untrailingslashit(ABSPATH);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        $backup_real = realpath($dir);
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) continue;
            $real = $file->getRealPath();
            if (!$real) continue;
            if ($backup_real && strpos($real, $backup_real . DIRECTORY_SEPARATOR) === 0) continue;
            $relative = ltrim(str_replace('\\', '/', substr($real, strlen($root))), '/');
            if ($relative === '.maintenance') continue;
            $zip->addFile($real, 'site-files/' . $relative);
        }
        $zip->close();
        @unlink($tmp_sql);

        if (!is_file($path) || filesize($path) < 100) {
            @unlink($path);
            throw new RuntimeException('Архів не створено або він пошкоджений.');
        }

        return ['name' => $filename, 'size' => filesize($path), 'path' => $path];
    }

    private static function export_database(string $file): void {
        global $wpdb;
        $fh = fopen($file, 'wb');
        if (!$fh) throw new RuntimeException('Не вдалося відкрити файл SQL.');
        fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        $tables = $wpdb->get_col('SHOW TABLES');
        foreach ($tables as $table) {
            $create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
            if (!$create || empty($create[1])) continue;
            fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n{$create[1]};\n\n");
            $offset = 0;
            $limit = 500;
            do {
                $rows = $wpdb->get_results("SELECT * FROM `{$table}` LIMIT {$offset}, {$limit}", ARRAY_A);
                foreach ($rows as $row) {
                    $values = [];
                    foreach ($row as $value) {
                        $values[] = is_null($value) ? 'NULL' : "'" . self::sql_escape((string)$value) . "'";
                    }
                    fwrite($fh, "INSERT INTO `{$table}` VALUES (" . implode(',', $values) . ");\n");
                }
                $offset += $limit;
            } while (count($rows) === $limit);
            fwrite($fh, "\n");
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);
    }

    private static function sql_escape(string $value): string {
        return str_replace(["\\", "\0", "\n", "\r", "'", '"', "\x1a"], ["\\\\", "\\0", "\\n", "\\r", "\\'", '\\"', "\\Z"], $value);
    }

    public static function delete(string $name): bool {
        $name = basename($name);
        if (!preg_match('/^ua-backup-[a-zA-Z0-9._-]+\.zip$/', $name)) return false;
        $path = self::backup_dir() . '/' . $name;
        return is_file($path) ? unlink($path) : false;
    }

    public static function file_path(string $name): string {
        $name = basename($name);
        if (!preg_match('/^ua-backup-[a-zA-Z0-9._-]+\.zip$/', $name)) return '';
        $path = self::backup_dir() . '/' . $name;
        return is_file($path) ? $path : '';
    }

    private static function installer_code(): string {
        $path = UAB_DIR . 'includes/installer-template.php';
        return is_file($path) ? (string) file_get_contents($path) : '<?php echo "UA Backup installer missing";';
    }
}
