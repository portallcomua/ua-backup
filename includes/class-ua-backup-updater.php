<?php
defined('ABSPATH') || exit;

final class UA_Backup_Updater {
    private const VERSION_URL = 'https://raw.githubusercontent.com/portallcomua/ua-backup/main/version.json';
    private const PACKAGE_URL = 'https://codeload.github.com/portallcomua/ua-backup/zip/refs/heads/main';
    private const CACHE_KEY = 'uab_github_update_data_225';

    public function __construct() {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('plugins_api', [$this, 'plugin_information'], 20, 3);
        add_filter('upgrader_source_selection', [$this, 'fix_package_directory'], 10, 4);
        add_filter('plugin_row_meta', [$this, 'plugin_row_meta'], 10, 2);
        add_filter('auto_update_plugin', [$this, 'enable_automatic_updates'], 10, 2);
    }

    private function remote_version_data(): array {
        $cached = get_site_transient(self::CACHE_KEY);
        if (is_array($cached) && !empty($cached['version'])) return $cached;

        $response = wp_remote_get(self::VERSION_URL, [
            'timeout' => 8,
            'redirection' => 3,
            'headers' => ['Accept' => 'application/json', 'User-Agent' => 'UA-Backup-WordPress-Updater'],
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return is_array($cached) ? $cached : [];
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['version']) || !preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', (string) $data['version'])) {
            return is_array($cached) ? $cached : [];
        }
        $data['package'] = self::PACKAGE_URL;
        $data['homepage'] = 'https://uaserver.pp.ua/uabackup';
        set_site_transient(self::CACHE_KEY, $data, 6 * HOUR_IN_SECONDS);
        return $data;
    }

    public function inject_update($transient) {
        if (!is_object($transient) || empty($transient->checked)) return $transient;
        $plugin = plugin_basename(UAB_FILE);
        $data = $this->remote_version_data();
        if (empty($data['version'])) return $transient;

        if (version_compare((string) $data['version'], UAB_VERSION, '>')) {
            $transient->response[$plugin] = (object) [
                'slug' => 'ua-backup',
                'plugin' => $plugin,
                'new_version' => (string) $data['version'],
                'url' => (string) $data['homepage'],
                'package' => (string) $data['package'],
                'tested' => (string) ($data['tested'] ?? ''),
                'requires_php' => (string) ($data['requires_php'] ?? '7.4'),
            ];
        } else {
            unset($transient->response[$plugin]);
        }
        return $transient;
    }

    public function plugin_information($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'ua-backup') return $result;
        $data = $this->remote_version_data();
        if (empty($data['version'])) return $result;

        return (object) [
            'name' => 'UA Backup',
            'slug' => 'ua-backup',
            'version' => (string) $data['version'],
            'author' => '<a href="https://uaserver.pp.ua">UAServer</a>',
            'homepage' => (string) $data['homepage'],
            'requires' => (string) ($data['requires'] ?? '6.0'),
            'tested' => (string) ($data['tested'] ?? ''),
            'requires_php' => (string) ($data['requires_php'] ?? '7.4'),
            'download_link' => (string) $data['package'],
            'sections' => [
                'description' => 'Full WordPress backup and restore in one ZIP archive.',
                'changelog' => (string) ($data['changelog'] ?? 'See the project page for the latest changes.'),
            ],
        ];
    }

    public function fix_package_directory($source, $remote_source, $upgrader, $hook_extra) {
        if (is_wp_error($source) || empty($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(UAB_FILE)) return $source;
        $plugin_dir = dirname(plugin_basename(UAB_FILE));
        if ($plugin_dir === '.' || basename(untrailingslashit($source)) === $plugin_dir) return $source;

        $target = trailingslashit(dirname(untrailingslashit($source))) . $plugin_dir;
        if (is_dir($target)) return new WP_Error('uab_update_folder_exists', 'UA Backup update package folder already exists. Please retry the update.');
        if (!@rename(untrailingslashit($source), $target)) return new WP_Error('uab_update_folder_rename', 'Could not prepare the UA Backup update package.');
        return trailingslashit($target);
    }


    public function enable_automatic_updates($update, $item) {
        if (is_object($item) && !empty($item->plugin) && $item->plugin === plugin_basename(UAB_FILE)) return true;
        return $update;
    }

    public function plugin_row_meta($links, $file) {
        if ($file !== plugin_basename(UAB_FILE)) return $links;
        $links[] = '<a href="https://uaserver.pp.ua/uabackup" target="_blank" rel="noopener">UAServer</a>';
        return $links;
    }
}
