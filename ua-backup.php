<?php
/*
 * Plugin Name: UA Backup
 * Plugin URI:  https://uaserver.pp.ua
 * Description: Швидке та надійне резервне копіювання сайтів від UAServer. Доступний перехід на довічну PRO ліцензію за 599 грн (PayPal) для повної автоматизації.
 * Version:     1.0.0
 * Author:      UAServer
 * Author URI:  https://uaserver.pp.ua
 * License:     GPLv2 or later
 * Text Domain: ua-backup
 */

if (!defined('ABSPATH')) {
    exit;
}

// 1. ДИНАМІЧНЕ ПЕРЕМИКАННЯ МОВИ (UA / EN)
add_action('plugins_loaded', 'uab_load_textdomain');
function uab_load_textdomain() {
    $locale = determine_locale();
    if ($locale !== 'uk') {
        unload_textdomain('ua-backup');
        load_textdomain('ua-backup', plugin_dir_path(__FILE__) . 'languages/ua-backup-en_US.mo');
    } else {
        load_plugin_textdomain('ua-backup', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
}

// 2. ІНТЕГРАЦІЯ В МЕНЮ "UAServer plugins"
add_action('admin_menu', 'uab_register_sub_menu', 20);
function uab_register_sub_menu() {
    add_submenu_page(
        'uaserver-plugins',              
        'UA Backup',                     
        'UA Backup',                     
        'manage_options',                
        'ua-backup',                     
        'uab_render_admin_page'          
    );
}

function uab_render_admin_page() {
    $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'backup';
    
    $tabs = [
        'backup' => __('Резервне копіювання', 'ua-backup'),
        'go_pro' => __('Отримати PRO 🚀', 'ua-backup')
    ];

    echo '<div class="wrap"><h1>UA Backup</h1>';
    ?>
    <h2 class="nav-tab-wrapper" style="margin-bottom: 20px;">
        <?php foreach ($tabs as $tab_slug => $tab_title) : 
            $active_class = ($current_tab === $tab_slug) ? 'nav-tab-active' : '';
            $tab_url = admin_url('admin.php?page=ua-backup&tab=' . $tab_slug);
        ?>
            <a href="<?php echo esc_url($tab_url); ?>" class="nav-tab <?php echo esc_attr($active_class); ?>"><?php echo esc_html($tab_title); ?></a>
        <?php endforeach; ?>
    </h2>
    <?php

    if ($current_tab === 'backup') {
        echo '<div class="card"><h2>' . __('Створення резервної копії', 'ua-backup') . '</h2>';
        echo '<p>' . __('Тут розташовується ваш поточний стандартний функціонал створення бекапу.', 'ua-backup') . '</p></div>';
    } 
    elseif ($current_tab === 'go_pro') {
        ?>
        <div class="card" style="max-width: 650px; margin-top: 20px; padding: 20px; background: #fff; border-left: 4px solid #d54e21;">
            <h2>Переходьте на UA Backup Pro</h2>
            <p>Безкоштовна версія підтримує тільки ручне створення копій. Отримайте **повну автоматизацію за розкладом** у Pro версії!</p>
            <p>Вартість довічної ліцензії: <strong>599 грн</strong>. Оплата через <strong>PayPal</strong>.</p>
            <p><a href="https://uaserver.pp.ua" target="_blank" class="button button-primary button-large" style="background:#d54e21; border-color:#bc3b11;">Придбати ліцензію на UAServer</a></p>
        </div>
        <?php
    }
    echo '</div>';
}
