<?php
/**
 * Plugin Name: WLS Filters — Основные разделы
 * Description: Пользовательские фильтры основных разделов VIDMOV, метки в загрузке, редакторе и карточках.
 * Version: 1.3.6
 * Author: WELOVESISSY
 */

if (!defined('ABSPATH')) exit;

final class WLS_Main_Filters {
    const VERSION = '1.3.6';
    const TAX = 'wls_content_filter';
    const USER_META = '_wls_shown_content_filters';
    const COOKIE = 'wls_shown_content_filters';
    const NONCE = 'wls_main_filters_nonce';
    const DB_VERSION_OPT = 'wls_main_filters_db_version';
    const OLD_OPT = 'wls_main_filters_settings';

    private static $instance = null;
    private $table_settings;
    private $table_filters;
    private $settings_cache = null;
    private $filters_cache = [];

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_settings = $wpdb->prefix . 'wls_main_filter_settings';
        $this->table_filters  = $wpdb->prefix . 'wls_main_filter_definitions';

        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        add_action('plugins_loaded', [$this, 'maybe_upgrade'], 5);
        add_action('init', [$this, 'register_taxonomy'], 20);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_wls_main_filters_save', [$this, 'save_settings']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);

        add_action('beeteam368_sub_features_in_submit_form', [$this, 'render_submit_filters'], 30, 2);
        add_action('beeteam368_after_submit_post_success', [$this, 'save_submit_filters'], 30, 2);
        add_action('beeteam368_sub_features_in_edit_form', [$this, 'render_edit_filters'], 30, 2);

        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_admin_metabox'], 20, 2);

        add_action('pre_get_posts', [$this, 'filter_queries'], 999);
        add_action('wp_footer', [$this, 'render_frontend'], 99);
        add_shortcode('wls_main_filters', [$this, 'shortcode_filters']);

        add_action('wp_ajax_wls_save_content_filters', [$this, 'ajax_save_user_filters']);
        add_action('wp_ajax_nopriv_wls_save_content_filters', [$this, 'ajax_save_user_filters']);
        add_action('wp_ajax_wls_get_post_filter_badges', [$this, 'ajax_get_badges']);
        add_action('wp_ajax_nopriv_wls_get_post_filter_badges', [$this, 'ajax_get_badges']);

        add_action('rest_api_init', [$this, 'register_rest_api']);
    }

    public static function activate() {
        $from_version = (string)get_option(self::DB_VERSION_OPT, '');
        self::install_tables();

        $instance = self::instance();
        if ($from_version === '') {
            $instance->migrate_old_settings();
        }
        $instance->repair_cover_flags_after_upgrade($from_version);
    }

    private static function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $settings = $wpdb->prefix . 'wls_main_filter_settings';
        $filters  = $wpdb->prefix . 'wls_main_filter_definitions';

        dbDelta("CREATE TABLE {$settings} (
            id tinyint unsigned NOT NULL DEFAULT 1,
            enabled tinyint(1) NOT NULL DEFAULT 0,
            gear_enabled tinyint(1) NOT NULL DEFAULT 0,
            upload_enabled tinyint(1) NOT NULL DEFAULT 0,
            updated_at datetime NULL,
            PRIMARY KEY (id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$filters} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            filter_key varchar(100) NOT NULL,
            name varchar(190) NOT NULL,
            slug varchar(190) NOT NULL,
            color varchar(20) NOT NULL DEFAULT '#cf7ca5',
            note text NULL,
            note_gear tinyint(1) NOT NULL DEFAULT 1,
            note_upload tinyint(1) NOT NULL DEFAULT 1,
            note_shortcode tinyint(1) NOT NULL DEFAULT 1,
            note_cover tinyint(1) NOT NULL DEFAULT 1,
            enabled tinyint(1) NOT NULL DEFAULT 0,
            show_gear tinyint(1) NOT NULL DEFAULT 0,
            show_upload tinyint(1) NOT NULL DEFAULT 0,
            show_cover tinyint(1) NOT NULL DEFAULT 0,
            default_visible tinyint(1) NOT NULL DEFAULT 0,
            sort_order int NOT NULL DEFAULT 0,
            created_at datetime NULL,
            updated_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY enabled (enabled)
        ) {$charset};");

        $exists = $wpdb->get_var("SELECT id FROM {$settings} WHERE id=1");
        if (!$exists) {
            $wpdb->insert($settings, [
                'id'=>1, 'enabled'=>0, 'gear_enabled'=>0, 'upload_enabled'=>0,
                'updated_at'=>current_time('mysql')
            ], ['%d','%d','%d','%d','%s']);
        }

        $sfw = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$filters} WHERE slug=%s", 'sfw'));
        if (!$sfw) {
            $wpdb->insert($filters, [
                'filter_key'=>'sfw', 'name'=>'SFW (непорно-контент)', 'slug'=>'sfw', 'color'=>'#cf7ca5',
                'note'=>'Для доп. контента, вне сексуальной тематики. Важно: WELOVESISSY является adult-площадкой. Данный тег позволяет авторам порно добавить блоги, подкасты и прочее, оставив зрителям возможность скрыть их',
                'note_gear'=>1, 'note_upload'=>1, 'note_shortcode'=>1, 'note_cover'=>1,
                'enabled'=>0, 'show_gear'=>0, 'show_upload'=>0, 'show_cover'=>0, 'default_visible'=>0, 'sort_order'=>10,
                'created_at'=>current_time('mysql'), 'updated_at'=>current_time('mysql')
            ]);
        }
        $sfw_note = 'Для доп. контента, вне сексуальной тематики. Важно: WELOVESISSY является adult-площадкой. Данный тег позволяет авторам порно добавить блоги, подкасты и прочее, оставив зрителям возможность скрыть их';
        $wpdb->query($wpdb->prepare("UPDATE {$filters} SET note=%s WHERE slug=%s AND (note IS NULL OR note='')", $sfw_note, 'sfw'));
        update_option(self::DB_VERSION_OPT, self::VERSION, false);
    }

    public function maybe_upgrade() {
        $from_version = (string)get_option(self::DB_VERSION_OPT, '');
        if ($from_version === self::VERSION) return;

        self::install_tables();

        // Старые option-настройки переносим только один раз.
        // Повторная миграция при каждом обновлении сбрасывала новые поля, включая «На обложке».
        if ($from_version === '') {
            $this->migrate_old_settings();
        }

        $this->repair_cover_flags_after_upgrade($from_version);
    }

    private function repair_cover_flags_after_upgrade($from_version) {
        if ($from_version === '' || version_compare($from_version, '1.3.4', '>=')) return;

        global $wpdb;
        $enabled = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->table_filters} WHERE enabled=1");
        $cover   = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->table_filters} WHERE enabled=1 AND show_cover=1");

        // В 1.3.x старый мигратор мог обнулить show_cover у всех фильтров.
        // Если это произошло, возвращаем прежнее поведение один раз; дальше галочки снова полностью ручные.
        if ($enabled > 0 && $cover === 0) {
            $wpdb->query($wpdb->prepare("UPDATE {$this->table_filters} SET show_cover=1, updated_at=%s WHERE enabled=1", current_time('mysql')));
        }
    }

    private function migrate_old_settings() {
        global $wpdb;
        $old = get_option(self::OLD_OPT, null);
        if (!is_array($old)) return;

        $wpdb->update($this->table_settings, [
            'enabled'=>!empty($old['enabled']) ? 1 : 0,
            'gear_enabled'=>!empty($old['gear_enabled']) ? 1 : 0,
            'upload_enabled'=>!empty($old['upload_enabled']) ? 1 : 0,
            'updated_at'=>current_time('mysql')
        ], ['id'=>1]);

        if (!empty($old['filters']) && is_array($old['filters'])) {
            foreach ($old['filters'] as $i=>$f) {
                $name = sanitize_text_field($f['name'] ?? '');
                $slug = sanitize_title($f['slug'] ?? '');
                if (!$name || !$slug) continue;
                $color = sanitize_hex_color($f['color'] ?? '') ?: '#cf7ca5';
                $data = [
                    'filter_key'=>sanitize_key($f['id'] ?? $slug) ?: $slug,
                    'name'=>$name, 'slug'=>$slug, 'color'=>$color,
                    'enabled'=>!empty($f['enabled']) ? 1 : 0,
                    'show_gear'=>!empty($f['gear']) ? 1 : 0,
                    'show_upload'=>!empty($f['upload']) ? 1 : 0,
                    'show_cover'=>!empty($f['show_cover']) ? 1 : 0,
                    'default_visible'=>0,
                    'sort_order'=>(int)$i * 10,
                    'updated_at'=>current_time('mysql')
                ];
                $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table_filters} WHERE slug=%s", $slug));
                if ($id) $wpdb->update($this->table_filters, $data, ['id'=>(int)$id]);
                else { $data['created_at']=current_time('mysql'); $wpdb->insert($this->table_filters, $data); }
            }
        }
    }

    private function settings() {
        if ($this->settings_cache !== null) return $this->settings_cache;
        global $wpdb;
        $row = $wpdb->get_row("SELECT * FROM {$this->table_settings} WHERE id=1", ARRAY_A);
        if (!$row) return $this->settings_cache=['enabled'=>0,'gear_enabled'=>0,'upload_enabled'=>0];
        return $this->settings_cache=[
            'enabled'=>(int)$row['enabled'],
            'gear_enabled'=>(int)$row['gear_enabled'],
            'upload_enabled'=>(int)$row['upload_enabled']
        ];
    }

    private function filters($context='all', $only_enabled=false) {
        $cache_key=$context.'|'.($only_enabled?'1':'0');
        if(isset($this->filters_cache[$cache_key])) return $this->filters_cache[$cache_key];
        global $wpdb;
        $where = [];
        if ($only_enabled) $where[] = 'enabled=1';
        if ($context === 'gear') $where[] = 'show_gear=1';
        if ($context === 'upload') $where[] = 'show_upload=1';
        $sql = "SELECT * FROM {$this->table_filters}" . ($where ? ' WHERE '.implode(' AND ', $where) : '') . ' ORDER BY sort_order ASC, id ASC';
        return $this->filters_cache[$cache_key]=$wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    private function post_types() {
        $all = get_post_types(['public'=>true], 'names');
        $types = ['post'];
        foreach ($all as $type) if (strpos($type, 'vidmov_') === 0) $types[] = $type;
        return array_values(array_unique($types));
    }

    public function register_taxonomy() {
        register_taxonomy(self::TAX, $this->post_types(), [
            'labels'=>['name'=>'WLS фильтры','singular_name'=>'WLS фильтр'],
            'public'=>false, 'publicly_queryable'=>false, 'show_ui'=>false, 'show_in_menu'=>false,
            'show_in_nav_menus'=>false, 'show_tagcloud'=>false, 'show_in_rest'=>false,
            'hierarchical'=>false, 'rewrite'=>false, 'query_var'=>false
        ]);
        foreach ($this->filters() as $f) {
            $term = get_term_by('slug', $f['slug'], self::TAX);
            if (!$term) wp_insert_term($f['name'], self::TAX, ['slug'=>$f['slug']]);
            elseif (!is_wp_error($term) && $term->name !== $f['name']) wp_update_term($term->term_id, self::TAX, ['name'=>$f['name']]);
        }
    }

    public function admin_menu() {
        add_management_page('WLS фильтры (основ. разделы)','WLS фильтры (основ. разделы)','manage_options','wls-main-filters',[$this,'settings_page']);
    }

    public function admin_assets($hook) {
        if ($hook !== 'tools_page_wls-main-filters') return;
        wp_enqueue_style('wp-color-picker'); wp_enqueue_script('wp-color-picker');
    }

    public function save_settings() {
        if (!current_user_can('manage_options')) wp_die('Недостаточно прав.');
        check_admin_referer('wls_main_filters_save_action', 'wls_main_filters_nonce');
        global $wpdb;

        $wpdb->update($this->table_settings, [
            'enabled'=>isset($_POST['enabled']) ? 1 : 0,
            'gear_enabled'=>isset($_POST['gear_enabled']) ? 1 : 0,
            'upload_enabled'=>isset($_POST['upload_enabled']) ? 1 : 0,
            'updated_at'=>current_time('mysql')
        ], ['id'=>1]);

        $rows = isset($_POST['filters']) && is_array($_POST['filters']) ? wp_unslash($_POST['filters']) : [];
        $keep = [];
        foreach ($rows as $order=>$row) {
            $id = absint($row['db_id'] ?? 0);
            $name = sanitize_text_field($row['name'] ?? '');
            $slug = sanitize_title($row['slug'] ?? '');
            if (!$name) continue;
            if (!$slug) $slug = sanitize_title($name);
            if (!$slug) continue;
            $color = sanitize_hex_color($row['color'] ?? '') ?: '#cf7ca5';
            $data = [
                'filter_key'=>sanitize_key($row['filter_key'] ?? $slug) ?: $slug,
                'name'=>$name, 'slug'=>$slug, 'color'=>$color,
                'note'=>sanitize_textarea_field($row['note'] ?? ''),
                'note_gear'=>isset($row['note_gear']) ? 1 : 0,
                'note_upload'=>isset($row['note_upload']) ? 1 : 0,
                'note_shortcode'=>isset($row['note_shortcode']) ? 1 : 0,
                'note_cover'=>isset($row['note_cover']) ? 1 : 0,
                'enabled'=>isset($row['enabled']) ? 1 : 0,
                'show_gear'=>isset($row['show_gear']) ? 1 : 0,
                'show_upload'=>isset($row['show_upload']) ? 1 : 0,
                'show_cover'=>isset($row['show_cover']) ? 1 : 0,
                'default_visible'=>isset($row['default_visible']) ? 1 : 0,
                'sort_order'=>(int)$order * 10,
                'updated_at'=>current_time('mysql')
            ];
            if ($id && $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table_filters} WHERE id=%d", $id))) {
                $wpdb->update($this->table_filters, $data, ['id'=>$id]);
                $keep[]=$id;
            } else {
                $data['created_at']=current_time('mysql');
                $ok=$wpdb->insert($this->table_filters,$data);
                if ($ok) $keep[]=(int)$wpdb->insert_id;
            }
            $term=get_term_by('slug',$slug,self::TAX);
            if (!$term) wp_insert_term($name,self::TAX,['slug'=>$slug]);
            elseif (!is_wp_error($term) && $term->name!==$name) wp_update_term($term->term_id,self::TAX,['name'=>$name]);
        }
        if ($keep) {
            $ids=implode(',',array_map('absint',$keep));
            $wpdb->query("DELETE FROM {$this->table_filters} WHERE id NOT IN ({$ids})");
        }
        wp_safe_redirect(add_query_arg(['page'=>'wls-main-filters','updated'=>'1'],admin_url('tools.php'))); exit;
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) return;
        $s=$this->settings(); $filters=$this->filters();
        ?>
        <div class="wrap wls-mf-admin"><h1>WLS фильтры (основ. разделы)</h1>
        <?php if (!empty($_GET['updated'])) echo '<div class="notice notice-success is-dismissible"><p>Настройки сохранены в базе.</p></div>'; ?>
        <p>Все функции после установки выключены. Настройки и определения фильтров хранятся в собственных таблицах плагина.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="wls_main_filters_save"><?php wp_nonce_field('wls_main_filters_save_action','wls_main_filters_nonce'); ?>
        <table class="form-table"><tr><th>Система фильтров</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked($s['enabled'],1); ?>> Включить</label></td></tr>
        <tr><th>Шестерёнка в разделах</th><td><label><input type="checkbox" name="gear_enabled" value="1" <?php checked($s['gear_enabled'],1); ?>> Показывать рядом со штатными фильтрами VIDMOV</label></td></tr>
        <tr><th>Фильтры в форме загрузки</th><td><label><input type="checkbox" name="upload_enabled" value="1" <?php checked($s['upload_enabled'],1); ?>> Показывать разрешённые галочки авторам</label></td></tr></table>
        <h2>Фильтры</h2>
        <p>«Включён» активирует фильтр в системе. «В шестерёнке» разрешает пользователю управлять показом такого контента. «В загрузке» добавляет галочку автору. «На обложке» выводит метку на карточке публикации. Все параметры независимы.</p>
        <table class="widefat striped" id="wls-mf-table"><thead><tr><th>Название</th><th>Slug</th><th>Цвет</th><th>Памятка</th><th>Подсказка: шестерёнка</th><th>Подсказка: загрузка/редактор</th><th>Подсказка: шорткод</th><th>Подсказка: обложка</th><th>Включён</th><th>В шестерёнке</th><th>В загрузке</th><th>На обложке</th><th>Показывать по умолчанию</th><th></th></tr></thead><tbody>
        <?php foreach($filters as $i=>$f): ?><tr>
        <td><input type="hidden" name="filters[<?php echo $i; ?>][db_id]" value="<?php echo (int)$f['id']; ?>"><input type="hidden" name="filters[<?php echo $i; ?>][filter_key]" value="<?php echo esc_attr($f['filter_key']); ?>"><input class="regular-text" name="filters[<?php echo $i; ?>][name]" value="<?php echo esc_attr($f['name']); ?>" required></td>
        <td><input name="filters[<?php echo $i; ?>][slug]" value="<?php echo esc_attr($f['slug']); ?>" required></td>
        <td><input class="wls-color" name="filters[<?php echo $i; ?>][color]" value="<?php echo esc_attr($f['color']); ?>"></td>
        <td><textarea name="filters[<?php echo $i; ?>][note]" rows="3" style="min-width:260px;width:100%"><?php echo esc_textarea($f['note'] ?? ''); ?></textarea></td>
        <td><input type="checkbox" name="filters[<?php echo $i; ?>][note_gear]" value="1" <?php checked((int)($f['note_gear'] ?? 1),1); ?>></td>
        <td><input type="checkbox" name="filters[<?php echo $i; ?>][note_upload]" value="1" <?php checked((int)($f['note_upload'] ?? 1),1); ?>></td>
        <td><input type="checkbox" name="filters[<?php echo $i; ?>][note_shortcode]" value="1" <?php checked((int)($f['note_shortcode'] ?? 1),1); ?>></td>
        <td><input type="checkbox" name="filters[<?php echo $i; ?>][note_cover]" value="1" <?php checked((int)($f['note_cover'] ?? 1),1); ?>></td>
        <td><input type="checkbox" name="filters[<?php echo $i; ?>][enabled]" value="1" <?php checked((int)$f['enabled'],1); ?>></td>
        <td><input type="checkbox" name="filters[<?php echo $i; ?>][show_gear]" value="1" <?php checked((int)$f['show_gear'],1); ?>></td>
        <td><input type="checkbox" name="filters[<?php echo $i; ?>][show_upload]" value="1" <?php checked((int)$f['show_upload'],1); ?>></td>
        <td><input type="checkbox" name="filters[<?php echo $i; ?>][show_cover]" value="1" <?php checked((int)$f['show_cover'],1); ?>></td>
        <td><input type="checkbox" name="filters[<?php echo $i; ?>][default_visible]" value="1" <?php checked((int)$f['default_visible'],1); ?>></td>
        <td><button type="button" class="button-link-delete wls-remove-row">Удалить</button></td></tr><?php endforeach; ?>
        </tbody></table><p><button type="button" class="button" id="wls-add-filter">Добавить фильтр</button></p><?php submit_button('Сохранить настройки'); ?></form></div>
        <script>jQuery(function($){$('.wls-color').wpColorPicker();var idx=<?php echo count($filters); ?>;$('#wls-add-filter').on('click',function(){var i=idx++;$('#wls-mf-table tbody').append('<tr><td><input type="hidden" name="filters['+i+'][db_id]" value="0"><input type="hidden" name="filters['+i+'][filter_key]" value=""><input class="regular-text" name="filters['+i+'][name]" required></td><td><input name="filters['+i+'][slug]" placeholder="из названия"></td><td><input class="wls-color-new" name="filters['+i+'][color]" value="#cf7ca5"></td><td><textarea name="filters['+i+'][note]" rows="3" style="min-width:260px;width:100%"></textarea></td><td><input type="checkbox" name="filters['+i+'][note_gear]" value="1" checked></td><td><input type="checkbox" name="filters['+i+'][note_upload]" value="1" checked></td><td><input type="checkbox" name="filters['+i+'][note_shortcode]" value="1" checked></td><td><input type="checkbox" name="filters['+i+'][note_cover]" value="1" checked></td><td><input type="checkbox" name="filters['+i+'][enabled]" value="1"></td><td><input type="checkbox" name="filters['+i+'][show_gear]" value="1"></td><td><input type="checkbox" name="filters['+i+'][show_upload]" value="1"></td><td><input type="checkbox" name="filters['+i+'][show_cover]" value="1"></td><td><input type="checkbox" name="filters['+i+'][default_visible]" value="1"></td><td><button type="button" class="button-link-delete wls-remove-row">Удалить</button></td></tr>');$('#wls-mf-table tbody tr:last .wls-color-new').wpColorPicker()});$(document).on('click','.wls-remove-row',function(){if(confirm('Убрать фильтр из настроек? Метки публикаций останутся в базе.'))$(this).closest('tr').remove()})});</script>
        <?php
    }

    private function enabled_filters($context='all') {
        $s=$this->settings(); if (!$s['enabled']) return [];
        return $this->filters($context,true);
    }

    private function render_submit_filter_styles() {
        echo '<style>
        .wls-submit-filter-list{display:flex!important;flex-wrap:wrap!important;gap:8px!important;margin-top:8px!important;align-items:flex-start!important}
        .wls-submit-filter-item{position:relative!important;display:inline-flex!important;flex-flow:row nowrap!important;align-items:center!important;justify-content:flex-start!important;gap:7px!important;width:auto!important;max-width:100%!important;min-height:38px!important;padding:8px 11px!important;margin:0!important;border:1px solid rgba(255,255,255,.14)!important;border-radius:7px!important;box-sizing:border-box!important;cursor:pointer!important;line-height:18px!important}
        .wls-submit-filter-item .wls-submit-filter-checkbox{appearance:auto!important;-webkit-appearance:checkbox!important;position:static!important;inset:auto!important;float:none!important;display:inline-block!important;flex:0 0 16px!important;width:16px!important;height:16px!important;min-width:16px!important;max-width:16px!important;min-height:16px!important;max-height:16px!important;margin:0!important;padding:0!important;vertical-align:middle!important;transform:none!important}
        .wls-submit-filter-item.is-locked{cursor:default!important;opacity:.9!important}
        .wls-submit-filter-name{display:inline-block!important;flex:0 1 auto!important;width:auto!important;margin:0!important;padding:0!important;line-height:18px!important;white-space:normal!important}
        .wls-submit-filter-lock{display:inline-flex!important;align-items:center!important;justify-content:center!important;flex:0 0 16px!important;width:16px!important;height:16px!important;font-size:11px!important;color:#aaa!important}
        .wls-submit-filter-info{position:relative!important;display:inline-flex!important;flex:0 0 18px!important;width:18px!important;height:18px!important;align-items:center!important;justify-content:center!important;margin:0 0 0 2px!important;color:var(--wls-filter-color)!important;cursor:help!important;line-height:18px!important}
        .wls-submit-filter-info>i{font-size:14px!important;line-height:18px!important}
        @media(max-width:600px){.wls-submit-filter-item{width:100%!important}}
        </style>';
    }

    private function render_filter_fields_block($filters, $selected = [], $locked = []) {
        $selected = is_array($selected) ? $selected : [];
        $locked = is_array($locked) ? $locked : [];
        $lock_for_user = !current_user_can('manage_options');

        echo '<input type="hidden" name="wls_content_filters_present" value="1">';
        echo '<div class="data-item wls-main-filter-submit">';
        echo '<label class="h5">Доп. теги</label>';
        echo '<em class="data-item-desc font-size-12">Отметьте подходящие признаки публикации.</em>';
        echo '<div class="wls-submit-filter-list">';

        foreach ($filters as $f) {
            $slug = $f['slug'];
            $checked = in_array($slug, $selected, true);
            $is_locked = $lock_for_user && in_array($slug, $locked, true);

            echo '<label class="wls-submit-filter-item'.($is_locked ? ' is-locked' : '').'" style="--wls-filter-color:'.esc_attr($f['color']).'">';
            echo '<input class="wls-submit-filter-checkbox" type="checkbox" name="wls_content_filters[]" value="'.esc_attr($slug).'" '.checked($checked, true, false).($is_locked ? ' disabled' : '').'>';
            if ($is_locked && $checked) {
                echo '<input type="hidden" name="wls_content_filters[]" value="'.esc_attr($slug).'">';
            }
            echo '<span class="wls-submit-filter-name">'.esc_html($f['name']).'</span>';
            if ($is_locked) {
                echo '<span class="wls-submit-filter-lock" title="Тег заблокирован администратором"><i class="fas fa-lock" aria-hidden="true"></i></span>';
            }
            if (!empty($f['note']) && !empty($f['note_upload'])) {
                echo '<span class="wls-mf-info wls-submit-filter-info" tabindex="0" role="button" aria-label="Памятка" data-wls-note="'.esc_attr($f['note']).'"><i class="fas fa-info-circle" aria-hidden="true"></i></span>';
            }
            echo '</label>';
        }

        echo '</div></div>';
        $this->render_submit_filter_styles();
    }

    public function render_submit_filters($arr_tab_submit=null,$default_switch_post_type=null) {
        $s=$this->settings();
        if (!$s['enabled'] || !$s['upload_enabled']) return;
        $filters=$this->enabled_filters('upload');
        if(!$filters) return;
        $this->render_filter_fields_block($filters, [], []);
    }

    public function render_edit_filters($post_id, $post_type=null) {
        $s=$this->settings();
        if (!$s['enabled'] || !$s['upload_enabled'] || !$post_id) return;

        $filters=$this->enabled_filters('upload');
        if(!$filters) return;

        $post=get_post((int)$post_id);
        if(!$post || !in_array($post->post_type,$this->post_types(),true)) return;

        $current=wp_get_object_terms((int)$post_id,self::TAX,['fields'=>'slugs']);
        if(is_wp_error($current)) $current=[];
        $locked=get_post_meta((int)$post_id,'_wls_locked_filter_slugs',true);
        if(!is_array($locked)) $locked=[];

        $this->render_filter_fields_block($filters, $current, $locked);
    }

    public function save_submit_filters($post_id,$post_type=null) {
        $s=$this->settings();
        if(!$s['enabled']||!$s['upload_enabled']||!$post_id)return;

        // Не трогаем теги, если форма вообще не содержит поля WLS.
        // Скрытое поле добавляется и в создание, и в VIDMOV-редактор.
        if(!isset($_POST['wls_content_filters_present'])) return;

        $allowed=wp_list_pluck($this->enabled_filters('upload'),'slug');
        $selected=isset($_POST['wls_content_filters'])?(array)wp_unslash($_POST['wls_content_filters']):[];
        $selected=array_values(array_intersect(array_map('sanitize_title',$selected),$allowed));

        $current=wp_get_object_terms((int)$post_id,self::TAX,['fields'=>'slugs']);
        if(is_wp_error($current))$current=[];

        // Автор управляет только теми фильтрами, которые разрешены в загрузке/редакторе.
        // Админские и прочие скрытые от автора теги сохраняются как есть.
        $preserve=array_values(array_diff($current,$allowed));

        $locked=get_post_meta((int)$post_id,'_wls_locked_filter_slugs',true);
        if(!is_array($locked))$locked=[];
        if(!current_user_can('manage_options')){
            foreach($locked as $slug){
                if(in_array($slug,$current,true) && !in_array($slug,$preserve,true))$preserve[]=$slug;
            }
        }

        $final=array_values(array_unique(array_merge($preserve,$selected)));
        wp_set_object_terms((int)$post_id,$final,self::TAX,false);
    }

    public function add_meta_boxes() {
        if(!current_user_can('manage_options'))return;
        foreach($this->post_types() as $type)add_meta_box('wls-main-filters-box','WLS фильтры',[$this,'render_metabox'],$type,'side','high');
    }
    public function render_metabox($post) {
        wp_nonce_field('wls_admin_filters_save','wls_admin_filters_nonce');
        $current=wp_get_object_terms($post->ID,self::TAX,['fields'=>'slugs']); if(is_wp_error($current))$current=[];
        $locked=get_post_meta($post->ID,'_wls_locked_filter_slugs',true); if(!is_array($locked))$locked=[];
        echo '<p style="margin-top:0">Добавить тег и при необходимости запретить автору его снимать:</p>';
        foreach($this->filters() as $f){
            $checked=in_array($f['slug'],$current,true); $is_locked=in_array($f['slug'],$locked,true);
            echo '<div style="margin:8px 0;padding:7px 8px;border:1px solid #dcdcde;border-radius:5px">';
            echo '<label style="display:flex;align-items:center;gap:7px"><input type="checkbox" name="wls_admin_filters[]" value="'.esc_attr($f['slug']).'" '.checked($checked,true,false).'><span style="width:10px;height:10px;border-radius:50%;background:'.esc_attr($f['color']).';display:inline-block"></span><span>'.esc_html($f['name']).'</span>'.(!empty($f['note'])?' <span title="'.esc_attr($f['note']).'" style="cursor:help">ⓘ</span>':'').'</label>';
            echo '<label style="display:flex;align-items:center;gap:6px;margin:7px 0 0 23px;font-size:12px"><input type="checkbox" name="wls_admin_locked_filters[]" value="'.esc_attr($f['slug']).'" '.checked($is_locked,true,false).'> Заблокировать для автора</label>';
            echo '</div>';
        }
    }
    public function save_admin_metabox($post_id,$post) {
        if(!current_user_can('manage_options')||!in_array($post->post_type,$this->post_types(),true))return;
        if(defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)return;
        if(empty($_POST['wls_admin_filters_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wls_admin_filters_nonce'])),'wls_admin_filters_save'))return;
        $allowed=wp_list_pluck($this->filters(),'slug');
        $selected=isset($_POST['wls_admin_filters'])?(array)wp_unslash($_POST['wls_admin_filters']):[];
        $selected=array_values(array_intersect(array_map('sanitize_title',$selected),$allowed));
        $locked=isset($_POST['wls_admin_locked_filters'])?(array)wp_unslash($_POST['wls_admin_locked_filters']):[];
        $locked=array_values(array_intersect(array_map('sanitize_title',$locked),$selected));
        wp_set_object_terms($post_id,$selected,self::TAX,false);
        update_post_meta($post_id,'_wls_locked_filter_slugs',$locked);
    }

    private function default_shown_slugs() {
        $shown = [];
        foreach ($this->enabled_filters('gear') as $f) {
            if (!empty($f['default_visible'])) $shown[] = $f['slug'];
        }
        return $shown;
    }

    private function user_shown_slugs() {
        $allowed = wp_list_pluck($this->enabled_filters('gear'), 'slug');
        if (!$allowed) return [];

        $has_saved = false;
        $saved = [];

        if (is_user_logged_in()) {
            $uid = get_current_user_id();
            $has_saved = metadata_exists('user', $uid, self::USER_META);
            if ($has_saved) {
                $saved = get_user_meta($uid, self::USER_META, true);
                $saved = is_array($saved) ? $saved : [];
            }
        } else if (isset($_COOKIE[self::COOKIE])) {
            $has_saved = true;
            $decoded = json_decode(stripslashes($_COOKIE[self::COOKIE]), true);
            $saved = is_array($decoded) ? $decoded : [];
        }

        if (!$has_saved) $saved = $this->default_shown_slugs();

        return array_values(array_intersect(array_map('sanitize_title', $saved), $allowed));
    }

    private function user_hidden_slugs() {
        $allowed = wp_list_pluck($this->enabled_filters('gear'), 'slug');
        if (!$allowed) return [];
        $shown = $this->user_shown_slugs();
        return array_values(array_diff($allowed, $shown));
    }

    public function filter_queries($query) {
        if(is_admin()&&!wp_doing_ajax())return; $s=$this->settings(); if(!$s['enabled']||!$s['gear_enabled'])return;
        $hidden=$this->user_hidden_slugs(); if(!$hidden)return; $pt=$query->get('post_type'); $target=false;
        if(is_string($pt)&&in_array($pt,$this->post_types(),true))$target=true; if(is_array($pt)&&array_intersect($pt,$this->post_types()))$target=true;
        if(!$target&&!$query->is_main_query())return;
        $tax=$query->get('tax_query');if(!is_array($tax))$tax=[];$tax[]=['taxonomy'=>self::TAX,'field'=>'slug','terms'=>$hidden,'operator'=>'NOT IN'];$query->set('tax_query',$tax);
    }

    public function ajax_save_user_filters() {
        check_ajax_referer(self::NONCE,'nonce');$s=$this->settings();if(!$s['enabled']||!$s['gear_enabled'])wp_send_json_error(['message'=>'disabled'],403);
        $allowed=wp_list_pluck($this->enabled_filters('gear'),'slug');$shown=isset($_POST['filters'])?(array)wp_unslash($_POST['filters']):[];$shown=array_values(array_intersect(array_map('sanitize_title',$shown),$allowed));
        if(is_user_logged_in())update_user_meta(get_current_user_id(),self::USER_META,$shown);else{$value=wp_json_encode($shown);setcookie(self::COOKIE,$value,time()+YEAR_IN_SECONDS,COOKIEPATH?:'/',COOKIE_DOMAIN,is_ssl(),true);$_COOKIE[self::COOKIE]=$value;}
        wp_send_json_success(['filters'=>$shown]);
    }

    public function ajax_get_badges() {
        check_ajax_referer(self::NONCE,'nonce');
        $ids=isset($_POST['ids'])?(array)wp_unslash($_POST['ids']):[];
        $ids=array_slice(array_values(array_unique(array_filter(array_map('absint',$ids)))),0,100);
        if(!$ids) wp_send_json_success([]);
        $defs=[];
        foreach($this->filters() as $f){
            if(!empty($f['enabled'])&&!empty($f['show_cover'])){
                $defs[$f['slug']]=[
                    'name'=>$f['name'],
                    'color'=>$f['color'],
                    'note'=>(!empty($f['note_cover']) ? ($f['note'] ?? '') : '')
                ];
            }
        }
        if(!$defs) wp_send_json_success([]);
        $terms=wp_get_object_terms($ids,self::TAX,['fields'=>'all_with_object_id']);
        if(is_wp_error($terms)) wp_send_json_success([]);
        $out=[];
        foreach($terms as $term){
            $oid=(int)$term->object_id;
            if(isset($defs[$term->slug])) $out[$oid][]=$defs[$term->slug];
        }
        wp_send_json_success($out);
    }

    /**
     * Нормализованный объект метки для REST API и внешних endpoint'ов.
     * Возвращает все настройки метки, чтобы другим интеграциям не приходилось
     * читать внутреннюю таблицу плагина напрямую.
     */
    private function api_filter_payload($filter) {
        return [
            'id'              => isset($filter['id']) ? (int)$filter['id'] : 0,
            'key'             => isset($filter['filter_key']) ? (string)$filter['filter_key'] : '',
            'name'            => isset($filter['name']) ? (string)$filter['name'] : '',
            'slug'            => isset($filter['slug']) ? (string)$filter['slug'] : '',
            'color'           => isset($filter['color']) ? (string)$filter['color'] : '',
            'note'            => isset($filter['note']) ? (string)$filter['note'] : '',
            'enabled'         => !empty($filter['enabled']),
            'show_gear'       => !empty($filter['show_gear']),
            'show_upload'     => !empty($filter['show_upload']),
            'show_cover'      => !empty($filter['show_cover']),
            'default_visible' => !empty($filter['default_visible']),
            'note_locations'  => [
                'gear'      => !empty($filter['note_gear']),
                'upload'    => !empty($filter['note_upload']),
                'shortcode' => !empty($filter['note_shortcode']),
                'cover'     => !empty($filter['note_cover']),
            ],
            'sort_order'      => isset($filter['sort_order']) ? (int)$filter['sort_order'] : 0,
        ];
    }

    /**
     * Публичный PHP API: все метки плагина.
     * Используется также глобальной функцией wls_main_filters_get_all_labels().
     */
    public function api_all_filters($only_enabled = false) {
        $rows = $this->filters('all', (bool)$only_enabled);
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->api_filter_payload($row);
        }
        return $out;
    }

    /**
     * Публичный PHP API: метки сразу для нескольких публикаций.
     * Один вызов wp_get_object_terms() на весь массив ID — без N+1 запросов.
     */
    public function api_posts_filters($post_ids, $only_enabled = false) {
        $ids = array_slice(
            array_values(array_unique(array_filter(array_map('absint', (array)$post_ids)))),
            0,
            100
        );

        if (!$ids) return [];

        $definitions = [];
        foreach ($this->filters('all', (bool)$only_enabled) as $row) {
            $definitions[$row['slug']] = $row;
        }

        $out = [];
        foreach ($ids as $id) $out[$id] = [];

        $terms = wp_get_object_terms($ids, self::TAX, ['fields' => 'all_with_object_id']);
        if (is_wp_error($terms)) return $out;

        foreach ($terms as $term) {
            $post_id = isset($term->object_id) ? (int)$term->object_id : 0;
            if (!$post_id || !array_key_exists($post_id, $out)) continue;

            if (isset($definitions[$term->slug])) {
                $item = $this->api_filter_payload($definitions[$term->slug]);
            } else {
                // Термин мог остаться у публикации после удаления определения
                // из настроек. В API его всё равно отдаём, чтобы данные не терялись.
                if ($only_enabled) continue;
                $item = [
                    'id'              => 0,
                    'key'             => (string)$term->slug,
                    'name'            => (string)$term->name,
                    'slug'            => (string)$term->slug,
                    'color'           => '',
                    'note'            => '',
                    'enabled'         => false,
                    'show_gear'       => false,
                    'show_upload'     => false,
                    'show_cover'      => false,
                    'default_visible' => false,
                    'note_locations'  => [
                        'gear' => false, 'upload' => false, 'shortcode' => false, 'cover' => false,
                    ],
                    'sort_order'      => PHP_INT_MAX,
                ];
            }

            $item['term_id'] = (int)$term->term_id;
            $out[$post_id][] = $item;
        }

        foreach ($out as $post_id => &$items) {
            usort($items, function($a, $b) {
                $a_order = isset($a['sort_order']) ? (int)$a['sort_order'] : PHP_INT_MAX;
                $b_order = isset($b['sort_order']) ? (int)$b['sort_order'] : PHP_INT_MAX;
                if ($a_order === $b_order) return strcasecmp((string)$a['name'], (string)$b['name']);
                return $a_order <=> $b_order;
            });
        }
        unset($items);

        return $out;
    }

    /**
     * Публичный PHP API: метки одной публикации.
     * Используется также глобальной функцией wls_main_filters_get_post_labels().
     */
    public function api_post_filters($post_id, $only_enabled = false) {
        $post_id = absint($post_id);
        if (!$post_id) return [];
        $batch = $this->api_posts_filters([$post_id], $only_enabled);
        return isset($batch[$post_id]) ? $batch[$post_id] : [];
    }

    private function rest_post_is_readable($post_id) {
        $post = get_post(absint($post_id));
        if (!$post) return false;

        if ($post->post_status === 'publish' && is_post_type_viewable($post->post_type)) {
            return true;
        }

        return current_user_can('read_post', $post->ID);
    }

    public function register_rest_api() {
        $namespace = 'wls-filters/v1';

        register_rest_route($namespace, '/filters', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'rest_get_all_filters'],
            'permission_callback' => '__return_true',
            'args'                => [
                'enabled' => [
                    'description'       => '1 — вернуть только включённые метки.',
                    'required'          => false,
                    'default'           => false,
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ],
            ],
        ]);

        // Алиас с термином labels — удобен внешним интеграциям.
        register_rest_route($namespace, '/labels', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'rest_get_all_filters'],
            'permission_callback' => '__return_true',
            'args'                => [
                'enabled' => [
                    'required'          => false,
                    'default'           => false,
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ],
            ],
        ]);

        register_rest_route($namespace, '/post/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'rest_get_post_filters'],
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => [
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function($value) { return absint($value) > 0; },
                ],
                'enabled' => [
                    'required'          => false,
                    'default'           => false,
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ],
            ],
        ]);

        register_rest_route($namespace, '/post/(?P<id>\d+)/labels', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'rest_get_post_filters'],
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => [
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function($value) { return absint($value) > 0; },
                ],
                'enabled' => [
                    'required'          => false,
                    'default'           => false,
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ],
            ],
        ]);

        register_rest_route($namespace, '/posts', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'rest_get_posts_filters'],
            'permission_callback' => '__return_true',
            'args'                => [
                'ids' => [
                    'description' => 'ID через запятую либо массив ID. Максимум 100.',
                    'required'    => true,
                ],
                'enabled' => [
                    'required'          => false,
                    'default'           => false,
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ],
            ],
        ]);

        // Стандартные WP REST endpoint'ы публикаций автоматически получают
        // поле wls_filters. Никаких дополнительных запросов со стороны клиента не нужно.
        register_rest_field($this->post_types(), 'wls_filters', [
            'get_callback' => function($object) {
                $post_id = 0;
                if (is_array($object) && !empty($object['id'])) $post_id = absint($object['id']);
                elseif (is_object($object) && !empty($object->ID)) $post_id = absint($object->ID);
                return $post_id ? $this->api_post_filters($post_id, false) : [];
            },
            'schema' => [
                'description' => 'Метки WLS Filters, назначенные публикации.',
                'type'        => 'array',
                'context'     => ['view', 'edit'],
                'readonly'    => true,
                'items'       => ['type' => 'object'],
            ],
        ]);
    }

    public function rest_get_all_filters($request) {
        $only_enabled = rest_sanitize_boolean($request->get_param('enabled'));
        $items = $this->api_all_filters($only_enabled);

        return rest_ensure_response([
            'taxonomy' => self::TAX,
            'count'    => count($items),
            'filters'  => $items,
        ]);
    }

    public function rest_get_post_filters($request) {
        $post_id = absint($request['id']);
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('wls_post_not_found', 'Публикация не найдена.', ['status' => 404]);
        }
        if (!$this->rest_post_is_readable($post_id)) {
            return new WP_Error('wls_post_forbidden', 'Нет доступа к публикации.', ['status' => 403]);
        }

        $only_enabled = rest_sanitize_boolean($request->get_param('enabled'));
        $items = $this->api_post_filters($post_id, $only_enabled);

        return rest_ensure_response([
            'post_id'   => $post_id,
            'post_type' => $post->post_type,
            'count'     => count($items),
            'slugs'     => array_values(array_map(function($item) { return $item['slug']; }, $items)),
            'filters'   => $items,
        ]);
    }

    public function rest_get_posts_filters($request) {
        $raw_ids = $request->get_param('ids');
        if (is_string($raw_ids)) $raw_ids = preg_split('/[\s,;]+/', $raw_ids, -1, PREG_SPLIT_NO_EMPTY);
        $ids = array_slice(array_values(array_unique(array_filter(array_map('absint', (array)$raw_ids)))), 0, 100);

        if (!$ids) {
            return new WP_Error('wls_invalid_post_ids', 'Не переданы корректные ID публикаций.', ['status' => 400]);
        }

        $readable = [];
        foreach ($ids as $id) {
            if ($this->rest_post_is_readable($id)) $readable[] = $id;
        }

        $only_enabled = rest_sanitize_boolean($request->get_param('enabled'));
        $map = $this->api_posts_filters($readable, $only_enabled);

        return rest_ensure_response([
            'requested_count' => count($ids),
            'returned_count'  => count($readable),
            'posts'           => $map,
        ]);
    }

    public function shortcode_filters() {
        $s = $this->settings();
        if (!$s['enabled']) return '';
        $filters = $this->enabled_filters('gear');
        if (!$filters) return '';
        $shown = $this->user_shown_slugs();
        ob_start();
        ?>
        <div class="wls-mf-shortcode" data-wls-filter-controls="1">
            <?php foreach ($filters as $f): ?>
                <label class="wls-mf-option">
                    <input type="checkbox" value="<?php echo esc_attr($f['slug']); ?>" <?php checked(in_array($f['slug'], $shown, true)); ?>>
                    <span class="wls-mf-dot" style="background:<?php echo esc_attr($f['color']); ?>"></span>
                    <span><?php echo esc_html($f['name']); ?></span>
                    <?php if (!empty($f['note']) && !empty($f['note_shortcode'])): ?><span class="wls-mf-info" tabindex="0" role="button" aria-label="Памятка" data-wls-note="<?php echo esc_attr($f['note']); ?>"><i class="fas fa-info-circle" aria-hidden="true"></i></span><?php endif; ?>
                </label>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function render_frontend() {
        if(is_admin()) return;
        $s=$this->settings();
        if(!$s['enabled']) return;
        $gear=$s['gear_enabled']?$this->enabled_filters('gear'):[];$shown=$this->user_shown_slugs();$data=[];
        foreach($gear as $f)$data[]=['name'=>$f['name'],'slug'=>$f['slug'],'color'=>$f['color'],'note'=>(!empty($f['note_gear']) ? ($f['note'] ?? '') : ''),'shown'=>in_array($f['slug'],$shown,true)];
        $single_id=is_singular($this->post_types()) ? (int)get_queried_object_id() : 0;
        ?>
<style>
.blog-info-filter>.posts-filter{display:flex!important;align-items:center!important;gap:10px!important;flex-wrap:nowrap!important}.blog-info-filter>.posts-filter>.filter-block{flex:0 0 auto!important}.wls-mf-gear-wrap{position:relative;display:inline-flex;align-items:center;flex:0 0 auto!important;margin:0!important;padding:0!important;width:auto!important;max-width:none!important}.wls-mf-gear{width:36px!important;height:36px!important;min-width:36px!important;min-height:36px!important;padding:0!important;border:1px solid rgba(255,255,255,.16);border-radius:6px;background:#202226;color:#fff;display:flex!important;align-items:center!important;justify-content:center!important;cursor:pointer;line-height:1!important;font-size:13px!important}.wls-mf-gear:hover,.wls-mf-gear.is-open{background:#2c2f33;border-color:rgba(255,255,255,.34)}
.wls-mf-panel{display:none!important;position:fixed!important;left:12px;top:12px;z-index:2147483646!important;width:290px;max-width:calc(100vw - 24px);box-sizing:border-box;padding:12px;background:#171717;border:1px solid rgba(255,255,255,.15);border-radius:7px;box-shadow:0 12px 35px rgba(0,0,0,.65);isolation:isolate}.wls-mf-panel.is-open{display:block!important}.wls-mf-title{font-weight:600;color:#fff;margin:0 0 8px}.wls-mf-option{display:flex!important;align-items:center!important;gap:9px!important;padding:8px 4px!important;color:#eee!important;cursor:pointer!important;line-height:1.35!important}.wls-mf-option input{margin:0!important;flex:0 0 auto!important}.wls-mf-dot{width:10px;height:10px;border-radius:50%;flex:0 0 10px}.wls-mf-info{display:inline-flex!important;align-items:center!important;justify-content:center!important;flex:0 0 auto!important;margin-left:2px!important;color:#aaa!important;cursor:help!important;font-size:11px!important;line-height:1!important}.wls-mf-info:hover,.wls-mf-info:focus{color:#fff!important}.wls-mf-note-pop{position:fixed!important;z-index:2147483647!important;width:310px!important;max-width:calc(100vw - 24px)!important;padding:10px 12px!important;box-sizing:border-box!important;background:#17191c!important;border:1px solid rgba(255,255,255,.18)!important;border-radius:7px!important;box-shadow:0 12px 35px rgba(0,0,0,.7)!important;color:#eee!important;font:400 12px/1.5 Poppins,sans-serif!important;text-align:left!important;white-space:normal!important}.wls-mf-saving{opacity:.55;pointer-events:none}
.wls-mf-shortcode{display:flex;flex-direction:column;gap:2px;width:100%;box-sizing:border-box}.wls-mf-shortcode .wls-mf-option{margin:0!important}
.wls-mf-badges{display:flex;flex-wrap:wrap;gap:4px;align-items:center}.wls-mf-card-badges{position:absolute!important;right:7px!important;bottom:7px!important;z-index:20!important;justify-content:flex-end!important;max-width:calc(100% - 14px)!important;pointer-events:none!important}.wls-mf-badge{display:inline-flex;align-items:center;padding:1px 5px;border-radius:3px;border:1px solid var(--wls-c);color:var(--wls-c);font-family:Poppins,sans-serif;font-size:9px;font-weight:600;line-height:1.35;background:rgba(16,16,16,.88);white-space:nowrap;gap:4px}.wls-mf-badge-info{color:var(--wls-c)!important;font-size:9px!important;margin-left:1px!important;pointer-events:auto!important}.wls-mf-single-badges{position:absolute!important;pointer-events:none!important;right:10px!important;bottom:10px!important;z-index:30!important;justify-content:flex-end!important;max-width:calc(100% - 20px)!important}.blog-info-filter>.total-posts{margin-left:auto}
@media(max-width:767px){.blog-info-filter>.posts-filter{gap:8px!important}.wls-mf-gear-wrap{margin:0!important}.wls-mf-panel{width:290px;max-width:calc(100vw - 24px)}.wls-mf-badge{font-size:8px;padding:1px 4px}}
</style>
<script>
(function(){
var filters=<?php echo wp_json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>,ajaxUrl=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,nonce=<?php echo wp_json_encode(wp_create_nonce(self::NONCE)); ?>,singleId=<?php echo (int)$single_id; ?>;
function currentValues(source){var vals=[];(source||document).querySelectorAll('.wls-mf-option input:checked').forEach(function(i){if(vals.indexOf(i.value)<0)vals.push(i.value)});return vals}
function saveValues(vals,source){if(source)source.classList.add('wls-mf-saving');var body=new URLSearchParams();body.append('action','wls_save_content_filters');body.append('nonce',nonce);vals.forEach(function(v){body.append('filters[]',v)});fetch(ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()}).then(function(r){return r.json()}).then(function(r){if(r&&r.success)location.reload();else if(source)source.classList.remove('wls-mf-saving')}).catch(function(){if(source)source.classList.remove('wls-mf-saving')})}
function initShortcodes(){document.querySelectorAll('.wls-mf-shortcode:not([data-wls-ready])').forEach(function(box){box.setAttribute('data-wls-ready','1');box.querySelectorAll('input').forEach(function(i){i.addEventListener('change',function(){saveValues(currentValues(box),box)})})})}
function gear(){if(!filters.length||document.querySelector('.wls-mf-gear-wrap'))return;var host=document.querySelector('.blog-info-filter');if(!host)return;var pf=host.querySelector(':scope > .posts-filter')||host.querySelector('.posts-filter');if(!pf)return;var w=document.createElement('div');w.className='wls-mf-gear-wrap';var b=document.createElement('button');b.type='button';b.className='wls-mf-gear';b.title='Фильтры контента';b.innerHTML='<i class="fas fa-cog"></i>';var p=document.createElement('div');p.className='wls-mf-panel';p.innerHTML='<div class="wls-mf-title">Показывать</div>';filters.forEach(function(f){var l=document.createElement('label');l.className='wls-mf-option';l.innerHTML='<input type="checkbox" value="'+f.slug+'" '+(f.shown?'checked':'')+'><span class="wls-mf-dot" style="background:'+f.color+'"></span><span></span>';l.lastElementChild.textContent=f.name;if(f.note){var inf=document.createElement('span');inf.className='wls-mf-info';inf.tabIndex=0;inf.setAttribute('role','button');inf.setAttribute('aria-label','Памятка');inf.setAttribute('data-wls-note',f.note);inf.innerHTML='<i class="fas fa-info-circle" aria-hidden="true"></i>';l.appendChild(inf)}l.querySelector('input').addEventListener('change',function(){saveValues(currentValues(p),p)});p.appendChild(l)});
function placePanel(){var r=b.getBoundingClientRect(),gap=7,pad=12,pw=Math.min(290,window.innerWidth-(pad*2));p.style.width=pw+'px';var left=r.left;if(left+pw>window.innerWidth-pad)left=window.innerWidth-pad-pw;if(left<pad)left=pad;var ph=p.offsetHeight||120;var below=window.innerHeight-r.bottom-gap,top;if(below>=ph||r.top<ph+gap+pad)top=r.bottom+gap;else top=r.top-gap-ph;if(top<pad)top=pad;if(top+ph>window.innerHeight-pad)top=Math.max(pad,window.innerHeight-pad-ph);p.style.left=Math.round(left)+'px';p.style.top=Math.round(top)+'px'}
b.onclick=function(e){e.stopPropagation();var opening=!p.classList.contains('is-open');b.classList.toggle('is-open',opening);p.classList.toggle('is-open',opening);if(opening){placePanel();requestAnimationFrame(placePanel)}};p.onclick=function(e){e.stopPropagation()};document.addEventListener('click',function(){b.classList.remove('is-open');p.classList.remove('is-open')});window.addEventListener('resize',function(){if(p.classList.contains('is-open'))placePanel()},{passive:true});window.addEventListener('scroll',function(){if(p.classList.contains('is-open'))placePanel()},{passive:true});w.appendChild(b);var fb=pf.querySelector(':scope > .filter-block')||pf.querySelector('.filter-block');if(fb)fb.insertAdjacentElement('afterend',w);else pf.appendChild(w);document.body.appendChild(p)}
function badgeBox(items,cls){var box=document.createElement('div');box.className='wls-mf-badges '+cls;items.forEach(function(x){var s=document.createElement('span');s.className='wls-mf-badge';s.style.setProperty('--wls-c',x.color);s.textContent=x.name;if(x.note){var inf=document.createElement('span');inf.className='wls-mf-info wls-mf-badge-info';inf.tabIndex=0;inf.setAttribute('role','button');inf.setAttribute('aria-label','Памятка');inf.setAttribute('data-wls-note',x.note);inf.innerHTML='<i class="fas fa-info-circle" aria-hidden="true"></i>';s.appendChild(inf)}box.appendChild(s)});return box}
function cardPostId(card){var el=card.querySelector('[data-post-id]');if(el){var n=parseInt(el.getAttribute('data-post-id'),10);if(n)return n}var post=card.closest&&card.closest('.post-item');if(post){var cm=(post.className||'').match(/(?:^|\s)post-(\d+)(?:\s|$)/);if(cm)return parseInt(cm[1],10)}var a=card.querySelector('a[href]');if(a){var m=(a.href||'').match(/[?&]p=(\d+)/);if(m)return parseInt(m[1],10)}return 0}
function collect(){var ids=[];document.querySelectorAll('.post-item-wrap').forEach(function(card){var id=cardPostId(card);if(id&&ids.indexOf(id)<0)ids.push(id)});if(singleId&&ids.indexOf(singleId)<0)ids.push(singleId);return ids}

/*
 * Метки загружаются батчами. Один запрос получает данные сразу для нескольких
 * публикаций; запросы выполняются последовательно, чтобы wp-admin/admin-ajax.php
 * не получал всплеск параллельных обращений при больших лентах.
 */
var BADGE_BATCH_SIZE=24,MAX_BADGE_PARALLEL=1;
var badgeCache={},badgeQueue=[],badgeQueued={},badgePending={},badgeActive=0;

function paintBadges(){document.querySelectorAll('.post-item-wrap').forEach(function(card){var id=cardPostId(card),items=badgeCache[id];var old=card.querySelector('.wls-mf-card-badges');if(old)old.remove();if(!items||!items.length)return;var cover=card.querySelector('.post-featured-image');if(!cover)return;cover.appendChild(badgeBox(items,'wls-mf-card-badges'))});if(singleId){var banner=document.querySelector('.player-banner.play-media-control');if(banner){var old=banner.querySelector('.wls-mf-single-badges');if(old)old.remove();var items=badgeCache[singleId];if(items&&items.length)banner.appendChild(badgeBox(items,'wls-mf-single-badges'))}}}

function enqueueBadgeIds(ids){ids.forEach(function(id){if(!id||Object.prototype.hasOwnProperty.call(badgeCache,id)||badgePending[id]||badgeQueued[id])return;badgeQueued[id]=1;badgeQueue.push(id)})}

function requestBadgeBatch(batch){var body=new URLSearchParams();body.append('action','wls_get_post_filter_badges');body.append('nonce',nonce);batch.forEach(function(id){body.append('ids[]',id)});return fetch(ajaxUrl,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()}).then(function(r){return r.json()}).then(function(r){if(!r||!r.success)throw new Error('badge_request_failed');var map=r.data||{};batch.forEach(function(id){badgeCache[id]=map[String(id)]||map[id]||[]})})}

function pumpBadgeQueue(){while(badgeActive<MAX_BADGE_PARALLEL&&badgeQueue.length){var batch=badgeQueue.splice(0,BADGE_BATCH_SIZE);batch.forEach(function(id){delete badgeQueued[id];badgePending[id]=1});badgeActive++;(function(ids){requestBadgeBatch(ids).then(function(){paintBadges()}).catch(function(){/* При следующем run() неудачный батч будет поставлен в очередь снова. */}).finally(function(){ids.forEach(function(id){delete badgePending[id]});badgeActive--;pumpBadgeQueue()})})(batch)}}

function badges(){var ids=collect();paintBadges();enqueueBadgeIds(ids);pumpBadgeQueue()}
var notePop=null;function hideNote(){if(notePop){notePop.remove();notePop=null}}function showNote(el){var txt=el.getAttribute('data-wls-note');if(!txt)return;hideNote();notePop=document.createElement('div');notePop.className='wls-mf-note-pop';notePop.textContent=txt;document.body.appendChild(notePop);var r=el.getBoundingClientRect(),pad=12,gap=7,w=Math.min(310,window.innerWidth-pad*2),h=notePop.offsetHeight;var left=r.left+r.width/2-w/2;if(left<pad)left=pad;if(left+w>window.innerWidth-pad)left=window.innerWidth-pad-w;var top=r.bottom+gap;if(top+h>window.innerHeight-pad)top=r.top-gap-h;if(top<pad)top=pad;notePop.style.left=Math.round(left)+'px';notePop.style.top=Math.round(top)+'px'}document.addEventListener('mouseover',function(e){var el=e.target.closest&&e.target.closest('.wls-mf-info[data-wls-note]');if(el)showNote(el)});document.addEventListener('mouseout',function(e){var el=e.target.closest&&e.target.closest('.wls-mf-info[data-wls-note]');if(el)hideNote()});document.addEventListener('focusin',function(e){var el=e.target.closest&&e.target.closest('.wls-mf-info[data-wls-note]');if(el)showNote(el)});document.addEventListener('focusout',function(e){var el=e.target.closest&&e.target.closest('.wls-mf-info[data-wls-note]');if(el)hideNote()});document.addEventListener('click',function(e){var el=e.target.closest&&e.target.closest('.wls-mf-info[data-wls-note]');if(el){e.preventDefault();e.stopPropagation();if(notePop)hideNote();else showNote(el)}});window.addEventListener('scroll',hideNote,{passive:true});window.addEventListener('resize',hideNote,{passive:true});
function run(){gear();initShortcodes();badges()}
function scheduleRun(delay){clearTimeout(window.__wlsMfT);window.__wlsMfT=setTimeout(run,typeof delay==='number'?delay:180)}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',function(){scheduleRun(50)});else scheduleRun(50);

/*
 * VIDMOV Load More работает через XHR. MutationObserver оставлен как лёгкий
 * резерв, а завершение XHR даёт гарантированный повторный проход после AJAX.
 * Подход совпадает с рабочим сниппетом вывода цен WELOVESISSY.
 */
(function(open){XMLHttpRequest.prototype.open=function(){this.addEventListener('load',function(){scheduleRun(300)});return open.apply(this,arguments)}})(XMLHttpRequest.prototype.open);

var mo=new MutationObserver(function(muts){var relevant=false;for(var i=0;i<muts.length&&!relevant;i++){for(var j=0;j<muts[i].addedNodes.length;j++){var n=muts[i].addedNodes[j];if(n.nodeType!==1)continue;if((n.matches&&n.matches('.post-item-wrap,.blog-info-filter,.wls-mf-shortcode,.player-banner.play-media-control'))||(n.querySelector&&n.querySelector('.post-item-wrap,.blog-info-filter,.wls-mf-shortcode,.player-banner.play-media-control'))){relevant=true;break}}}if(relevant)scheduleRun(180)});mo.observe(document.body||document.documentElement,{childList:true,subtree:true});
})();
</script>
        <?php
    }
}
WLS_Main_Filters::instance();

/**
 * PHP helper для любых других endpoint'ов/плагинов.
 * Пример: wls_main_filters_get_all_labels();
 */
if (!function_exists('wls_main_filters_get_all_labels')) {
    function wls_main_filters_get_all_labels($only_enabled = false) {
        return WLS_Main_Filters::instance()->api_all_filters((bool)$only_enabled);
    }
}

/**
 * PHP helper для любых других endpoint'ов/плагинов.
 * Пример: wls_main_filters_get_post_labels(123);
 */
if (!function_exists('wls_main_filters_get_post_labels')) {
    function wls_main_filters_get_post_labels($post_id, $only_enabled = false) {
        return WLS_Main_Filters::instance()->api_post_filters(absint($post_id), (bool)$only_enabled);
    }
}

/**
 * Батч-helper для endpoint'ов, которые отдают сразу много публикаций.
 * Делает один taxonomy-запрос максимум на 100 ID.
 */
if (!function_exists('wls_main_filters_get_posts_labels')) {
    function wls_main_filters_get_posts_labels($post_ids, $only_enabled = false) {
        return WLS_Main_Filters::instance()->api_posts_filters((array)$post_ids, (bool)$only_enabled);
    }
}