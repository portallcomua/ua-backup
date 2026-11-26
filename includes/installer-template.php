<?php
/* UA Backup standalone installer */
@set_time_limit(0);
@ini_set('memory_limit', '512M');
session_start();

$base = __DIR__;
$manifestFile = $base . '/ua-backup.json';
$manifest = is_file($manifestFile) ? json_decode((string) file_get_contents($manifestFile), true) : [];
$manifest = is_array($manifest) ? $manifest : [];
$message = '';
$error = '';
$stage = isset($_SESSION['uab_stage']) ? (string) $_SESSION['uab_stage'] : 'start';
$tokenHash = isset($manifest['installer_token_hash']) ? (string) $manifest['installer_token_hash'] : '';
$tokenOk = false;

function uab_h($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
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
function uab_import_sql($mysqli, $file) {
    $count = 0;
    foreach (uab_sql_statements($file) as $sql) {
        if (stripos($sql, 'SET NAMES') === 0 || stripos($sql, 'SET FOREIGN_KEY_CHECKS') === 0) {
            if (!$mysqli->query($sql)) uab_stop('Помилка SQL: ' . $mysqli->error);
            continue;
        }
        if (!$mysqli->query($sql)) uab_stop('Помилка імпорту бази даних: ' . $mysqli->error);
        $count++;
    }
    if ($count < 1) uab_stop('Файл бази даних порожній або не містить команд.');
}
function uab_replace_recursive($value, $old, $new) {
    if (is_string($value)) return str_replace($old, $new, $value);
    if (is_array($value)) { foreach ($value as $k => $v) $value[$k] = uab_replace_recursive($v, $old, $new); return $value; }
    if (is_object($value)) { foreach ($value as $k => $v) $value->$k = uab_replace_recursive($v, $old, $new); return $value; }
    return $value;
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
    $result = $mysqli->query("SELECT meta_id, meta_value FROM " . $meta . " WHERE meta_value LIKE '%" . $old . "%'");
    if ($result) {
        $stmt = $mysqli->prepare("UPDATE " . $meta . " SET meta_value=? WHERE meta_id=?");
        while ($row = $result->fetch_assoc()) {
            $raw = $row['meta_value'];
            $value = @unserialize($raw, ['allowed_classes' => false]);
            if ($value !== false || $raw === 'b:0;') $updated = serialize(uab_replace_recursive($value, $oldUrl, $newUrl));
            else $updated = str_replace($oldUrl, $newUrl, $raw);
            if ($updated !== $raw) { $stmt->bind_param('si', $updated, $row['meta_id']); $stmt->execute(); }
        }
        $stmt->close();
        $result->free();
    }
}
function uab_copy_site_files($source, $target) {
    if (!is_dir($source)) uab_stop('В архіві відсутня папка site-files.');
    $sourceReal = realpath($source);
    $targetReal = realpath($target);
    if (!$sourceReal || !$targetReal) uab_stop('Не вдалося визначити папки відновлення.');
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceReal, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
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
        }
    }
}
function uab_update_config($file, $dbName, $dbUser, $dbPass, $dbHost, $prefix) {
    if (!is_file($file) || !is_writable($file)) uab_stop('Файл wp-config.php відсутній або недоступний для запису.');
    $config = file_get_contents($file);
    $map = ['DB_NAME' => $dbName, 'DB_USER' => $dbUser, 'DB_PASSWORD' => $dbPass, 'DB_HOST' => $dbHost];
    foreach ($map as $key => $value) {
        $pattern = "/define\\s*\\(\\s*(['\"])" . preg_quote($key, '/') . "\\1\\s*,\\s*(['\"])(?:\\\\\\\\.|(?!\\2).)*\\2\\s*\\)\\s*;/s";
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
function uab_cleanup($base) {
    foreach (['installer.php', 'database.sql', 'ua-backup.json'] as $name) if (is_file($base . '/' . $name)) @unlink($base . '/' . $name);
    $dir = $base . '/site-files';
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) { if ($item->isDir() && !$item->isLink()) @rmdir($item->getPathname()); else @unlink($item->getPathname()); }
        @rmdir($dir);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token = (string) ($_POST['installer_token'] ?? '');
        if (!$tokenHash || !hash_equals($tokenHash, hash('sha256', $token))) uab_stop('Неправильний ключ інсталятора. Візьміть ключ у повідомленні після створення бекапа.');
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
                $_SESSION['uab_db'] = $db;
                $_SESSION['uab_stage'] = 'tested';
                $stage = 'tested';
                $message = 'Підключення успішне. База даних порожня та готова до відновлення.';
            } else {
                if (!is_file($base . '/database.sql')) uab_stop('Не знайдено database.sql. Спочатку розпакуйте повний ZIP-архів.');
                $tables = $mysqli->query('SHOW TABLES');
                if ($tables && $tables->num_rows > 0) uab_stop('База даних не порожня. Зупинено, щоб не перезаписати чужі таблиці.');
                uab_import_sql($mysqli, $base . '/database.sql');
                $oldPrefix = (string) ($manifest['table_prefix'] ?? 'wp_');
                if ($oldPrefix !== $db['prefix']) {
                    $res = $mysqli->query('SHOW TABLES');
                    $renames = [];
                    while ($row = $res->fetch_array(MYSQLI_NUM)) {
                        $oldName = (string) $row[0];
                        if (strpos($oldName, $oldPrefix) === 0) {
                            $newName = $db['prefix'] . substr($oldName, strlen($oldPrefix));
                            $renames[] = 'RENAME TABLE ' . uab_ident($oldName) . ' TO ' . uab_ident($newName);
                        }
                    }
                    foreach ($renames as $sql) if (!$mysqli->query($sql)) uab_stop('Не вдалося змінити префікс таблиць: ' . $mysqli->error);
                }
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
            $newLogin = sanitize_user((string) ($_POST['new_admin_login'] ?? ''), true);
            $newPass = (string) ($_POST['new_admin_pass'] ?? '');
            if ($newLogin !== '' || $newPass !== '') {
                if ($newLogin === '' || strlen($newPass) < 8) uab_stop('Для нового адміністратора вкажіть логін і пароль не коротший за 8 символів.');
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
            uab_copy_site_files($base . '/site-files', $base);
            uab_update_config($base . '/wp-config.php', $db['name'], $db['user'], $db['pass'], $db['host'], $db['prefix']);
            unset($_SESSION['uab_db'], $_SESSION['uab_stage']);
            uab_cleanup($base);
            $stage = 'done';
            $message = 'Готово! Сайт відновлено. Службові файли інсталятора очищено.';
        } else {
            uab_stop('Невідома дія інсталятора.');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$dbForm = $_SESSION['uab_db'] ?? [
    'host' => 'localhost', 'name' => '', 'user' => '', 'pass' => '',
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
*{box-sizing:border-box}body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f3f6fa;margin:0;padding:24px;color:#172033}.box{max-width:720px;margin:25px auto;background:#fff;padding:28px;border-radius:16px;box-shadow:0 8px 35px #15294b12}h1{margin-top:0}label{display:block;font-weight:600;margin:14px 0 6px}input,select{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:8px;font:inherit}.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.button{display:inline-block;margin-top:20px;padding:13px 20px;border:0;border-radius:8px;background:#1769d2;color:#fff;font-size:16px;font-weight:700;cursor:pointer}.msg,.err{padding:13px;border-radius:8px;margin:15px 0}.msg{background:#e9f7ef;color:#17633a}.err{background:#fff0f0;color:#a11b1b}.hint{color:#64748b;font-size:14px}.admin{padding:12px;border:1px solid #e2e8f0;border-radius:9px;margin:8px 0}.admin input{width:auto}.done a{display:inline-block;margin:8px 8px 0 0}
@media(max-width:600px){.grid{grid-template-columns:1fr}.box{padding:20px;margin:0}}
</style></head><body><main class="box"><h1>UA Backup — встановлення сайту</h1>
<?php if ($message): ?><div class="msg"><?php echo uab_h($message); ?></div><?php endif; ?>
<?php if ($error): ?><div class="err"><?php echo uab_h($error); ?></div><?php endif; ?>
<?php if ($stage !== 'done'): ?>
<form method="post">
<label>Одноразовий ключ інсталятора</label><input type="password" name="installer_token" required autocomplete="off" placeholder="Вставте ключ із повідомлення після створення бекапа">
<?php if ($stage !== 'admins'): ?>
<h2>Підключення до бази даних</h2><p class="hint">Спочатку перевіряється підключення. Відновлення дозволене лише в порожню базу даних, щоб не пошкодити наявний сайт.</p>
<div class="grid"><div><label>Хост БД</label><input name="db_host" value="<?php echo uab_h($dbForm['host']); ?>" required></div><div><label>Назва БД</label><input name="db_name" value="<?php echo uab_h($dbForm['name']); ?>" required></div><div><label>Користувач БД</label><input name="db_user" value="<?php echo uab_h($dbForm['user']); ?>" required></div><div><label>Пароль БД</label><input type="password" name="db_pass" value="<?php echo uab_h($dbForm['pass']); ?>"></div><div><label>Префікс таблиць</label><input name="db_prefix" value="<?php echo uab_h($dbForm['prefix']); ?>" required></div><div><label>Нова адреса сайту</label><input name="site_url" value="<?php echo uab_h($dbForm['url']); ?>" placeholder="https://example.com" required></div></div>
<button class="button" name="uab_action" value="test">Перевірити підключення</button>
<?php if ($stage === 'tested'): ?><button class="button" name="uab_action" value="import" onclick="return confirm('Почати відновлення бази даних?')">Відновити базу даних</button><?php endif; ?>
<?php else: ?>
<h2>Адміністратор сайта</h2><p class="hint">Виберіть адміністратора з резервної копії. Якщо пароль не змінювати, він залишиться таким, як на старому сайті.</p>
<?php foreach ($adminList as $index => $admin): ?><label class="admin"><input type="radio" name="admin_id" value="<?php echo (int) $admin['ID']; ?>" <?php echo $index === 0 ? 'checked' : ''; ?>> <?php echo uab_h($admin['user_login'] . ' — ' . $admin['user_email']); ?></label><?php endforeach; ?>
<label>Новий пароль для вибраного адміністратора (необов’язково)</label><input type="password" name="existing_admin_pass" minlength="8">
<h3>Або створити нового адміністратора</h3><label>Новий логін</label><input name="new_admin_login"><label>Новий пароль (мінімум 8 символів)</label><input type="password" name="new_admin_pass" minlength="8">
<button class="button" name="uab_action" value="finish" onclick="return confirm('Завершити відновлення сайту?')">Завершити встановлення</button>
<?php endif; ?></form>
<?php else: ?><div class="done"><h2>Сайт відновлено</h2><p>Відкрийте сайт або увійдіть до панелі керування.</p><a class="button" href="<?php echo uab_h($_SESSION['uab_done_url'] ?? '/'); ?>">Перейти на сайт</a><a class="button" href="<?php echo uab_h(rtrim((string) ($dbForm['url'] ?? ''), '/') . '/wp-admin/'); ?>">Відкрити адмінку</a></div><?php endif; ?>
</main></body></html>
