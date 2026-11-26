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

    private function t(string $key): string {
        return class_exists('UA_Backup_I18n') ? UA_Backup_I18n::t($key) : $key;
    }

    public function menu(): void {
        add_management_page($this->t('page_title'), $this->t('page_title'), 'manage_options', 'ua-backup', [$this, 'page']);
    }

    public function page(): void {
        if (!current_user_can('manage_options')) return;
        $backups = UA_Backup_Engine::list_backups();
        $notice = '';
        if (isset($_GET['uab_done'])) {
            $notice = (string) get_transient('uab_last_result_' . get_current_user_id());
            delete_transient('uab_last_result_' . get_current_user_id());
        }
        if (!$notice && isset($_GET['uab_notice'])) $notice = sanitize_text_field(wp_unslash($_GET['uab_notice']));
        $tr = function (string $key): string { return $this->t($key); };
        $js = [
            'creating' => $tr('creating'),
            'tryAgain' => $tr('try_again'),
            'preparing' => $tr('preparing'),
            'starting' => $tr('starting'),
            'finishing' => $tr('finishing'),
            'ready' => $tr('ready'),
            'connectionError' => $tr('connection_error'),
            'genericError' => $tr('generic_error'),
            'stageDb' => $tr('stage_db'),
            'stageFiles' => $tr('stage_files'),
            'stageFilesCount' => $tr('stage_files_count'),
            'stageZip' => $tr('stage_zip'),
        ];
        ?>
        <div class="wrap" style="max-width:1100px">
            <h1 style="display:flex;align-items:center;gap:12px;flex-wrap:wrap"><?php echo esc_html($tr('page_title')); ?><span style="font-size:13px;font-weight:500;background:#eaf2f8;color:#31536c;padding:5px 10px;border-radius:20px"><?php echo esc_html($tr('version')); ?> <?php echo esc_html(UAB_VERSION); ?></span></h1>
            <?php if ($notice): ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
            <p><?php echo esc_html($tr('description')); ?></p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px;margin:24px 0">
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:25px;text-align:center">
                    <div style="font-size:42px">🗄️</div><h2><?php echo esc_html($tr('create_title')); ?></h2>
                    <p><?php echo esc_html($tr('create_desc')); ?></p>
                    <form id="uab-create-form">
                        <input type="hidden" name="action" value="uab_create_ajax">
                        <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('uab_ajax')); ?>">
                        <button type="submit" class="button button-primary button-large" id="uab-create-button" style="font-size:18px;padding:12px 26px;height:auto"><?php echo esc_html($tr('create_button')); ?></button>
                    </form>
                    <div id="uab-progress" style="display:none;margin-top:18px;text-align:left">
                        <div style="height:18px;background:#e5e7eb;border-radius:20px;overflow:hidden"><div id="uab-progress-bar" style="height:100%;width:0;background:#2271b1;transition:width .25s"></div></div>
                        <p id="uab-progress-text" style="margin:8px 0 0"><?php echo esc_html($tr('preparing')); ?></p>
                    </div>
                </div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:25px;text-align:center">
                    <div style="font-size:42px">🚀</div><h2><?php echo esc_html($tr('install_title')); ?></h2>
                    <p><?php echo esc_html($tr('install_desc')); ?></p>
                    <a class="button button-secondary button-large" href="#uab-install" style="font-size:18px;padding:12px 26px;height:auto"><?php echo esc_html($tr('install_button')); ?></a>
                </div>
            </div>
            <section id="uab-install" style="background:#f6f7f7;border-left:4px solid #2271b1;padding:16px 22px;margin:20px 0">
                <h2><?php echo esc_html($tr('restore_title')); ?></h2>
                <ol>
                    <li><?php echo esc_html($tr('step1')); ?></li>
                    <li><?php echo esc_html($tr('step2')); ?></li>
                    <li><?php echo esc_html($tr('step3')); ?></li>
                    <li><?php echo esc_html($tr('step4')); ?></li>
                </ol>
                <p><strong><?php echo esc_html($tr('installer_note')); ?></strong></p>
            </section>
            <h2><?php echo esc_html($tr('backups_title')); ?></h2>
            <table class="widefat striped"><thead><tr><th><?php echo esc_html($tr('archive')); ?></th><th><?php echo esc_html($tr('date')); ?></th><th><?php echo esc_html($tr('size')); ?></th><th><?php echo esc_html($tr('actions')); ?></th></tr></thead><tbody>
            <?php if (!$backups): ?><tr><td colspan="4"><?php echo esc_html($tr('no_backups')); ?></td></tr><?php endif; ?>
            <?php foreach ($backups as $backup): ?><tr>
                <td><code><?php echo esc_html($backup['name']); ?></code></td>
                <td><?php echo esc_html(wp_date('d.m.Y H:i', $backup['time'])); ?></td>
                <td><?php echo esc_html(size_format($backup['size'], 2)); ?></td><td>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=uab_download&file=' . rawurlencode($backup['name'])), 'uab_download_' . $backup['name'])); ?>"><?php echo esc_html($tr('download')); ?></a>
                <a class="button button-link-delete" onclick="return confirm(<?php echo esc_attr(wp_json_encode($tr('confirm_delete'))); ?>)" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=uab_delete&file=' . rawurlencode($backup['name'])), 'uab_delete_' . $backup['name'])); ?>"><?php echo esc_html($tr('delete')); ?></a>
                </td></tr><?php endforeach; ?>
            </tbody></table>
            <p style="margin-top:18px;color:#646970"><?php echo esc_html__('Author:', 'ua-backup'); ?> <a href="https://uaserver.pp.ua" target="_blank" rel="noopener">UAServer</a> · <a href="https://uaserver.pp.ua/uabackup" target="_blank" rel="noopener">uaserver.pp.ua/uabackup</a></p>
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
            const i18n=<?php echo wp_json_encode($js); ?>;
            let polling=false;
            function translateStage(message){
                if(!message)return i18n.preparing;
                if(message.indexOf('Експорт бази даних')===0)return i18n.stageDb;
                if(message.indexOf('Базу даних збережено')===0)return i18n.stageFiles;
                if(message.indexOf('Архівація файлів:')===0)return i18n.stageFilesCount+' '+message.split(':').slice(1).join(':').trim();
                if(message.indexOf('Перевірка цілісності ZIP')===0)return i18n.stageZip;
                if(message.indexOf('Підготовка резервної копії')===0)return i18n.preparing;
                return message;
            }
            function makeRunId(){
                if(window.crypto&&window.crypto.getRandomValues){
                    return Array.from(window.crypto.getRandomValues(new Uint8Array(16))).map(v=>v.toString(16).padStart(2,'0')).join('');
                }
                return (Date.now().toString(16)+Math.random().toString(16).slice(2)).slice(0,32);
            }
            async function poll(runId){
                while(polling){
                    try{
                        const body=new URLSearchParams({action:'uab_progress',nonce:nonce,run_id:runId});
                        const response=await fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString(),cache:'no-store'});
                        const data=await response.json();
                        if(data.success&&data.data&&data.data.run_id===runId){
                            const raw=Math.max(0,Number(data.data.percent)||0);
                            // Поки запит створення архіву не завершився, ніколи не показуємо 100%.
                            const percent=Math.min(99,raw);
                            bar.style.width=percent+'%';
                            label.textContent=percent+'% — '+(raw>=100?i18n.finishing:translateStage(data.data.message));
                        }
                    }catch(e){}
                    await new Promise(resolve=>setTimeout(resolve,650));
                }
            }
            form.addEventListener('submit',async function(event){
                event.preventDefault();
                if(button.disabled)return;
                const runId=makeRunId();
                button.disabled=true;button.textContent=i18n.creating;panel.style.display='block';
                bar.style.width='1%';label.textContent=i18n.starting;
                polling=true;poll(runId);
                try{
                    const payload=new FormData(form);
                    payload.set('run_id',runId);
                    const response=await fetch(ajaxurl,{method:'POST',body:payload,cache:'no-store'});
                    const data=await response.json();
                    polling=false;
                    if(data.success){
                        // 100% відображається лише після успішної відповіді сервера про збережений ZIP.
                        bar.style.width='100%';label.textContent='100% — '+i18n.ready;
                        window.setTimeout(function(){window.location.href=data.data.redirect;},1100);
                    }else{
                        label.textContent='Error: '+(data.data&&data.data.message?data.data.message:i18n.genericError);
                        button.disabled=false;button.textContent=i18n.tryAgain;
                    }
                }catch(e){
                    polling=false;label.textContent=i18n.connectionError;
                    button.disabled=false;button.textContent=i18n.tryAgain;
                }
            });
        })();
        </script><?php
    }

    public function progress_ajax(): void {
        check_ajax_referer('uab_ajax', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => $this->t('permission_error')], 403);
        $runId = isset($_POST['run_id']) ? strtolower(sanitize_text_field(wp_unslash($_POST['run_id']))) : '';
        wp_send_json_success(UA_Backup_Engine::progress($runId));
    }

    public function create_ajax(): void {
        check_ajax_referer('uab_ajax', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => $this->t('permission_error')], 403);
        $runId = isset($_POST['run_id']) ? strtolower(sanitize_text_field(wp_unslash($_POST['run_id']))) : '';
        if (!preg_match('/^[a-f0-9]{24,64}$/', $runId)) wp_send_json_error(['message' => $this->t('generic_error')], 400);
        try {
            UA_Backup_Engine::reset_progress($runId);
            $result = UA_Backup_Engine::create_backup();
            clearstatcache(true, $result['path']);
            if (empty($result['path']) || !is_file($result['path']) || (int) filesize($result['path']) < 100) {
                throw new RuntimeException($this->t('generic_error'));
            }
            $message = 'Бекап створено: ' . $result['name'];
            set_transient('uab_last_result_' . get_current_user_id(), $message, 10 * MINUTE_IN_SECONDS);
            wp_send_json_success(['message' => $this->t('backup_created'), 'redirect' => add_query_arg('uab_done', '1', admin_url('tools.php?page=ua-backup'))]);
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }
    }

    public function create(): void {
        if (!current_user_can('manage_options')) wp_die($this->t('permission_error'));
        check_admin_referer('uab_create');
        try { $result=UA_Backup_Engine::create_backup(); $message='Бекап створено: '.$result['name']; }
        catch (Throwable $e) { $message='Помилка створення: '.$e->getMessage(); }
        set_transient('uab_last_result_' . get_current_user_id(), $message, 10 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg('uab_done', '1', admin_url('tools.php?page=ua-backup'))); exit;
    }

    public function download(): void {
        if (!current_user_can('manage_options')) wp_die($this->t('permission_error'));
        $name=isset($_GET['file'])?basename(wp_unslash($_GET['file'])):'';
        check_admin_referer('uab_download_'.$name); $path=UA_Backup_Engine::file_path($name);
        if (!$path) wp_die($this->t('file_missing'));
        nocache_headers(); header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="'.rawurlencode($name).'"');
        header('Content-Length: '.filesize($path)); readfile($path); exit;
    }

    public function delete(): void {
        if (!current_user_can('manage_options')) wp_die($this->t('permission_error'));
        $name=isset($_GET['file'])?basename(wp_unslash($_GET['file'])):'';
        check_admin_referer('uab_delete_'.$name);
        $message=UA_Backup_Engine::delete($name)?$this->t('delete_done'):$this->t('delete_error');
        wp_safe_redirect(add_query_arg('uab_notice', rawurlencode($message), admin_url('tools.php?page=ua-backup'))); exit;
    }
}
