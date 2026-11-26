<?php
defined('ABSPATH') || exit;
class UA_Backup_Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_uab_create', [$this, 'create']);
        add_action('wp_ajax_uab_create_ajax', [$this, 'create_ajax']);
        add_action('wp_ajax_uab_progress', [$this, 'progress_ajax']);
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
        <div class="wrap" style="max-width:1100px">
            <h1>UA Backup</h1>
            <?php if ($notice): ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
            <p>Повна резервна копія WordPress: файли сайту та база даних в одному ZIP-архіві.</p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px;margin:24px 0">
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:25px;text-align:center">
                    <div style="font-size:42px">🗄️</div><h2>Створити бекап</h2>
                    <p>Зберегти повну копію сайту разом із базою даних.</p>
                    <form id="uab-create-form">
                        <input type="hidden" name="action" value="uab_create_ajax">
                        <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('uab_ajax')); ?>">
                        <button type="submit" class="button button-primary button-large" id="uab-create-button" style="font-size:18px;padding:12px 26px;height:auto">Створити бекап</button>
                    </form>
                    <div id="uab-progress" style="display:none;margin-top:18px;text-align:left">
                        <div style="height:18px;background:#e5e7eb;border-radius:20px;overflow:hidden"><div id="uab-progress-bar" style="height:100%;width:0;background:#2271b1;transition:width .25s"></div></div>
                        <p id="uab-progress-text" style="margin:8px 0 0">Підготовка...</p>
                    </div>
                </div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:25px;text-align:center">
                    <div style="font-size:42px">🚀</div><h2>Встановити сайт</h2>
                    <p>Перенести резервну копію на інший хостинг.</p>
                    <a class="button button-secondary button-large" href="#uab-install" style="font-size:18px;padding:12px 26px;height:auto">Встановити сайт</a>
                </div>
            </div>
            <section id="uab-install" style="background:#f6f7f7;border-left:4px solid #2271b1;padding:16px 22px;margin:20px 0">
                <h2>Встановлення на новому хостингу</h2>
                <ol><li>Створіть резервну копію та скачайте ZIP-архів нижче.</li><li>Завантажте архів у кореневу папку нового сайту та розпакуйте його.</li><li>Відкрийте <code>https://ваш-домен/installer.php</code>.</li><li>Заповніть параметри бази даних, перевірте підключення та завершіть встановлення.</li></ol>
                <p><strong>Збережіть одноразовий ключ інсталятора з повідомлення після створення бекапа. Після успішного перенесення інсталятор сам видалить службові файли.</strong></p>
            </section>
            <h2>Резервні копії</h2>
            <table class="widefat striped"><thead><tr><th>Архів</th><th>Дата</th><th>Розмір</th><th>Дії</th></tr></thead><tbody>
            <?php if (!$backups): ?><tr><td colspan="4">Резервних копій ще немає.</td></tr><?php endif; ?>
            <?php foreach ($backups as $backup): ?><tr>
                <td><code><?php echo esc_html($backup['name']); ?></code></td>
                <td><?php echo esc_html(wp_date('d.m.Y H:i', $backup['time'])); ?></td>
                <td><?php echo esc_html(size_format($backup['size'], 2)); ?></td><td>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=uab_download&file=' . rawurlencode($backup['name'])), 'uab_download_' . $backup['name'])); ?>">Скачати</a>
                <a class="button button-link-delete" onclick="return confirm('Видалити архів?')" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=uab_delete&file=' . rawurlencode($backup['name'])), 'uab_delete_' . $backup['name'])); ?>">Видалити</a>
                </td></tr><?php endforeach; ?>
            </tbody></table>
        </div>
        <script>
        (function(){
            const form=document.getElementById('uab-create-form');
            if(!form)return;
            const button=document.getElementById('uab-create-button');
            const panel=document.getElementById('uab-progress');
            const bar=document.getElementById('uab-progress-bar');
            const label=document.getElementById('uab-progress-text');
            const nonce=form.querySelector('[name="nonce"]').value;
            let polling=true;
            async function poll(){
                while(polling){
                    try{
                        const body=new URLSearchParams({action:'uab_progress',nonce:nonce});
                        const response=await fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()});
                        const data=await response.json();
                        if(data.success&&data.data){
                            bar.style.width=(data.data.percent||0)+'%';
                            label.textContent=(data.data.percent||0)+'% — '+(data.data.message||'');
                            if(data.data.percent===100){polling=false;return;}
                        }
                    }catch(e){}
                    await new Promise(resolve=>setTimeout(resolve,700));
                }
            }
            form.addEventListener('submit',async function(event){
                event.preventDefault();
                if(button.disabled)return;
                button.disabled=true;button.textContent='Створення...';panel.style.display='block';
                bar.style.width='1%';label.textContent='Запуск резервного копіювання...';
                polling=true;poll();
                try{
                    const response=await fetch(ajaxurl,{method:'POST',body:new FormData(form)});
                    const data=await response.json();
                    polling=false;
                    if(data.success){
                        bar.style.width='100%';label.textContent='100% — '+data.data.message;
                        window.location.href=data.data.redirect;
                    }else{
                        label.textContent='Помилка: '+(data.data&&data.data.message?data.data.message:'Не вдалося створити бекап.');
                        button.disabled=false;button.textContent='Спробувати ще раз';
                    }
                }catch(e){
                    polling=false;label.textContent='Помилка з’єднання. Оновіть сторінку та перевірте список бекапів.';
                    button.disabled=false;button.textContent='Спробувати ще раз';
                }
            });
        })();
        </script><?php
    }
    public function progress_ajax(): void {
        check_ajax_referer('uab_ajax', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Недостатньо прав.'], 403);
        wp_send_json_success(UA_Backup_Engine::progress());
    }
    public function create_ajax(): void {
        check_ajax_referer('uab_ajax', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Недостатньо прав.'], 403);
        try {
            $result = UA_Backup_Engine::create_backup();
            $message = 'Бекап створено: ' . $result['name'] . ' | Ключ інсталятора: ' . $result['installer_token'];
            wp_send_json_success(['message' => $message, 'redirect' => add_query_arg('uab_notice', rawurlencode($message), admin_url('tools.php?page=ua-backup'))]);
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }
    }
    public function create(): void {
        if (!current_user_can('manage_options')) wp_die('Недостатньо прав.');
        check_admin_referer('uab_create');
        try { $result=UA_Backup_Engine::create_backup(); $message='Бекап створено: '.$result['name'].' | Ключ інсталятора: '.$result['installer_token']; }
        catch (Throwable $e) { $message='Помилка створення: '.$e->getMessage(); }
        wp_safe_redirect(add_query_arg('uab_notice', rawurlencode($message), admin_url('tools.php?page=ua-backup'))); exit;
    }
    public function download(): void {
        if (!current_user_can('manage_options')) wp_die('Недостатньо прав.');
        $name=isset($_GET['file'])?basename(wp_unslash($_GET['file'])):'';
        check_admin_referer('uab_download_'.$name); $path=UA_Backup_Engine::file_path($name);
        if (!$path) wp_die('Файл не знайдено.');
        nocache_headers(); header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="'.rawurlencode($name).'"');
        header('Content-Length: '.filesize($path)); readfile($path); exit;
    }
    public function delete(): void {
        if (!current_user_can('manage_options')) wp_die('Недостатньо прав.');
        $name=isset($_GET['file'])?basename(wp_unslash($_GET['file'])):'';
        check_admin_referer('uab_delete_'.$name);
        $message=UA_Backup_Engine::delete($name)?'Архів видалено.':'Не вдалося видалити архів.';
        wp_safe_redirect(add_query_arg('uab_notice', rawurlencode($message), admin_url('tools.php?page=ua-backup'))); exit;
    }
}