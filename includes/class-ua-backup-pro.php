<?php
defined('ABSPATH') || exit;

class UA_Backup_Pro {
    const OPTION = 'uab_pro_settings';
    const HOOK = 'uab_pro_scheduled_backup';

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register']);
        add_action(self::HOOK, [$this, 'run']);
        add_filter('cron_schedules', [$this, 'schedules']);
        add_action('update_option_' . self::OPTION, [$this, 'reschedule'], 10, 2);
    }

    public function menu(): void {
        add_submenu_page('tools.php', 'UA Backup Pro', 'UA Backup Pro — Автоматизація', 'manage_options', 'ua-backup-pro', [$this, 'page']);
    }

    public function register(): void {
        register_setting('uab_pro', self::OPTION, [$this, 'sanitize']);
    }

    public function sanitize($value): array {
        return [
            'enabled' => !empty($value['enabled']) ? 1 : 0,
            'frequency' => in_array($value['frequency'] ?? '', ['daily','weekly','monthly'], true) ? $value['frequency'] : 'monthly',
            'hour' => max(0, min(23, (int)($value['hour'] ?? 3))),
            'keep' => max(1, min(100, (int)($value['keep'] ?? 5))),
            'email' => sanitize_email($value['email'] ?? get_option('admin_email')),
            'notify_success' => !empty($value['notify_success']) ? 1 : 0,
            'notify_error' => !empty($value['notify_error']) ? 1 : 0,
            'drive_token' => sanitize_text_field($value['drive_token'] ?? ''),
            'drive_folder' => sanitize_text_field($value['drive_folder'] ?? ''),
            'drive_enabled' => !empty($value['drive_enabled']) ? 1 : 0,
        ];
    }

    public function schedules(array $schedules): array {
        $schedules['weekly'] = ['interval' => WEEK_IN_SECONDS, 'display' => 'Раз на тиждень'];
        $schedules['monthly'] = ['interval' => 30 * DAY_IN_SECONDS, 'display' => 'Раз на місяць'];
        return $schedules;
    }

    public function reschedule($old, $new): void {
        wp_clear_scheduled_hook(self::HOOK);
        if (!empty($new['enabled'])) {
            $hour = (int)($new['hour'] ?? 3);
            $next = strtotime('tomorrow ' . $hour . ':00:00');
            wp_schedule_event($next, $new['frequency'] ?? 'monthly', self::HOOK);
        }
    }

    public function page(): void {
        if (!current_user_can('manage_options')) return;
        $s = wp_parse_args(get_option(self::OPTION, []), ['enabled'=>0,'frequency'=>'monthly','hour'=>3,'keep'=>5,'email'=>get_option('admin_email'),'notify_success'=>1,'notify_error'=>1,'drive_enabled'=>0,'drive_token'=>'','drive_folder'=>'']);
        ?>
        <div class="wrap"><h1>UA Backup Pro — Автоматизація</h1>
        <form method="post" action="options.php">
            <?php settings_fields('uab_pro'); ?>
            <table class="form-table">
                <tr><th>Автоматичні копії</th><td><label><input type="checkbox" name="<?php echo self::OPTION; ?>[enabled]" value="1" <?php checked($s['enabled']); ?>> Увімкнути</label></td></tr>
                <tr><th>Періодичність</th><td><select name="<?php echo self::OPTION; ?>[frequency]"><option value="daily" <?php selected($s['frequency'],'daily'); ?>>Щодня</option><option value="weekly" <?php selected($s['frequency'],'weekly'); ?>>Щотижня</option><option value="monthly" <?php selected($s['frequency'],'monthly'); ?>>Щомісяця</option></select></td></tr>
                <tr><th>Година запуску</th><td><input type="number" min="0" max="23" name="<?php echo self::OPTION; ?>[hour]" value="<?php echo esc_attr($s['hour']); ?>">:00</td></tr>
                <tr><th>Зберігати копій</th><td><input type="number" min="1" max="100" name="<?php echo self::OPTION; ?>[keep]" value="<?php echo esc_attr($s['keep']); ?>"></td></tr>
                <tr><th>Email</th><td><input type="email" class="regular-text" name="<?php echo self::OPTION; ?>[email]" value="<?php echo esc_attr($s['email']); ?>"><br><label><input type="checkbox" name="<?php echo self::OPTION; ?>[notify_success]" value="1" <?php checked($s['notify_success']); ?>> повідомляти про успіх</label><br><label><input type="checkbox" name="<?php echo self::OPTION; ?>[notify_error]" value="1" <?php checked($s['notify_error']); ?>> повідомляти про помилку</label></td></tr>
                <tr><th>Google Drive</th><td><label><input type="checkbox" name="<?php echo self::OPTION; ?>[drive_enabled]" value="1" <?php checked($s['drive_enabled']); ?>> Завантажувати копії</label><p><input type="password" class="large-text" name="<?php echo self::OPTION; ?>[drive_token]" value="<?php echo esc_attr($s['drive_token']); ?>" placeholder="Google OAuth access token"></p><p><input type="text" class="regular-text" name="<?php echo self::OPTION; ?>[drive_folder]" value="<?php echo esc_attr($s['drive_folder']); ?>" placeholder="ID папки Google Drive"></p><p class="description">У версії 1.0 використовується OAuth access token. Токен має мати доступ до Google Drive API.</p></td></tr>
            </table>
            <?php submit_button('Зберегти'); ?>
        </form></div>
        <?php
    }

    public function run(): void {
        $s = wp_parse_args(get_option(self::OPTION, []), ['keep'=>5,'email'=>get_option('admin_email')]);
        try {
            $result = UA_Backup_Engine::create_backup();
            $this->rotate((int)$s['keep']);
            if (!empty($s['drive_enabled']) && !empty($s['drive_token'])) {
                $this->upload_drive($result['path'], $result['name'], $s['drive_token'], $s['drive_folder'] ?? '');
            }
            if (!empty($s['notify_success']) && is_email($s['email'])) wp_mail($s['email'], 'UA Backup: копію створено', 'Створено резервну копію: ' . $result['name']);
        } catch (Throwable $e) {
            if (!empty($s['notify_error']) && is_email($s['email'])) wp_mail($s['email'], 'UA Backup: помилка', $e->getMessage());
        }
    }

    private function rotate(int $keep): void {
        $items = UA_Backup_Engine::list_backups();
        foreach (array_slice($items, $keep) as $item) UA_Backup_Engine::delete($item['name']);
    }

    private function upload_drive(string $path, string $name, string $token, string $folder): void {
        $meta = ['name' => $name];
        if ($folder) $meta['parents'] = [$folder];
        $boundary = 'uab' . wp_generate_password(16, false, false);
        $body = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n" . wp_json_encode($meta) . "\r\n--{$boundary}\r\nContent-Type: application/zip\r\n\r\n" . file_get_contents($path) . "\r\n--{$boundary}--";
        $response = wp_remote_post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart', ['timeout'=>300,'headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'multipart/related; boundary='.$boundary],'body'=>$body]);
        $code = wp_remote_retrieve_response_code($response);
        if (is_wp_error($response) || $code < 200 || $code >= 300) throw new RuntimeException('Google Drive: не вдалося завантажити архів. Код ' . $code);
    }
}
