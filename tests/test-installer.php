<?php
error_reporting(E_ALL);
$host = getenv('UAB_TEST_DB_HOST') ?: '127.0.0.1';
$dbName = getenv('UAB_TEST_DB_NAME') ?: 'uab_test';
$dbUser = getenv('UAB_TEST_DB_USER') ?: 'root';
$dbPass = getenv('UAB_TEST_DB_PASS') ?: 'root';
$port = random_int(18000, 24000);
$root = sys_get_temp_dir() . '/uab-installer-' . bin2hex(random_bytes(5));
mkdir($root, 0777, true);
mkdir($root . '/.ua-backup-private', 0777, true);
mkdir($root . '/fixture-files/wp-admin', 0777, true);
$token = bin2hex(random_bytes(16));
$oldUrl = 'https://old.example';
$newUrl = 'https://new.example';
$serialized = serialize(['url' => $oldUrl, 'nested' => ['url' => $oldUrl]]);

$manifest = [
    'plugin' => 'UA Backup',
    'version' => '2.1.0',
    'home_url' => $oldUrl,
    'site_url' => $oldUrl,
    'table_prefix' => 'wp_',
    'installer_token_hash' => hash('sha256', $token),
    'installer_token' => $token,
];
file_put_contents($root . '/.ua-backup-private/ua-backup.json', json_encode($manifest));
file_put_contents($root . '/.ua-backup-private/database.sql', "
CREATE TABLE wp_options (option_id bigint unsigned NOT NULL AUTO_INCREMENT, option_name varchar(191) NOT NULL, option_value longtext NOT NULL, PRIMARY KEY (option_id), UNIQUE KEY option_name (option_name));
INSERT INTO wp_options (option_name,option_value) VALUES ('siteurl','https://old.example'),('home','https://old.example'),('widget_test','" . addslashes(serialize(['link' => $oldUrl])) . "'),('wp_user_roles','a:1:{s:13:" . chr(34) . "administrator" . chr(34) . ";b:1;}');
CREATE TABLE wp_users (ID bigint unsigned NOT NULL AUTO_INCREMENT, user_login varchar(60) NOT NULL, user_pass varchar(255) NOT NULL, user_nicename varchar(50) NOT NULL, user_email varchar(100) NOT NULL, user_url varchar(100) NOT NULL DEFAULT '', user_registered datetime NOT NULL, user_activation_key varchar(255) NOT NULL DEFAULT '', user_status int NOT NULL DEFAULT 0, display_name varchar(250) NOT NULL, PRIMARY KEY (ID), KEY user_login (user_login));
INSERT INTO wp_users (ID,user_login,user_pass,user_nicename,user_email,user_registered,display_name) VALUES (1,'admin','" . password_hash('original-password', PASSWORD_BCRYPT) . "','admin','admin@old.example',NOW(),'Admin');
CREATE TABLE wp_usermeta (umeta_id bigint unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned NOT NULL, meta_key varchar(255) DEFAULT NULL, meta_value longtext, PRIMARY KEY (umeta_id), KEY user_id (user_id));
INSERT INTO wp_usermeta (user_id,meta_key,meta_value) VALUES (1,'wp_capabilities','a:1:{s:13:" . chr(34) . "administrator" . chr(34) . ";b:1;}'),(1,'wp_user_level','10');
CREATE TABLE wp_posts (ID bigint unsigned NOT NULL AUTO_INCREMENT, post_content longtext NOT NULL, guid varchar(255) NOT NULL, PRIMARY KEY (ID));
INSERT INTO wp_posts (post_content,guid) VALUES ('See https://old.example/page','https://old.example/?p=1');
CREATE TABLE wp_postmeta (meta_id bigint unsigned NOT NULL AUTO_INCREMENT, post_id bigint unsigned NOT NULL, meta_key varchar(255) DEFAULT NULL, meta_value longtext, PRIMARY KEY (meta_id), KEY post_id (post_id));
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES (1,'serialized','" . addslashes($serialized) . "');
");
$config = "<?php\n";
$config .= "define('DB_NAME', 'old');\n";
$config .= "define('DB_USER', 'old');\n";
$config .= "define('DB_PASSWORD', 'old');\n";
$config .= "define('DB_HOST', 'localhost');\n";
$config .= "\$table_prefix = 'wp_';\n";
file_put_contents($root . '/fixture-files/wp-config.php', $config);
file_put_contents($root . '/fixture-files/index.php', '<?php echo "restored";');
file_put_contents($root . '/fixture-files/wp-admin/index.php', '<?php echo "admin";');
$payloadZip = new ZipArchive();
if ($payloadZip->open($root . '/.ua-backup-private/site-files.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not create nested site files archive.');
$payloadZip->addFile($root . '/fixture-files/wp-config.php', 'wp-config.php');
$payloadZip->addFile($root . '/fixture-files/index.php', 'index.php');
$payloadZip->addFile($root . '/fixture-files/wp-admin/index.php', 'wp-admin/index.php');
$payloadZip->close();
unlink($root . '/fixture-files/wp-config.php');
unlink($root . '/fixture-files/index.php');
unlink($root . '/fixture-files/wp-admin/index.php');
rmdir($root . '/fixture-files/wp-admin');
rmdir($root . '/fixture-files');
copy(dirname(__DIR__) . '/includes/installer-template.php', $root . '/installer.php');

$mysqli = new mysqli($host, $dbUser, $dbPass, $dbName);
if ($mysqli->connect_errno) throw new RuntimeException('Test database connection failed: ' . $mysqli->connect_error);
$mysqli->query('SET FOREIGN_KEY_CHECKS=0');
foreach (['wp_postmeta','wp_posts','wp_usermeta','wp_users','wp_options'] as $table) $mysqli->query('DROP TABLE IF EXISTS ' . $table);
$mysqli->query('SET FOREIGN_KEY_CHECKS=1');
$mysqli->close();

$command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root];
$descriptors = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
$process = proc_open($command, $descriptors, $pipes);
if (!is_resource($process)) throw new RuntimeException('Could not start PHP test server.');
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
$cookie = $root . '/cookies.txt';
$baseUrl = 'http://127.0.0.1:' . $port . '/installer.php';
$send = function ($url, $fields = null) use ($cookie) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    if ($fields !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $body = curl_exec($ch);
    if ($body === false) throw new RuntimeException('HTTP test request failed: ' . curl_error($ch));
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status >= 400) throw new RuntimeException('Installer returned HTTP ' . $status . ': ' . substr($body, 0, 500));
    return $body;
};

try {
    usleep(700000);
    $send($baseUrl);
    $credentials = [
        'installer_token' => $token,
        'db_host' => $host,
        'db_name' => $dbName,
        'db_user' => $dbUser,
        'db_pass' => $dbPass,
        'db_prefix' => 'site_',
        'site_url' => $newUrl,
    ];
    $tested = $send($baseUrl, $credentials + ['uab_action' => 'test']);
    if (strpos($tested, 'Підключення успішне') === false) throw new RuntimeException('Installer DB test stage failed.');
    $imported = $send($baseUrl, $credentials + ['uab_action' => 'import']);
    if (strpos($imported, 'Базу даних відновлено') === false) throw new RuntimeException('Installer DB import stage failed: ' . strip_tags($imported));
    $finished = $send($baseUrl, [
        'installer_token' => $token,
        'uab_action' => 'finish',
        'admin_id' => '1',
        'existing_admin_pass' => '',
        'new_admin_login' => 'newadmin',
        'new_admin_pass' => 'newadmin-password',
    ]);
    if (strpos($finished, 'Сайт відновлено') === false) throw new RuntimeException('Installer finish stage failed: ' . strip_tags($finished));
    if (!is_file($root . '/index.php') || !is_file($root . '/wp-admin/index.php')) throw new RuntimeException('Site files were not restored.');
    if (is_file($root . '/installer.php') || is_dir($root . '/.ua-backup-private') || is_file($root . '/database.sql') || is_file($root . '/site-files.zip') || is_dir($root . '/site-files')) throw new RuntimeException('Installer did not clean temporary files.');
    $restoredConfig = file_get_contents($root . '/wp-config.php');
    foreach (["'" . $dbName . "'", "'" . $dbUser . "'", "'" . $dbPass . "'", "'" . $host . "'", "'site_'"] as $needle) {
        if (strpos($restoredConfig, $needle) === false) throw new RuntimeException('wp-config.php was not updated: missing ' . $needle);
    }
    $mysqli = new mysqli($host, $dbUser, $dbPass, $dbName);
    $options = $mysqli->query("SELECT option_value FROM site_options WHERE option_name='home'")->fetch_assoc();
    if ($options['option_value'] !== $newUrl) throw new RuntimeException('Site URL was not updated in options.');
    $meta = $mysqli->query("SELECT meta_value FROM site_postmeta WHERE meta_key='serialized'")->fetch_assoc();
    $value = unserialize($meta['meta_value']);
    if (($value['url'] ?? '') !== $newUrl || ($value['nested']['url'] ?? '') !== $newUrl) throw new RuntimeException('Serialized metadata URL replacement failed.');
    $admin = $mysqli->query("SELECT user_pass FROM site_users WHERE ID=1")->fetch_assoc();
    if (!password_verify('original-password', $admin['user_pass'])) throw new RuntimeException('Existing administrator password was not preserved.');
    $rolesOption = $mysqli->query("SELECT option_name FROM site_options WHERE option_name='site_user_roles'")->fetch_assoc();
    if (!$rolesOption) throw new RuntimeException('WordPress roles option key was not migrated to the new prefix.');
    $oldAdminRole = $mysqli->query("SELECT meta_value FROM site_usermeta WHERE user_id=1 AND meta_key='site_capabilities'")->fetch_assoc();
    if (!$oldAdminRole || strpos($oldAdminRole['meta_value'], 'administrator') === false) throw new RuntimeException('Existing administrator capability key was not migrated to the new prefix.');
    $newAdmin = $mysqli->query("SELECT u.ID,m.meta_value FROM site_users u INNER JOIN site_usermeta m ON m.user_id=u.ID WHERE u.user_login='newadmin' AND m.meta_key='site_capabilities'")->fetch_assoc();
    if (!$newAdmin || strpos($newAdmin['meta_value'], 'administrator') === false) throw new RuntimeException('New administrator was not created with administrator role.');
    $mysqli->close();
    echo "UA Backup installer integration test passed.\n";
} finally {
    proc_terminate($process);
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    proc_close($process);
}
