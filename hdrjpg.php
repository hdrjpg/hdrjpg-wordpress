<?php
/**
 * Plugin Name:       HDRJPG – HDR photos
 * Plugin URI:        https://github.com/hdrjpg/hdrjpg-wordpress
 * Description:       Converts the HDR photos in your Media Library (iPhone HEIC, Android Ultra HDR JPG, EXR) into web-ready HDR files with the HDRJPG API, and shows them in HDR in almost every browser.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            HDRJPG
 * Author URI:        https://hdrjpg.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hdrjpg
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HDRJPG_VERSION', '1.0.0');
define('HDRJPG_FILE', __FILE__);
define('HDRJPG_DIR', plugin_dir_path(__FILE__));
define('HDRJPG_URL', plugin_dir_url(__FILE__));

require_once HDRJPG_DIR.'includes/class-hdrjpg-api.php';
require_once HDRJPG_DIR.'includes/class-hdrjpg-converter.php';
require_once HDRJPG_DIR.'includes/class-hdrjpg-settings.php';
require_once HDRJPG_DIR.'includes/class-hdrjpg-media.php';
require_once HDRJPG_DIR.'includes/class-hdrjpg-frontend.php';

Hdrjpg_Settings::register();
Hdrjpg_Media::register();
Hdrjpg_Frontend::register();
Hdrjpg_Converter::register();
