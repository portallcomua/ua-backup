<?php
/**
 * Plugin Name: UA Backup Pro
 * Plugin URI: https://uaserver.pp.ua/ua-backup
 * Description: Повне резервне копіювання WordPress із розкладом, ротацією, email і Google Drive.
 * Version: 1.0.0
 * Author: UAServer
 * Author URI: https://uaserver.pp.ua
 * Text Domain: ua-backup
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * GitHub Plugin URI: portallcomua/ua-backup-pro
 */

defined('ABSPATH') || exit;

define('UAB_VERSION', '1.0.0');
define('UAB_FILE', __FILE__);
define('UAB_DIR', plugin_dir_path(__FILE__));
define('UAB_URL', plugin_dir_url(__FILE__));
define('UAB_PRO', true);

require_once UAB_DIR . 'includes/class-ua-backup-engine.php';
require_once UAB_DIR . 'includes/class-ua-backup-admin.php';
require_once UAB_DIR . 'includes/class-ua-backup-updater.php';
require_once UAB_DIR . 'includes/class-ua-backup-pro.php';

register_activation_hook(__FILE__, function () {
    $dir = WP_CONTENT_DIR . '/ua-backups';
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }
    @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
    @file_put_contents($dir . '/.htaccess', "Deny from all\n");
    @file_put_contents($dir . '/web.config', '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>');
});

add_action('plugins_loaded', function () {
    load_plugin_textdomain('ua-backup', false, dirname(plugin_basename(__FILE__)) . '/languages');
    new UA_Backup_Admin();
    new UA_Backup_Updater(UAB_FILE, 'portallcomua/ua-backup-pro');
    new UA_Backup_Pro();
});
