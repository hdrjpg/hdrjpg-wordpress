<?php
/**
 * Removes the plugin's settings. Converted files and their records stay, so photos already in posts keep working
 * if the plugin is installed again; delete wp-content/uploads/hdrjpg to remove the files.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('hdrjpg_settings');
wp_unschedule_hook('hdrjpg_check_conversion');
