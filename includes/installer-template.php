<?php
/* UA Backup standalone installer */
@set_time_limit(0);
@ini_set('memory_limit', '512M');
$base = __DIR__;
$privateDir = $base . '/.ua-backup-private';
$manifestFile = is_file($privateDir . '/ua-backup.json') ? $privateDir . '/ua-backup.json' : $base . '/ua-backup.json';
$databaseFile = is_file($privateDir . '/database.sql') ? $privateDir . '/database.sql' : $base . '/database.sql';
$manifest = is_file($manifestFile) ? json_decode((string) file_get_contents($manifestFile), true) : [];
$manifest = is_array($manifest) ? $manifest : [];
$message = '';
$error = '';
$tokenHash = isset($manifest['installer_token_hash']) ? (string) $manifest['installer_token_hash'] : '';
$installerToken = isset($manifest['installer_token']) ? (string) $manifest['installer_token'] : '';
if ($installerToken !== '' && $tokenHash !== '' && !hash_equals($tokenHash, hash('sha256', $installerToken))) $installerToken = '';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['uab_progress'])) {
    $progressToken = (string) ($_GET['token'] ?? '');
    if (!$tokenHash || !hash_equals($tokenHash, hash('sha256', $progressToken))) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['percent' => 0, 'message' => 'Невірний ключ']);
        exit;
    }
    $progressFile = sys_get_temp_dir() . '/uab-restore-' . hash('sha256', $progressToken) . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo is_file($progressFile) ? file_get_contents($progressFile) : json_encode(['percent' => 0, 'message' => 'Підготовка відновлення']);
    exit;
}
session_start();
$stage = isset($_SESSION['uab_stage']) ? (string) $_SESSION['uab_stage'] : 'start';
$tokenOk = false;

function uab_h($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function uab_set_progress($token, $percent, $message) {
    $file = sys_get_temp_dir() . '/uab-restore-' . hash('sha256', $token) . '.json';
    file_put_contents($file, json_encode(['percent' => max(0, min(100, (int) $percent)), 'message' => (string) $message]), LOCK_EX);
}
function uab_stop($message) { throw new RuntimeException($message); }
function uab_ident($value) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) uab_stop('Некоректний префікс таблиць.');
    return chr(96) . str_replace(chr(96), chr(96) . chr(96), $value) . chr(96);
}
function uab_sql_statements($file) {
    $fh = fopen($file, 'rb');
    if (!$fh) uab_stop('Не вдалося відкрити файл бази даних.');
    $buffer = '';
    $quote = '';
    $escape = false;
    $lineComment = false;
    $blockComment = false;
    try {
        while (!feof($fh)) {
            $chunk = fread($fh, 65536);
            if ($chunk === false) uab_stop('Помилка читання SQL-файлу.');
            $len = strlen($chunk);
            for ($i = 0; $i < $len; $i++) {
                $ch = $chunk[$i];
                $next = ($i + 1 < $len) ? $chunk[$i + 1] : '';
                if ($lineComment) {
                    if ($ch === "\n") { $lineComment = false; $buffer .= "\n"; }
                    continue;
                }
                if ($blockComment) {
                    if ($ch === '*' && $next === '/') { $blockComment = false; $i++; }
                    continue;
                }
                if ($quote !== '') {
                    $buffer .= $ch;
                    if ($escape) { $escape = false; continue; }
                    if ($ch === chr(92)) { $escape = true; continue; }
                    if ($ch === $quote) {
                        if ($next === $quote) { $buffer .= $next; $i++; }
                        else $quote = '';
                    }
                    continue;
                }
                if ($ch === '-' && $next === '-' && ($i + 2 >= $len || ctype_space($chunk[$i + 2]))) { $lineComment = true; $i++; continue; }
                if ($ch === '#') { $lineComment = true; continue; }
                if ($ch === '/' && $next === '*') { $blockComment = true; $i++; continue; }
                if ($ch === "'" || $ch === '"' || $ch === chr(96)) { $quote = $ch; $buffer .= $ch; continue; }
                if ($ch === ';') {
                    $statement = trim($buffer);
                    $buffer = '';
                    if ($statement !== '') yield $statement;
                    continue;
                }
                $buffer .= $ch;
            }
        }
        $statement = trim($buffer);
        if ($statement !== '') yield $statement;
    } finally { fclose($fh); }
}
function uab_import_sql($mysqli, $file, $token) {
    uab_set_progress($token, 3, 'Аналіз SQL-файлу');
    $total = 0;
    foreach (uab_sql_statements($file) as $unused) $total++;
    if ($total < 1) uab_stop('Файл бази даних порожній або не містить команд.');
    $count = 0;
    foreach (uab_sql_statements($file) as $sql) {
        if (stripos($sql, 'SET NAMES') === 0 || stripos($sql, 'SET FOREIGN_KEY_CHECKS') === 0) {
            if (!$mysqli->query($sql)) uab_stop('Помилка SQL: ' . $mysqli->error);
            continue;
        }
        if (!$mysqli->query($sql)) uab_stop('Помилка імпорту бази даних: ' . $mysqli->error);
        $count++;
        if ($count % 10 === 0 || $count === $total) {
            $percent = 5 + (int) floor(68 * $count / $total);
            uab_set_progress($token, $percent, 'Імпорт бази даних: ' . $count . ' із ' . $total . ' SQL-команд');
        }
    }
    uab_set_progress($token, 74, 'Базу даних імпортовано');
}
function uab_replace_recursive($value, $old, $new) {
    if (is_string($value)) return str_replace($old, $new, $value);
    if (is_array($value)) { foreach ($value as $k => $v) $value[$k] = uab_replace_recursive($v, $old, $new); return $value; }
    if (is_object($value)) { foreach ($value as $k => $v) $value->$k = uab_replace_recursive($v, $old, $new); return $value; }
    return $value;
}
function uab_replace_serialized_column($mysqli, $table, $idColumn, $valueColumn, $oldUrl, $newUrl) {
    $old = $mysqli->real_escape_string($oldUrl);
    $result = $mysqli->query("SELECT " . $idColumn . ", " . $valueColumn . " FROM " . $table . " WHERE " . $valueColumn . " LIKE '%" . $old . "%'");
    if (!$result) return;
    $stmt = $mysqli->prepare("UPDATE " . $table . " SET " . $valueColumn . "=? WHERE " . $idColumn . "=?");
    if (!$stmt) { $result->free(); return; }
    while ($row = $result->fetch_assoc()) {
        $raw = (string) $row[$valueColumn];
        $value = @unserialize($raw, ['allowed_classes' => false]);
        if ($value !== false || $raw === 'b:0;') $updated = serialize(uab_replace_recursive($value, $oldUrl, $newUrl));
        else $updated = str_replace($oldUrl, $newUrl, $raw);
        if ($updated !== $raw) {
            $id = (int) $row[$idColumn];
            $stmt->bind_param('si', $updated, $id);
            $stmt->execute();
        }
    }
    $stmt->close();
    $result->free();
}
function uab_rewrite_urls($mysqli, $prefix, $oldUrl, $newUrl) {
    if (!$oldUrl || !$newUrl || $oldUrl === $newUrl) return;
    $options = uab_ident($prefix . 'options');
    $posts = uab_ident($prefix . 'posts');
    $meta = uab_ident($prefix . 'postmeta');
    $old = $mysqli->real_escape_string($oldUrl);
    $new = $mysqli->real_escape_string($newUrl);
    $mysqli->query("UPDATE " . $options . " SET option_value='" . $new . "' WHERE option_name IN ('siteurl','home')");
    $mysqli->query("UPDATE " . $posts . " SET post_content=REPLACE(post_content,'" . $old . "','" . $new . "'), guid=REPLACE(guid,'" . $old . "','" . $new . "')");
    uab_replace_serialized_column($mysqli, $options, 'option_id', 'option_value', $oldUrl, $newUrl);
    uab_replace_serialized_column($mysqli, $meta, 'meta_id', 'meta_value', $oldUrl, $newUrl);
}
function uab_remove_tree($dir) {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) {
        if ($item->isDir() && !$item->isLink()) @rmdir($item->getPathname());
        else @unlink($item->getPathname());
    }
    @rmdir($dir);
}
function uab_prepare_site_files($base, $token) {
    $privateNestedZip = $base . '/.ua-backup-private/site-files.zip';
    $nestedZip = is_file($privateNestedZip) ? $privateNestedZip : $base . '/site-files.zip';
    if (is_file($nestedZip)) {
        $target = sys_get_temp_dir() . '/uab-files-' . hash('sha256', $token);
        uab_remove_tree($target);
        if (!mkdir($target, 0700, true) && !is_dir($target)) uab_stop('Не вдалося створити тимчасову папку для файлів сайту.');
        $zip = new ZipArchive();
        if ($zip->open($nestedZip, ZipArchive::CHECKCONS) !== true) uab_stop('Внутрішній архів файлів пошкоджений.');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace(chr(92), '/', (string) $zip->getNameIndex($i));
            $parts = explode('/', $name);
            if ($name === '' || $name[0] === '/' || preg_match('/^[A-Za-z]:/', $name) || strpos($name, "\0") !== false || in_array('..', $parts, true)) {
                $zip->close();
                uab_stop('У резервній копії виявлено небезпечний шлях.');
            }
            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)) {
                $type = ($attributes >> 16) & 0170000;
                if ($type === 0120000) {
                    $zip->close();
                    uab_stop('Резервна копія містить символічне посилання. Відновлення зупинено.');
                }
            }
        }
        if (!$zip->extractTo($target)) {
            $zip->close();
            uab_stop('Не вдалося розпакувати файли сайту.');
        }
        $zip->close();
        return $target;
    }
    $legacy = $base . '/site-files';
    if (is_dir($legacy)) return $legacy;
    uab_stop('В архіві відсутній внутрішній архів файлів сайту.');
}
function uab_copy_site_files($source, $target, $token) {
    if (!is_dir($source)) uab_stop('В архіві відсутня папка site-files.');
    $sourceReal = realpath($source);
    $targetReal = realpath($target);
    if (!$sourceReal || !$targetReal) uab_stop('Не вдалося визначити папки відновлення.');
    $counter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceReal, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
    $totalFiles = 0;
    foreach ($counter as $candidate) if ($candidate->isFile() && !$candidate->isLink()) $totalFiles++;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceReal, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    $copiedFiles = 0;
    foreach ($it as $item) {
        if ($item->isLink()) continue;
        $relative = substr($item->getPathname(), strlen($sourceReal) + 1);
        $relative = str_replace(chr(92), '/', $relative);
        $parts = explode('/', $relative);
        if (in_array('..', $parts, true) || $relative === '') uab_stop('Виявлено небезпечний шлях у резервній копії.');
        $dest = $targetReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if ($item->isDir()) {
            if (!is_dir($dest) && !mkdir($dest, 0755, true) && !is_dir($dest)) uab_stop('Не вдалося створити папку: ' . $relative);
        } else {
            if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0755, true) && !is_dir(dirname($dest))) uab_stop('Не вдалося створити папку для файлу.');
            if (!copy($item->getPathname(), $dest)) uab_stop('Не вдалося відновити файл: ' . $relative);
            $copiedFiles++;
            if ($copiedFiles % 20 === 0 || $copiedFiles === $totalFiles) {
                $percent = $totalFiles > 0 ? 80 + (int) floor(18 * $copiedFiles / $totalFiles) : 98;
                uab_set_progress($token, $percent, 'Відновлення файлів: ' . $copiedFiles . ' із ' . $totalFiles);
            }
        }
    }
}
function uab_update_config($file, $dbName, $dbUser, $dbPass, $dbHost, $prefix) {
    if (!is_file($file) || !is_writable($file)) uab_stop('Файл wp-config.php відсутній або недоступний для запису.');
    $config = file_get_contents($file);
    $map = ['DB_NAME' => $dbName, 'DB_USER' => $dbUser, 'DB_PASSWORD' => $dbPass, 'DB_HOST' => $dbHost];
    foreach ($map as $key => $value) {
        $pattern = "/define\\s*\\(\\s*(['\"])". preg_quote($key, '/') . "\\1\\s*,.*?\\)\\s*;/s";
        $replacement = "define(" . var_export($key, true) . ", " . var_export($value, true) . ");";
        $changed = preg_replace($pattern, $replacement, $config, 1, $replacements);
        if ($replacements < 1) uab_stop('Не вдалося оновити параметр ' . $key . ' у wp-config.php.');
        $config = $changed;
    }
    $prefixPattern = '/\\$table_prefix\\s*=\\s*([\'"])[^\'"]*\\1\\s*;/';
    $config = preg_replace($prefixPattern, '$table_prefix = ' . var_export($prefix, true) . ';', $config, 1, $replacements);
    if ($replacements < 1) uab_stop('Не вдалося оновити префікс таблиць у wp-config.php.');
    $tmp = $file . '.uab-tmp';
    if (file_put_contents($tmp, $config, LOCK_EX) === false || !rename($tmp, $file)) { @unlink($tmp); uab_stop('Не вдалося безпечно зберегти wp-config.php.'); }
}
function uab_admins($mysqli, $prefix) {
    $users = uab_ident($prefix . 'users');
    $meta = uab_ident($prefix . 'usermeta');
    $key = $mysqli->real_escape_string($prefix . 'capabilities');
    $sql = "SELECT u.ID,u.user_login,u.user_email FROM " . $users . " u INNER JOIN " . $meta . " m ON m.user_id=u.ID WHERE m.meta_key='" . $key . "' AND m.meta_value LIKE '%administrator%' ORDER BY u.ID";
    $result = $mysqli->query($sql);
    if (!$result) uab_stop('Не вдалося отримати адміністраторів: ' . $mysqli->error);
    $items = [];
    while ($row = $result->fetch_assoc()) $items[] = $row;
    $result->free();
    return $items;
}
function uab_cleanup($base, $token) {
    foreach (['installer.php', 'database.sql', 'ua-backup.json', 'site-files.zip'] as $name) if (is_file($base . '/' . $name)) @unlink($base . '/' . $name);
    $dir = $base . '/site-files';
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) { if ($item->isDir() && !$item->isLink()) @rmdir($item->getPathname()); else @unlink($item->getPathname()); }
        @rmdir($dir);
    }
    uab_remove_tree($base . '/.ua-backup-private');
    uab_remove_tree(sys_get_temp_dir() . '/uab-files-' . hash('sha256', $token));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token = (string) ($_POST['installer_token'] ?? '');
        if (!$tokenHash || $installerToken === '' || !hash_equals($tokenHash, hash('sha256', $token)) || !hash_equals($tokenHash, hash('sha256', $installerToken))) uab_stop('Не вдалося автоматично перевірити службові дані архіву. Переконайтеся, що розпаковано повний ZIP UA Backup.');
        $tokenOk = true;
        $action = (string) ($_POST['uab_action'] ?? '');
        if ($action === 'test' || $action === 'import') {
            $db = [
                'host' => trim((string) ($_POST['db_host'] ?? 'localhost')),
                'name' => trim((string) ($_POST['db_name'] ?? '')),
                'user' => trim((string) ($_POST['db_user'] ?? '')),
                'pass' => (string) ($_POST['db_pass'] ?? ''),
                'prefix' => trim((string) ($_POST['db_prefix'] ?? ($manifest['table_prefix'] ?? 'wp_'))),
                'url' => rtrim(trim((string) ($_POST['site_url'] ?? '')), '/'),
            ];
            if ($db['host'] === '' || $db['name'] === '' || $db['user'] === '' || $db['url'] === '') uab_stop('Неправильно вказані дані або не всі поля заповнені.');
            uab_ident($db['prefix']);
            if (!filter_var($db['url'], FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $db['url'])) uab_stop('Вкажіть коректну адресу сайту з http:// або https://.');
            $mysqli = @new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
            if ($mysqli->connect_errno) uab_stop('Помилка підключення до БД: ' . $mysqli->connect_error);
            $mysqli->set_charset('utf8mb4');
            if ($action === 'test') {
                $tables = $mysqli->query('SHOW TABLES');
                if ($tables && $tables->num_rows > 0) uab_stop('У вибраній базі вже є таблиці. Для безпечного відновлення використайте порожню базу даних.');
                // Не зберігаємо пароль після перевірки: поле на наступному кроці має бути порожнім.
                $sessionDb = $db;
                $sessionDb['pass'] = '';
                $_SESSION['uab_db'] = $sessionDb;
                $_SESSION['uab_stage'] = 'tested';
                $stage = 'tested';
                $message = 'Підключення успішне. База даних порожня та готова до відновлення.';
            } else {
                if (!is_file($databaseFile)) uab_stop('Не знайдено файл бази даних. Спочатку розпакуйте повний ZIP-архів.');
                $tables = $mysqli->query('SHOW TABLES');
                if ($tables && $tables->num_rows > 0) uab_stop('База даних не порожня. Зупинено, щоб не перезаписати чужі таблиці.');
                try {
                    session_write_close();
                uab_import_sql($mysqli, $databaseFile, $token);
                } catch (Throwable $importError) {
                    $cleanupPrefixes = array_unique([(string) ($manifest['table_prefix'] ?? 'wp_'), $db['prefix']]);
                    $existing = $mysqli->query('SHOW TABLES');
                    if ($existing) {
                        while ($tableRow = $existing->fetch_array(MYSQLI_NUM)) {
                            $tableName = (string) $tableRow[0];
                            foreach ($cleanupPrefixes as $cleanupPrefix) {
                                if ($cleanupPrefix !== '' && strpos($tableName, $cleanupPrefix) === 0) {
                                    $mysqli->query('DROP TABLE IF EXISTS ' . uab_ident($tableName));
                                    break;
                                }
                            }
                        }
                    }
                    throw $importError;
                }
                $oldPrefix = (string) ($manifest['table_prefix'] ?? 'wp_');
                if ($oldPrefix !== $db['prefix']) {
                    $res = $mysqli->query('SHOW TABLES');
                    $renames = [];
                    while ($row = $res->fetch_array(MYSQLI_NUM)) {
                        $oldName = (string) $row[0];
                        if (strpos($oldName, $oldPrefix) === 0) {
                            $newName = $db['prefix'] . substr($oldName, strlen($oldPrefix));
                            $renames[] = uab_ident($oldName) . ' TO ' . uab_ident($newName);
                        }
                    }
                    if ($renames && !$mysqli->query('RENAME TABLE ' . implode(', ', $renames))) uab_stop('Не вдалося змінити префікс таблиць: ' . $mysqli->error);
                    $metaTable = uab_ident($db['prefix'] . 'usermeta');
                    $oldPrefixEsc = $mysqli->real_escape_string($oldPrefix);
                    $newPrefixEsc = $mysqli->real_escape_string($db['prefix']);
                    $prefixLength = strlen($oldPrefix);
                    $metaUpdate = "UPDATE " . $metaTable . " SET meta_key=CONCAT('" . $newPrefixEsc . "',SUBSTRING(meta_key," . ($prefixLength + 1) . ")) WHERE LEFT(meta_key," . $prefixLength . ")='" . $oldPrefixEsc . "'";
                    if (!$mysqli->query($metaUpdate)) uab_stop('Не вдалося оновити ключі прав користувачів: ' . $mysqli->error);
                    $optionsTable = uab_ident($db['prefix'] . 'options');
                    $oldRoles = $mysqli->real_escape_string($oldPrefix . 'user_roles');
                    $newRoles = $mysqli->real_escape_string($db['prefix'] . 'user_roles');
                    if (!$mysqli->query("UPDATE " . $optionsTable . " SET option_name='" . $newRoles . "' WHERE option_name='" . $oldRoles . "'")) uab_stop('Не вдалося оновити налаштування ролей: ' . $mysqli->error);
                }
                session_start();
                // Пароль потрібен далі для повторного підключення та запису wp-config.php.
                // Він зберігається лише в сесії сервера і ніколи не повертається у HTML-поле.
                $_SESSION['uab_db'] = $db;
                $_SESSION['uab_stage'] = 'admins';
                $stage = 'admins';
                $message = 'Базу даних відновлено. Тепер виберіть адміністратора сайту.';
            }
            $mysqli->close();
        } elseif ($action === 'finish') {
            $db = $_SESSION['uab_db'] ?? null;
            if (!is_array($db) || ($_SESSION['uab_stage'] ?? '') !== 'admins') uab_stop('Сесію відновлення втрачено. Почніть встановлення знову.');
            $mysqli = @new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
            if ($mysqli->connect_errno) uab_stop('Не вдалося повторно підключитися до бази даних: ' . $mysqli->connect_error);
            $mysqli->set_charset('utf8mb4');
            $admins = uab_admins($mysqli, $db['prefix']);
            $selected = (int) ($_POST['admin_id'] ?? 0);
            $newLogin = preg_replace('/[^a-zA-Z0-9_.@-]/', '', trim((string) ($_POST['new_admin_login'] ?? '')));
            $newPass = (string) ($_POST['new_admin_pass'] ?? '');
            $createNewAdmin = !$admins || (string) ($_POST['create_new_admin'] ?? '') === '1';
            if ($createNewAdmin) {
                if ($newLogin === '' || strlen($newPass) < 8) uab_stop('Для створення нового адміністратора вкажіть логін і пароль не коротший за 8 символів.');
                $users = uab_ident($db['prefix'] . 'users');
                $meta = uab_ident($db['prefix'] . 'usermeta');
                $loginEsc = $mysqli->real_escape_string($newLogin);
                $exists = $mysqli->query("SELECT ID FROM " . $users . " WHERE user_login='" . $loginEsc . "' LIMIT 1");
                if ($exists && $exists->num_rows) uab_stop('Такий логін уже існує. Виберіть іншого.');
                $email = 'admin-' . substr(hash('sha256', $newLogin), 0, 12) . '@example.invalid';
                $hash = password_hash($newPass, PASSWORD_BCRYPT);
                $stmt = $mysqli->prepare("INSERT INTO " . $users . " (user_login,user_pass,user_nicename,user_email,user_registered,display_name) VALUES (?,?,?,?,NOW(),?)");
                $nice = $newLogin; $display = $newLogin;
                $stmt->bind_param('sssss', $newLogin, $hash, $nice, $email, $display);
                if (!$stmt->execute()) uab_stop('Не вдалося створити адміністратора: ' . $stmt->error);
                $newId = $stmt->insert_id; $stmt->close();
                $capKey = $db['prefix'] . 'capabilities'; $capValue = serialize(['administrator' => true]);
                $levelKey = $db['prefix'] . 'user_level'; $levelValue = '10';
                $stmt = $mysqli->prepare("INSERT INTO " . $meta . " (user_id,meta_key,meta_value) VALUES (?,?,?),(?,?,?)");
                $stmt->bind_param('ississ', $newId, $capKey, $capValue, $newId, $levelKey, $levelValue);
                if (!$stmt->execute()) uab_stop('Не вдалося призначити права адміністратора: ' . $stmt->error);
                $stmt->close();
            } else {
                $valid = false;
                foreach ($admins as $admin) if ((int) $admin['ID'] === $selected) $valid = true;
                if (!$valid) uab_stop('Виберіть адміністратора зі списку або створіть нового.');
                $newPassForOld = (string) ($_POST['existing_admin_pass'] ?? '');
                if ($newPassForOld !== '') {
                    if (strlen($newPassForOld) < 8) uab_stop('Новий пароль має містити щонайменше 8 символів.');
                    $users = uab_ident($db['prefix'] . 'users');
                    $hash = password_hash($newPassForOld, PASSWORD_BCRYPT);
                    $stmt = $mysqli->prepare("UPDATE " . $users . " SET user_pass=? WHERE ID=?");
                    $stmt->bind_param('si', $hash, $selected);
                    if (!$stmt->execute()) uab_stop('Не вдалося змінити пароль адміністратора.');
                    $stmt->close();
                }
            }
            uab_rewrite_urls($mysqli, $db['prefix'], rtrim((string) ($manifest['home_url'] ?? $manifest['site_url'] ?? ''), '/'), $db['url']);
            $mysqli->close();
            uab_set_progress($token, 79, 'Підготовка файлів сайту');
            $restoreFiles = uab_prepare_site_files($base, $token);
            uab_copy_site_files($restoreFiles, $base, $token);
            uab_set_progress($token, 99, 'Оновлення конфігурації WordPress');
            uab_update_config($base . '/wp-config.php', $db['name'], $db['user'], $db['pass'], $db['host'], $db['prefix']);
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            unset($_SESSION['uab_db'], $_SESSION['uab_stage']);
            $_SESSION['uab_done_url'] = $db['url'];
            uab_cleanup($base, $token);
            uab_set_progress($token, 100, 'Відновлення завершено успішно');
            $stage = 'done';
            $message = 'Готово! Сайт відновлено. Службові файли інсталятора очищено.';
        } else {
            uab_stop('Невідома дія інсталятора.');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if (!empty($token)) {
            uab_set_progress($token, 0, 'Помилка: ' . $error);
            uab_remove_tree(sys_get_temp_dir() . '/uab-files-' . hash('sha256', $token));
        }
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    }
}

$dbForm = $_SESSION['uab_db'] ?? [
    'host' => 'localhost', 'name' => '', 'user' => 'root', 'pass' => '',
    'prefix' => (string) ($manifest['table_prefix'] ?? 'wp_'), 'url' => '',
];
$adminList = [];
if ($stage === 'admins' && isset($_SESSION['uab_db'])) {
    $d = $_SESSION['uab_db'];
    $m = @new mysqli($d['host'], $d['user'], $d['pass'], $d['name']);
    if (!$m->connect_errno) { $m->set_charset('utf8mb4'); try { $adminList = uab_admins($m, $d['prefix']); } catch (Throwable $e) { $error = $e->getMessage(); } $m->close(); }
}
?><!doctype html>
<html lang="uk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>UA Backup — встановлення</title>
<style>
*{box-sizing:border-box}body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f3f6fa;margin:0;padding:24px;color:#172033}.box{max-width:720px;margin:25px auto;background:#fff;padding:28px;border-radius:16px;box-shadow:0 8px 35px #15294b12}h1{margin-top:0}label{display:block;font-weight:600;margin:14px 0 6px}input,select{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:8px;font:inherit}.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.button{display:inline-block;margin-top:20px;padding:13px 20px;border:0;border-radius:8px;background:#1769d2;color:#fff;font-size:16px;font-weight:700;cursor:pointer}.msg,.err{padding:13px;border-radius:8px;margin:15px 0}.msg{background:#e9f7ef;color:#17633a}.err{background:#fff0f0;color:#a11b1b}.hint{color:#64748b;font-size:14px}.admin{padding:12px;border:1px solid #e2e8f0;border-radius:9px;margin:8px 0}.admin input{width:auto}.admin-create{margin-top:22px;padding:16px;border:1px solid #e2e8f0;border-radius:10px}.admin-create-heading{display:flex;align-items:center;justify-content:space-between;gap:12px}.plus-toggle{width:38px;height:38px;border:1px solid #cbd5e1;border-radius:8px;background:#f8fafc;color:#172033;font-size:24px;line-height:1;cursor:pointer}.password-field{display:flex;gap:8px;align-items:center}.password-field input{flex:1;min-width:0}.toggle-password{padding:10px 13px;border:1px solid #cbd5e1;border-radius:8px;background:#f8fafc;color:#172033;font:inherit;cursor:pointer}.done a{display:inline-block;margin:8px 8px 0 0}
@media(max-width:600px){.grid{grid-template-columns:1fr}.box{padding:20px;margin:0}}
</style></head><body><main class="box"><h1>UA Backup — встановлення сайту</h1>
<?php if ($message): ?><div class="msg"><?php echo uab_h($message); ?></div><?php endif; ?>
<?php if ($error): ?><div class="err"><?php echo uab_h($error); ?></div><?php endif; ?>
<?php if ($stage !== 'done'): ?>
<div id="uab-restore-progress" style="display:none;margin:18px 0;padding:14px;background:#f1f5f9;border-radius:10px">
<div style="height:18px;background:#dbe3ee;border-radius:20px;overflow:hidden"><div id="uab-restore-bar" style="height:100%;width:0;background:#1769d2;transition:width .25s"></div></div>
<p id="uab-restore-label" class="hint">Підготовка відновлення...</p></div>
<form method="post">
<input type="hidden" name="installer_token" value="<?php echo uab_h($installerToken); ?>">
<?php if ($stage !== 'admins'): ?>
<h2>Підключення до бази даних</h2><p class="hint">Спочатку перевіряється підключення. Відновлення дозволене лише в порожню базу даних, щоб не пошкодити наявний сайт.</p>
<div class="grid"><div><label>Хост БД</label><input name="db_host" value="<?php echo uab_h($dbForm['host']); ?>" required></div><div><label>Назва БД</label><input name="db_name" value="<?php echo uab_h($dbForm['name']); ?>" required></div><div><label>Користувач БД</label><input name="db_user" value="<?php echo uab_h($dbForm['user']); ?>" required></div><div><label>Пароль БД</label><input type="password" name="db_pass" value="" autocomplete="new-password" autocapitalize="off" spellcheck="false"></div><div><label>Префікс таблиць</label><input name="db_prefix" value="<?php echo uab_h($dbForm['prefix']); ?>" required></div><div><label>Нова адреса сайту</label><input name="site_url" value="<?php echo uab_h($dbForm['url']); ?>" placeholder="https://example.com" required></div></div>
<button class="button" name="uab_action" value="test">Перевірити підключення</button>
<?php if ($stage === 'tested'): ?><button class="button" name="uab_action" value="import" onclick="return confirm('Почати відновлення бази даних?')">Відновити базу даних</button><?php endif; ?>
<?php else: ?>
<h2>Адміністратор сайта</h2><p class="hint">За замовчуванням буде використано адміністратора з резервної копії. Зміну пароля та створення додаткового адміністратора можна пропустити.</p>
<?php if ($adminList): ?>
<p class="hint">Виберіть наявного адміністратора. Якщо залишити пароль нижче порожнім, його старий пароль збережеться.</p>
<?php foreach ($adminList as $index => $admin): ?><label class="admin"><input type="radio" name="admin_id" value="<?php echo (int) $admin['ID']; ?>" <?php echo $index === 0 ? 'checked' : ''; ?> required> <?php echo uab_h($admin['user_login'] . ' — ' . $admin['user_email']); ?></label><?php endforeach; ?>
<label for="existing_admin_pass">Змінити пароль вибраного адміністратора (необов’язково)</label><div class="password-field"><input id="existing_admin_pass" type="password" name="existing_admin_pass" minlength="8" autocomplete="new-password"><button type="button" class="toggle-password" aria-label="Показати пароль" aria-pressed="false">👁</button></div>
<?php else: ?><p class="hint">У резервній копії адміністраторів не знайдено. Для входу потрібно створити нового адміністратора.</p><?php endif; ?>
<div class="admin-create">
<div class="admin-create-heading"><strong>Створити нового адміністратора</strong><button type="button" id="toggle-new-admin" class="plus-toggle" aria-expanded="<?php echo $adminList ? 'false' : 'true'; ?>" aria-controls="new-admin-fields"><?php echo $adminList ? '+' : '−'; ?></button></div>
<input type="hidden" id="create_new_admin" name="create_new_admin" value="<?php echo $adminList ? '0' : '1'; ?>">
<div id="new-admin-fields" <?php echo $adminList ? 'hidden' : ''; ?>>
<p class="hint">Заповніть ці поля лише якщо потрібен окремий обліковий запис адміністратора.</p>
<label for="new_admin_login">Логін нового адміністратора</label><input id="new_admin_login" name="new_admin_login" autocomplete="off" <?php echo $adminList ? 'disabled' : 'required'; ?>>
<label for="new_admin_pass">Пароль нового адміністратора (мінімум 8 символів)</label><div class="password-field"><input id="new_admin_pass" type="password" name="new_admin_pass" minlength="8" autocomplete="new-password" <?php echo $adminList ? 'disabled' : 'required'; ?>><button type="button" class="toggle-password" aria-label="Показати пароль" aria-pressed="false">👁</button></div>
</div></div>
<button class="button" name="uab_action" value="finish" onclick="return confirm('Завершити відновлення сайту?')">Завершити встановлення</button>
<?php endif; ?></form>
<?php else: ?><div class="done"><h2>Сайт відновлено</h2><p>Відкрийте сайт або увійдіть до панелі керування.</p><a class="button" href="<?php echo uab_h($_SESSION['uab_done_url'] ?? '/'); ?>">Перейти на сайт</a><a class="button" href="<?php echo uab_h(rtrim((string) ($_SESSION['uab_done_url'] ?? ''), '/') . '/wp-admin/'); ?>">Відкрити адмінку</a></div><?php endif; ?>
</main>
<script>
(function(){
 const form=document.querySelector('form');
 if(!form)return;
 const createToggle=document.getElementById('create_new_admin');
 const newFields=document.getElementById('new-admin-fields');
 const toggleNewAdmin=document.getElementById('toggle-new-admin');
 const newLogin=document.getElementById('new_admin_login');
 const newPass=document.getElementById('new_admin_pass');
 if(createToggle&&newFields&&toggleNewAdmin){
  toggleNewAdmin.addEventListener('click',()=>{
   const enabled=createToggle.value!=='1';
   createToggle.value=enabled?'1':'0';
   newFields.hidden=!enabled;
   toggleNewAdmin.textContent=enabled?'−':'+';
   toggleNewAdmin.setAttribute('aria-expanded',enabled?'true':'false');
   if(newLogin){newLogin.disabled=!enabled;newLogin.required=enabled;}
   if(newPass){newPass.disabled=!enabled;newPass.required=enabled;}
  });
 }
 form.querySelectorAll('.toggle-password').forEach(button=>{
  button.addEventListener('click',()=>{
   const input=button.parentElement.querySelector('input');
   if(!input)return;
   const show=input.type==='password';
   input.type=show?'text':'password';
   button.textContent=show?'🙈':'👁';
   button.setAttribute('aria-label',show?'Приховати пароль':'Показати пароль');
   button.setAttribute('aria-pressed',show?'true':'false');
  });
 });
 form.addEventListener('submit',async function(event){
  const action=event.submitter?event.submitter.value:'';
  if(action!=='import'&&action!=='finish')return;
  event.preventDefault();
  const token=form.querySelector('[name="installer_token"]').value;
  const panel=document.getElementById('uab-restore-progress');
  const bar=document.getElementById('uab-restore-bar');
  const label=document.getElementById('uab-restore-label');
  panel.style.display='block';
  let running=true;
  const poll=(async function(){
   while(running){
    try{
     const response=await fetch('?uab_progress=1&token='+encodeURIComponent(token),{cache:'no-store'});
     const data=await response.json();
     bar.style.width=(data.percent||0)+'%';
     label.textContent=(data.percent||0)+'% — '+(data.message||'');
    }catch(e){}
    await new Promise(resolve=>setTimeout(resolve,500));
   }
  })();
  try{
   const payload=new FormData(form);
   // FormData(form) не додає кнопку submit, тому сервер не отримував uab_action
   // і помилково показував «Невідома дія інсталятора».
   if(event.submitter && event.submitter.name){
    payload.set(event.submitter.name,event.submitter.value);
   }
   const response=await fetch(location.href,{method:'POST',body:payload});
   const html=await response.text();
   running=false;
   document.open();document.write(html);document.close();
  }catch(e){
   running=false;
   label.textContent='Помилка з’єднання. Перевірте стан бази даних перед повторною спробою.';
  }
 });
})();
</script></body></html>
