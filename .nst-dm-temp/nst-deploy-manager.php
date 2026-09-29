<?php
/**
 * Plugin Name: NST Deploy Manager
 * Description: Централизованный deploy/rollback WordPress-плагинов с отдельным self-deploy и аварийным rollback менеджера.
 * Version: 0.1.1
 * Author: NST
 * Requires at least: 6.4
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('NST_DM_VERSION', '0.1.1');
define('NST_DM_FILE', __FILE__);
define('NST_DM_DIR', plugin_dir_path(__FILE__));
define('NST_DM_SLUG', basename(NST_DM_DIR));

require_once NST_DM_DIR . 'includes/class-nst-deploy-manager.php';

register_activation_hook(__FILE__, ['NST_Deploy_Manager', 'activate']);

add_action('plugins_loaded', static function (): void {
    NST_Deploy_Manager::init();
});
