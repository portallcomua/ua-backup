<?php
defined('ABSPATH') || exit;
class UA_Backup_Updater {
    private string $plugin_file;
    private string $repo;
    public function __construct(string $plugin_file, string $repo) {
        $this->plugin_file = $plugin_file;
        $this->repo = $repo;
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check']);
        add_filter('plugins_api', [$this, 'info'], 20, 3);
    }
    private function release(): ?array {
        $key = 'uab_release_' . md5($this->repo);
        $cached = get_site_transient($key);
        if (is_array($cached)) return $cached;
        $response = wp_remote_get('https://api.github.com/repos/' . $this->repo . '/releases/latest', ['timeout' => 10, 'headers' => ['Accept' => 'application/vnd.github+json']]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return null;
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['tag_name']) || empty($data['zipball_url'])) return null;
        set_site_transient($key, $data, 6 * HOUR_IN_SECONDS);
        return $data;
    }
    public function check($transient) {
        if (empty($transient->checked)) return $transient;
        $release = $this->release();
        if (!$release) return $transient;
        $version = ltrim($release['tag_name'], 'vV');
        if (version_compare(UAB_VERSION, $version, '<')) {
            $slug = plugin_basename($this->plugin_file);
            $transient->response[$slug] = (object)['slug' => dirname($slug), 'plugin' => $slug, 'new_version' => $version, 'package' => $release['zipball_url'], 'url' => 'https://github.com/' . $this->repo];
        }
        return $transient;
    }
    public function info($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== dirname(plugin_basename($this->plugin_file))) return $result;
        $release = $this->release();
        if (!$release) return $result;
        return (object)['name' => 'UA Backup Pro', 'slug' => $args->slug, 'version' => ltrim($release['tag_name'], 'vV'), 'author' => '<a href="https://uaserver.pp.ua">UAServer</a>', 'homepage' => 'https://uaserver.pp.ua/ua-backup', 'download_link' => $release['zipball_url'], 'sections' => ['description' => 'Просте повне резервне копіювання WordPress.', 'changelog' => !empty($release['body']) ? wp_kses_post($release['body']) : 'Оновлення.']];
    }
}
