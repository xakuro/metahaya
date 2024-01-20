<?php
/**
 * Metahaya
 *
 * @package metahaya
 * @author  ishitaka
 * @license GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Metahaya
 * Plugin URI:        https://xakuro.com/wordpress/
 * Description:       Custom fields search acceleration plugin for WordPress.
 * Version:           1.0.0-alpha-1
 * Requires at least: 5.5
 * Requires PHP:      7.4
 * Author:            Xakuro
 * Author URI:        https://xakuro.com/
 * License:           GPL v2 or later
 * Text Domain:       metahaya
 * Domain Path:       /languages
 *
 * Update URI:        https://github.com/xakuro/metahaya
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'METAHAYA_VERSION', '1.0.0-alpha-1' );

require_once __DIR__ . '/class-metahaya-main.php';
require_once __DIR__ . '/class-metahaya-meta-query.php';

global $metahaya;
$metahaya = new Metahaya_Main();

register_uninstall_hook( __FILE__, 'Metahaya_Main::uninstall' );
