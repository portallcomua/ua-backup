<?php
/* UA Backup Installer 1.0.0 */
@set_time_limit(0);
$base = __DIR__;
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['db_host'] ?? 'localhost');
    $name = trim($_POST['db_name'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = (string)($_POST['db_pass'] ?? '');
    $url  = rtrim(trim($_POST['site_url'] ?? ''), '/');
    try {
        if (!$name || !$user || !$url) throw new Exception('Заповніть обов’язкові поля.');
        if (!is_dir($base . '/site-files') || !is_file($base . '/database.sql')) throw new Exception('Поруч немає site-files або database.sql.');
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base . '/site-files', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            $target = $base . '/' . substr($item->getPathname(), strlen($base . '/site-files/'));
            if ($item->isDir()) { if (!is_dir($target)) mkdir($target, 0755, true); }
            else { if (!is_dir(dirname($target))) mkdir(dirname($target), 0755, true); copy($item->getPathname(), $target); }
        }
        $mysqli = @new mysqli($host, $user, $pass, $name);
        if ($mysqli->connect_errno) throw new Exception('Помилка підключення до БД: ' . $mysqli->connect_error);
        $mysqli->set_charset('utf8mb4');
        $sql = file_get_contents($base . '/database.sql');
        if (!$mysqli->multi_query($sql)) throw new Exception('Помилка імпорту БД: ' . $mysqli->error);
        do { if ($res = $mysqli->store_result()) $res->free(); } while ($mysqli->more_results() && $mysqli->next_result());
        $manifest = json_decode(file_get_contents($base . '/ua-backup.json'), true) ?: [];
        $old = rtrim($manifest['home_url'] ?? $manifest['site_url'] ?? '', '/');
        $prefix = $manifest['table_prefix'] ?? 'wp_';
        if ($old && $old !== $url) {
            $oldEsc = $mysqli->real_escape_string($old); $newEsc = $mysqli->real_escape_string($url);
            foreach ([$prefix.'options', $prefix.'posts', $prefix.'postmeta'] as $table) {
                $check = $mysqli->query("SHOW TABLES LIKE '".$mysqli->real_escape_string($table)."'");
                if (!$check || !$check->num_rows) continue;
                if ($table === $prefix.'options') $mysqli->query("UPDATE `$table` SET option_value=REPLACE(option_value,'$oldEsc','$newEsc')");
                elseif ($table === $prefix.'posts') { $mysqli->query("UPDATE `$table` SET post_content=REPLACE(post_content,'$oldEsc','$newEsc'), guid=REPLACE(guid,'$oldEsc','$newEsc')"); }
                else $mysqli->query("UPDATE `$table` SET meta_value=REPLACE(meta_value,'$oldEsc','$newEsc')");
            }
            $mysqli->query("UPDATE `{$prefix}options` SET option_value='".$mysqli->real_escape_string($url)."' WHERE option_name IN ('siteurl','home')");
        }
        $config = $base . '/wp-config.php';
        if (is_file($config)) {
            $c = file_get_contents($config);
            $map = ['DB_NAME'=>$name,'DB_USER'=>$user,'DB_PASSWORD'=>$pass,'DB_HOST'=>$host];
            foreach ($map as $k=>$v) $c = preg_replace("/define\(\s*['\"]".$k."['\"]\s*,\s*['\"].*?['\"]\s*\);/", "define('".$k."', '".addslashes($v)."');", $c);
            file_put_contents($config, $c);
        }
        $message = 'Готово. Сайт відновлено. Видаліть installer.php, database.sql, ua-backup.json і папку site-files.';
    } catch (Throwable $e) { $message = 'Помилка: ' . $e->getMessage(); }
}
?><!doctype html><html lang="uk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>UA Backup Installer</title><style>body{font-family:system-ui;background:#f5f7fb;margin:0;padding:30px}.box{max-width:650px;margin:auto;background:#fff;padding:28px;border-radius:14px;box-shadow:0 8px 30px #0001}label{display:block;margin:14px 0 5px}input{width:100%;padding:11px;box-sizing:border-box}button{margin-top:18px;padding:12px 20px;background:#1677ff;color:#fff;border:0;border-radius:8px;font-weight:700}.msg{padding:12px;background:#eef6ff;margin-bottom:15px}</style></head><body><div class="box"><h1>UA Backup Installer</h1><?php if($message):?><div class="msg"><?php echo htmlspecialchars($message,ENT_QUOTES,'UTF-8');?></div><?php endif;?><form method="post"><label>Нова адреса сайта</label><input name="site_url" placeholder="https://example.com" required><label>Хост бази</label><input name="db_host" value="localhost" required><label>Назва бази</label><input name="db_name" required><label>Користувач бази</label><input name="db_user" required><label>Пароль бази</label><input name="db_pass" type="password"><button>Відновити сайт</button></form></div></body></html>
