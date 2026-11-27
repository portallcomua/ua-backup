<?php
/*
 * Plugin Name: UA Backup
 * Plugin URI: https://uaserver.pp.ua/uabackup
 * Description: Просте повне резервне копіювання та перенесення WordPress-сайтів.
 * Version: 2.2.3
 * Author: UAServer
 * Author URI: https://uaserver.pp.ua
 * License: GPLv2 or later
 * Text Domain: ua-backup
 */
defined('ABSPATH') || exit;

define('UAB_VERSION', '2.2.3');
define('UAB_FILE', __FILE__);
define('UAB_DIR', plugin_dir_path(__FILE__));
define('UAB_URL', plugin_dir_url(__FILE__));

require_once UAB_DIR . 'includes/class-ua-backup-engine.php';
require_once UAB_DIR . 'includes/class-ua-backup-i18n.php';
require_once UAB_DIR . 'includes/class-ua-backup-updater.php';
require_once UAB_DIR . 'includes/class-ua-backup-admin.php';

new UA_Backup_Updater();

add_action('plugins_loaded', static function () {
    new UA_Backup_Admin();
});

register_activation_hook(__FILE__, static function () {
    $dir = WP_CONTENT_DIR . '/ua-backups';
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }
    if (is_dir($dir) && !file_exists($dir . '/.htaccess')) {
        file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    if (is_dir($dir) && !file_exists($dir . '/index.php')) {
        file_put_contents($dir . '/index.php', "<?php http_response_code(404); exit;\n");
    }
    if (is_dir($dir) && !file_exists($dir . '/web.config')) {
        file_put_contents($dir . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>");
    }
});
