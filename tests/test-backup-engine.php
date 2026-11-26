<?php
error_reporting(E_ALL);
define('ABSPATH', sys_get_temp_dir() . '/uab-test-' . bin2hex(random_bytes(5)) . '/site/');
define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
define('UAB_DIR', dirname(__DIR__) . '/');
define('UAB_VERSION', '2.1.0');
define('ARRAY_N', 'ARRAY_N');
define('ARRAY_A', 'ARRAY_A');
define('MINUTE_IN_SECONDS', 60);

function wp_mkdir_p($path) { return is_dir($path) || mkdir($path, 0777, true); }
function wp_tempnam($prefix) { return tempnam(sys_get_temp_dir(), $prefix); }
function sanitize_title($value) { return preg_replace('/[^a-z0-9-]+/', '-', strtolower($value)); }
function home_url() { return 'https://source.example'; }
function site_url() { return 'https://source.example'; }
function get_bloginfo($key) { return '6.8'; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function untrailingslashit($value) { return rtrim($value, '/\\'); }
function get_current_user_id() { return 7; }
function set_transient($key, $value, $expiration) { $GLOBALS['uab_transients'][$key] = $value; }
function get_transient($key) { return $GLOBALS['uab_transients'][$key] ?? false; }

class UAB_Test_WPDB {
    public $last_error = '';
    public function get_col($sql) { return ['wp_options']; }
    public function get_row($sql, $output) { return [0, 'CREATE TABLE ' . chr(96) . 'wp_options' . chr(96) . ' (option_id bigint NOT NULL)']; }
    public function get_results($sql, $output) { return []; }
}
$GLOBALS['wpdb'] = new UAB_Test_WPDB();
$GLOBALS['table_prefix'] = 'wp_';

require UAB_DIR . 'includes/class-ua-backup-engine.php';

if (!wp_mkdir_p(ABSPATH . 'wp-admin') || !wp_mkdir_p(WP_CONTENT_DIR)) throw new RuntimeException('Could not create test directories.');
file_put_contents(ABSPATH . 'index.php', '<?php echo "test";');
file_put_contents(ABSPATH . 'wp-admin/test.txt', 'backup fixture');

$result = UA_Backup_Engine::create_backup();
$archive = UA_Backup_Engine::file_path($result['name']);
if (!$archive || !is_file($archive)) throw new RuntimeException('Backup archive was not created.');
$zip = new ZipArchive();
if ($zip->open($archive, ZipArchive::CHECKCONS) !== true) throw new RuntimeException('Backup ZIP is invalid.');
$required = ['ua-backup.json', 'database.sql', 'installer.php', 'site-files.zip'];
foreach ($required as $entry) {
    if ($zip->locateName($entry) === false) throw new RuntimeException('Missing ZIP entry: ' . $entry);
}
$payloadBytes = $zip->getFromName('site-files.zip');
$payloadPath = tempnam(sys_get_temp_dir(), 'uab-payload-test');
file_put_contents($payloadPath, $payloadBytes);
$payload = new ZipArchive();
if ($payload->open($payloadPath, ZipArchive::CHECKCONS) !== true) throw new RuntimeException('Nested site files ZIP is invalid.');
if ($payload->locateName('index.php') === false || $payload->locateName('wp-admin/test.txt') === false) throw new RuntimeException('Nested site files ZIP is incomplete.');
$payload->close();
unlink($payloadPath);
$manifest = json_decode($zip->getFromName('ua-backup.json'), true);
if (empty($manifest['installer_token_hash']) || empty($manifest['table_prefix'])) throw new RuntimeException('Manifest is incomplete.');
$sql = $zip->getFromName('database.sql');
if (strpos($sql, 'CREATE TABLE') === false || strpos($sql, 'FOREIGN_KEY_CHECKS=1') === false) throw new RuntimeException('Database export is incomplete.');
$zip->close();
if (!UA_Backup_Engine::delete($result['name'])) throw new RuntimeException('Could not delete test archive.');
echo "UA Backup engine test passed.\n";
