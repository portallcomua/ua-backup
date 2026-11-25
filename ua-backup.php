<?php
/*
 * Plugin Name: UA Backup
 * Plugin URI: https://uaserver.pp.ua
 * Description: Просте повне резервне копіювання та перенесення WordPress-сайтів.
 * Version: 2.0.0
 * Author: UAServer
 * Author URI: https://uaserver.pp.ua
 * License: GPLv2 or later
 * Text Domain: ua-backup
 */
defined('ABSPATH') || exit;

define('UAB_VERSION', '2.0.0');
define('UAB_FILE', __FILE__);
define('UAB_DIR', plugin_dir_path(__FILE__));
define('UAB_URL', plugin_dir_url(__FILE__));

require_once UAB_DIR . 'includes/class-ua-backup-engine.php';
require_once UAB_DIR . 'includes/class-ua-backup-admin.php';

add_action('plugins_loaded', static function () {
    new UA_Backup_Admin();
});

register_activation_hook(__FILE__, static function () {
    $dir = WP_CONTENT_DIR . '/ua-backups';
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }
    if (is_dir($dir) && !file_exists($dir . '/.htaccess')) {
        file_put_contents($dir . '/.htaccess', "Deny from all\n");
    }
    if (is_dir($dir) && !file_exists($dir . '/index.php')) {
        file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
    }
});
