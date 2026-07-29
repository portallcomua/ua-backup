<?php
defined('ABSPATH') || exit;

class UA_Backup_Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_uab_create', [$this, 'create']);
        add_action('admin_post_uab_download', [$this, 'download']);
        add_action('admin_post_uab_delete', [$this, 'delete']);
    }

    public function menu(): void {
        add_management_page('UA Backup', 'UA Backup', 'manage_options', 'ua-backup', [$this, 'page']);
    }

    public function page(): void {
        if (!current_user_can('manage_options')) return;
        $backups = UA_Backup_Engine::list_backups();
        $notice = isset($_GET['uab_notice']) ? sanitize_text_field(wp_unslash($_GET['uab_notice'])) : '';
        ?>
        <div class="wrap">
            <h1>UA Backup <?php echo UAB_PRO ? 'Pro' : ''; ?></h1>
            <?php if ($notice): ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
            <p>Повна резервна копія файлів і бази даних без зайвих налаштувань.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('uab_create'); ?>
                <input type="hidden" name="action" value="uab_create">
                <?php submit_button('Створити бекап зараз', 'primary large'); ?>
            </form>
            <h2>Резервні копії</h2>
            <table class="widefat striped">
                <thead><tr><th>Архів</th><th>Дата</th><th>Розмір</th><th>Дії</th></tr></thead>
                <tbody>
                <?php if (!$backups): ?><tr><td colspan="4">Резервних копій ще немає.</td></tr><?php endif; ?>
                <?php foreach ($backups as $backup): ?>
                    <tr>
                        <td><code><?php echo esc_html($backup['name']); ?></code></td>
                        <td><?php echo esc_html(wp_date('d.m.Y H:i', $backup['time'])); ?></td>
                        <td><?php echo esc_html(size_format($backup['size'], 2)); ?></td>
                        <td>
                            <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=uab_download&file=' . rawurlencode($backup['name'])), 'uab_download_' . $backup['name'])); ?>">Скачати</a>
                            <a class="button button-link-delete" onclick="return confirm('Видалити архів?')" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=uab_delete&file=' . rawurlencode($backup['name'])), 'uab_delete_' . $backup['name'])); ?>">Видалити</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p><strong>Відновлення:</strong> завантажте ZIP на новий сервер, розпакуйте його та відкрийте <code>installer.php</code>.</p>
        </div>
        <?php
    }

    public function create(): void {
        if (!current_user_can('manage_options')) wp_die('Недостатньо прав.');
        check_admin_referer('uab_create');
        try {
            $result = UA_Backup_Engine::create_backup();
            $message = 'Бекап створено: ' . $result['name'];
        } catch (Throwable $e) {
            $message = 'Помилка: ' . $e->getMessage();
        }
        wp_safe_redirect(add_query_arg('uab_notice', rawurlencode($message), admin_url('tools.php?page=ua-backup')));
        exit;
    }

    public function download(): void {
        if (!current_user_can('manage_options')) wp_die('Недостатньо прав.');
        $name = isset($_GET['file']) ? basename(wp_unslash($_GET['file'])) : '';
        check_admin_referer('uab_download_' . $name);
        $path = UA_Backup_Engine::file_path($name);
        if (!$path) wp_die('Файл не знайдено.');
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    public function delete(): void {
        if (!current_user_can('manage_options')) wp_die('Недостатньо прав.');
        $name = isset($_GET['file']) ? basename(wp_unslash($_GET['file'])) : '';
        check_admin_referer('uab_delete_' . $name);
        $message = UA_Backup_Engine::delete($name) ? 'Архів видалено.' : 'Не вдалося видалити архів.';
        wp_safe_redirect(add_query_arg('uab_notice', rawurlencode($message), admin_url('tools.php?page=ua-backup')));
        exit;
    }
}
