<?php
defined('ABSPATH') || exit;

class UA_Backup_Engine {
    private static string $active_run_id = '';
    public static function backup_dir(): string {
        return WP_CONTENT_DIR . '/ua-backups';
    }

    public static function list_backups(): array {
        $dir = self::backup_dir();
        if (!is_dir($dir)) return [];
        $files = glob($dir . '/ua-backup-*.zip') ?: [];
        usort($files, static function ($a, $b) { return (int) filemtime($b) <=> (int) filemtime($a); });
        return array_map(static function ($file) {
            return ['name' => basename($file), 'size' => (int) filesize($file), 'time' => (int) filemtime($file)];
        }, $files);
    }

    private static function update_progress($percent, $message): void {
        $key = 'uab_progress_' . (function_exists('get_current_user_id') ? get_current_user_id() : 0);
        set_transient($key, ['run_id' => self::$active_run_id, 'percent' => max(0, min(100, (int) $percent)), 'message' => (string) $message], 30 * MINUTE_IN_SECONDS);
    }

    public static function reset_progress(string $runId = ''): void {
        self::$active_run_id = preg_replace('/[^a-f0-9]/i', '', $runId);
        self::update_progress(0, 'Підготовка резервної копії');
    }

    public static function progress(string $runId = ''): array {
        $key = 'uab_progress_' . get_current_user_id();
        $data = get_transient($key);
        if (!is_array($data)) return ['run_id' => $runId, 'percent' => 0, 'message' => 'Очікування'];
        if ($runId !== '' && (!isset($data['run_id']) || !hash_equals((string) $data['run_id'], $runId))) {
            return ['run_id' => $runId, 'percent' => 0, 'message' => 'Запуск резервного копіювання'];
        }
        return $data;
    }

    public static function create_backup(): array {
        if (!class_exists('ZipArchive')) throw new RuntimeException('На сервері не увімкнено PHP ZipArchive.');
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        $dir = self::backup_dir();
        if (!is_dir($dir) && !wp_mkdir_p($dir)) throw new RuntimeException('Не вдалося створити папку резервних копій.');
        if (!is_writable($dir)) throw new RuntimeException('Папка резервних копій недоступна для запису.');

        $lock = $dir . '/.backup.lock';
        $lockHandle = @fopen($lock, 'c');
        if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            if (is_resource($lockHandle)) fclose($lockHandle);
            throw new RuntimeException('Створення іншої резервної копії вже виконується.');
        }
        self::update_progress(1, 'Підготовка резервної копії');
        $tmpSql = '';
        $tmpFilesZip = '';
        $tmpZip = '';
        $finalPath = '';
        try {
            $stamp = gmdate('Y-m-d_H-i-s');
            $installerToken = bin2hex(random_bytes(16));
            $host = sanitize_title((string) parse_url(home_url(), PHP_URL_HOST));
            $filename = 'ua-backup-' . ($host ?: 'wordpress') . '-' . $stamp . '.zip';
            $finalPath = $dir . '/' . $filename;
            $tmpSql = wp_tempnam('ua-backup-db');
            if (!$tmpSql) throw new RuntimeException('Не вдалося створити тимчасовий файл бази.');
            self::update_progress(5, 'Експорт бази даних');
            self::export_database($tmpSql);
            self::update_progress(18, 'Базу даних збережено. Підготовка файлів сайту');

            $tmpZip = $dir . '/.' . $filename . '.part';
            $zip = new ZipArchive();
            if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Не вдалося створити ZIP-архів.');
            $manifest = [
                'plugin' => 'UA Backup',
                'version' => defined('UAB_VERSION') ? UAB_VERSION : '2.0.0',
                'created_at' => gmdate('c'),
                'site_url' => site_url(),
                'home_url' => home_url(),
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'table_prefix' => (string) $GLOBALS['table_prefix'],
                'archive_format' => 3,
                'installer_token_hash' => hash('sha256', $installerToken),
                'installer_token' => $installerToken,
            ];
            if (!$zip->addFromString('.ua-backup-private/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n")) throw new RuntimeException('Не вдалося захистити службову папку архіву.');
            if (!$zip->addFromString('.ua-backup-private/index.php', "<?php http_response_code(404); exit;\n")) throw new RuntimeException('Не вдалося додати захист службової папки.');
            if (!$zip->addFromString('.ua-backup-private/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>")) throw new RuntimeException('Не вдалося додати захист службової папки для IIS.');
            if (!$zip->addFromString('.ua-backup-private/ua-backup.json', wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))) throw new RuntimeException('Не вдалося додати опис резервної копії.');
            if (!$zip->addFile($tmpSql, '.ua-backup-private/database.sql')) throw new RuntimeException('Не вдалося додати базу даних до архіву.');
            if (!$zip->addFromString('installer.php', self::installer_code())) throw new RuntimeException('Не вдалося додати інсталятор до архіву.');
            $tmpFilesZip = wp_tempnam('ua-backup-files');
            if (!$tmpFilesZip) throw new RuntimeException('Не вдалося створити тимчасовий архів файлів.');
            $filesZip = new ZipArchive();
            if ($filesZip->open($tmpFilesZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Не вдалося створити архів файлів сайту.');

            $root = realpath(untrailingslashit(ABSPATH));
            if (!$root || !is_dir($root)) throw new RuntimeException('Не вдалося визначити кореневу папку WordPress.');
            $backupReal = realpath($dir);
            $countIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
            $totalFiles = 0;
            foreach ($countIterator as $countFile) {
                if ($countFile->isFile() && !$countFile->isLink()) {
                    $countReal = $countFile->getRealPath();
                    if ($countReal && (!$backupReal || strpos($countReal, $backupReal . DIRECTORY_SEPARATOR) !== 0)) $totalFiles++;
                }
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
            $processedFiles = 0;
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->isLink()) continue;
                $real = $file->getRealPath();
                if (!$real || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) continue;
                if ($backupReal && ($real === $backupReal || strpos($real, $backupReal . DIRECTORY_SEPARATOR) === 0)) continue;
                $relative = ltrim(str_replace('\\', '/', substr($real, strlen($root))), '/');
                if ($relative === '.maintenance' || $relative === '') continue;
                if (!$filesZip->addFile($real, $relative)) throw new RuntimeException('Не вдалося додати файл до архіву: ' . $relative);
                $processedFiles++;
                if ($processedFiles % 25 === 0 || $processedFiles === $totalFiles) {
                    $percent = $totalFiles > 0 ? 18 + (int) floor(72 * $processedFiles / $totalFiles) : 90;
                    self::update_progress($percent, 'Архівація файлів: ' . $processedFiles . ' із ' . $totalFiles);
                }
            }
            if (!$filesZip->close()) throw new RuntimeException('Не вдалося завершити архів файлів сайту.');
            if (!$zip->addFile($tmpFilesZip, '.ua-backup-private/site-files.zip')) throw new RuntimeException('Не вдалося додати архів файлів до резервної копії.');
            self::update_progress(92, 'Перевірка цілісності ZIP-архіву');
            if (!$zip->close()) throw new RuntimeException('Не вдалося завершити ZIP-архів.');
            $check = new ZipArchive();
            if ($check->open($tmpZip, ZipArchive::CHECKCONS) !== true) throw new RuntimeException('Перевірка ZIP-архіву не пройдена.');
            $valid = $check->locateName('.ua-backup-private/ua-backup.json') !== false && $check->locateName('.ua-backup-private/database.sql') !== false && $check->locateName('installer.php') !== false && $check->locateName('.ua-backup-private/site-files.zip') !== false;
            $check->close();
            if (!$valid || !is_file($tmpZip) || filesize($tmpZip) < 100) throw new RuntimeException('Архів неповний або пошкоджений.');
            if (!@rename($tmpZip, $finalPath)) throw new RuntimeException('Не вдалося зберегти готовий ZIP-архів.');
            self::update_progress(100, 'Бекап успішно створено');
            return ['name' => $filename, 'size' => (int) filesize($finalPath), 'path' => $finalPath, 'installer_token' => $installerToken];
        } catch (Throwable $e) {
            if ($tmpZip && is_file($tmpZip)) @unlink($tmpZip);
            if ($finalPath && is_file($finalPath)) @unlink($finalPath);
            self::update_progress(0, 'Помилка: ' . $e->getMessage());
            throw $e;
        } finally {
            if ($tmpSql && is_file($tmpSql)) @unlink($tmpSql);
            if ($tmpFilesZip && is_file($tmpFilesZip)) @unlink($tmpFilesZip);
            if (is_resource($lockHandle)) { flock($lockHandle, LOCK_UN); fclose($lockHandle); }
        }
    }

    private static function export_database(string $file): void {
        global $wpdb;
        $fh = @fopen($file, 'wb');
        if (!$fh) throw new RuntimeException('Не вдалося відкрити тимчасовий SQL-файл.');
        $write = static function ($line) use ($fh) {
            if (fwrite($fh, $line) === false) throw new RuntimeException('Помилка запису SQL-файлу.');
        };
        try {
            $write("-- UA Backup database export\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            $tables = $wpdb->get_col('SHOW TABLES');
            if (!is_array($tables) || !$tables) throw new RuntimeException('Не вдалося отримати список таблиць БД: ' . $wpdb->last_error);
            foreach ($tables as $table) {
                $table = (string) $table;
                if ($table === '' || strpos($table, "\0") !== false) continue;
                $quoted = chr(96) . str_replace(chr(96), chr(96) . chr(96), $table) . chr(96);
                $create = $wpdb->get_row('SHOW CREATE TABLE ' . $quoted, ARRAY_N);
                if (!$create || empty($create[1])) throw new RuntimeException('Не вдалося прочитати структуру таблиці ' . $table . ': ' . $wpdb->last_error);
                $write('DROP TABLE IF EXISTS ' . $quoted . ";\n" . $create[1] . ";\n\n");
                $offset = 0;
                $limit = 250;
                do {
                    $rows = $wpdb->get_results('SELECT * FROM ' . $quoted . ' LIMIT ' . (int) $offset . ', ' . (int) $limit, ARRAY_A);
                    if ($rows === null || $wpdb->last_error) throw new RuntimeException('Помилка читання таблиці ' . $table . ': ' . $wpdb->last_error);
                    foreach ($rows as $row) {
                        $values = [];
                        foreach ($row as $value) $values[] = is_null($value) ? 'NULL' : "'" . self::sql_escape((string) $value) . "'";
                        $write('INSERT INTO ' . $quoted . ' VALUES (' . implode(',', $values) . ");\n");
                    }
                    $count = count($rows);
                    $offset += $count;
                } while ($count === $limit);
                $write("\n");
            }
            $write("SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($fh);
        }
    }

    private static function sql_escape(string $value): string {
        return str_replace(["\\", "\0", "\n", "\r", "'", '"', "\x1a"], ["\\\\", "\\0", "\\n", "\\r", "\\'", '\\"', "\\Z"], $value);
    }

    public static function delete(string $name): bool {
        $name = basename($name);
        if (!preg_match('/^ua-backup-[a-zA-Z0-9._-]+\.zip$/', $name)) return false;
        $path = self::backup_dir() . '/' . $name;
        return is_file($path) ? @unlink($path) : false;
    }

    public static function file_path(string $name): string {
        $name = basename($name);
        if (!preg_match('/^ua-backup-[a-zA-Z0-9._-]+\.zip$/', $name)) return '';
        $path = self::backup_dir() . '/' . $name;
        return is_file($path) && is_readable($path) ? $path : '';
    }

    private static function installer_code(): string {
        $path = UAB_DIR . 'includes/installer-template.php';
        return is_file($path) ? (string) file_get_contents($path) : '<?php http_response_code(404); exit;';
    }
}
