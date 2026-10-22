<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings > HDRJPG.
 */
class Hdrjpg_Settings
{
    const OPTION = 'hdrjpg_settings';

    const DEFAULTS = [
        'api_key' => '',
        'widths' => '2400, 1200',
        'avif' => 1,
        'mp4' => 1,
        'auto_convert' => 0,
    ];

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'fields']);
        add_filter('plugin_action_links_'.plugin_basename(HDRJPG_FILE), [self::class, 'actionLinks']);
    }

    public static function get(string $key)
    {
        $settings = get_option(self::OPTION, []);
        return is_array($settings) && array_key_exists($key, $settings) ? $settings[$key] : self::DEFAULTS[$key];
    }

    /**
     * The widths to convert to, largest first.
     */
    public static function widths(): array
    {
        $widths = array_filter(array_map('intval', preg_split('/[\s,]+/', (string)self::get('widths'))), fn ($w) => $w >= 100 && $w <= 8192);
        $widths = array_values(array_unique($widths));
        rsort($widths);
        return $widths ?: [2400, 1200];
    }

    public static function menu(): void
    {
        add_options_page(__('HDRJPG', 'hdrjpg'), __('HDRJPG', 'hdrjpg'), 'manage_options', 'hdrjpg', [self::class, 'page']);
    }

    public static function actionLinks(array $links): array
    {
        array_unshift($links, '<a href="'.esc_url(admin_url('options-general.php?page=hdrjpg')).'">'.esc_html__('Settings', 'hdrjpg').'</a>');
        return $links;
    }

    public static function fields(): void
    {
        register_setting('hdrjpg', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default' => self::DEFAULTS,
        ]);

        add_settings_section('hdrjpg_account', '', '__return_false', 'hdrjpg');
        add_settings_field('api_key', __('API key', 'hdrjpg'), [self::class, 'fieldApiKey'], 'hdrjpg', 'hdrjpg_account', ['label_for' => 'hdrjpg_api_key']);
        add_settings_field('widths', __('Widths', 'hdrjpg'), [self::class, 'fieldWidths'], 'hdrjpg', 'hdrjpg_account', ['label_for' => 'hdrjpg_widths']);
        add_settings_field('formats', __('Extra formats', 'hdrjpg'), [self::class, 'fieldFormats'], 'hdrjpg', 'hdrjpg_account');
        add_settings_field('auto_convert', __('New uploads', 'hdrjpg'), [self::class, 'fieldAutoConvert'], 'hdrjpg', 'hdrjpg_account');
    }

    public static function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];
        $current = get_option(self::OPTION, []);
        $apiKey = trim((string)($input['api_key'] ?? ''));
        return [
            // An empty field keeps the saved key, which the form never shows
            'api_key' => $apiKey !== '' ? sanitize_text_field($apiKey) : (is_array($current) ? (string)($current['api_key'] ?? '') : ''),
            'widths' => sanitize_text_field((string)($input['widths'] ?? self::DEFAULTS['widths'])),
            'avif' => empty($input['avif']) ? 0 : 1,
            'mp4' => empty($input['mp4']) ? 0 : 1,
            'auto_convert' => empty($input['auto_convert']) ? 0 : 1,
        ];
    }

    public static function fieldApiKey(): void
    {
        $hasKey = self::get('api_key') !== '';
        printf(
            '<input type="password" id="hdrjpg_api_key" name="%s[api_key]" value="" class="regular-text" autocomplete="off" placeholder="%s">',
            esc_attr(self::OPTION),
            esc_attr($hasKey ? __('Saved. Enter a new key to replace it.', 'hdrjpg') : '')
        );
        echo '<p class="description">';
        printf(
            /* translators: %s: link to hdrjpg.com */
            wp_kses(__('Get your key from your account at %s. The free plan includes 300 conversions a month, with a watermark; the paid plan has no watermark and no cap.', 'hdrjpg'), ['a' => ['href' => [], 'target' => []]]),
            '<a href="https://hdrjpg.com/account" target="_blank">hdrjpg.com</a>'
        );
        echo '</p>';
    }

    public static function fieldWidths(): void
    {
        printf('<input type="text" id="hdrjpg_widths" name="%s[widths]" value="%s" class="regular-text">', esc_attr(self::OPTION), esc_attr((string)self::get('widths')));
        echo '<p class="description">'.esc_html__('The widths in pixels to make each photo in, separated by commas. Browsers pick the right one for the screen.', 'hdrjpg').'</p>';
    }

    public static function fieldFormats(): void
    {
        foreach (['avif' => __('AVIF, smaller, shown in HDR by Chrome, Edge and Safari 26', 'hdrjpg'), 'mp4' => __('MP4 video, for HDR in Firefox and in Safari on a Mac', 'hdrjpg')] as $key => $label) {
            printf(
                '<label><input type="checkbox" name="%s[%s]" value="1" %s> %s</label><br>',
                esc_attr(self::OPTION),
                esc_attr($key),
                checked((bool)self::get($key), true, false),
                esc_html($label)
            );
        }
        echo '<p class="description">'.esc_html__('A gain map JPEG is always made: it shows in HDR in Chrome, Edge and Safari 26, and as a normal photo everywhere else.', 'hdrjpg').' ';
        printf(
            /* translators: %d: number of files per photo */
            esc_html__('With these settings each photo becomes %d files and counts as one conversion. The free plan allows up to 5 files per photo.', 'hdrjpg'),
            count(self::widths()) * (self::get('avif') ? 2 : 1) + (self::get('mp4') ? 1 : 0)
        );
        echo '</p>';
    }

    public static function fieldAutoConvert(): void
    {
        printf(
            '<label><input type="checkbox" name="%s[auto_convert]" value="1" %s> %s</label>',
            esc_attr(self::OPTION),
            checked((bool)self::get('auto_convert'), true, false),
            esc_html__('Convert HDR photos as they are uploaded (HEIC, EXR, and JPGs that carry a gain map)', 'hdrjpg')
        );
        echo '<p class="description">'.esc_html__('Otherwise, convert a photo from its details in the Media Library, or several at once with the Convert to HDR bulk action.', 'hdrjpg').'</p>';
    }

    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="wrap"><h1>'.esc_html__('HDRJPG', 'hdrjpg').'</h1>';
        echo '<p>'.esc_html__('HDRJPG converts your HDR photos into files that keep their HDR, and shows them in HDR in almost every browser, on any HDR screen. On other screens they show as a normal photo.', 'hdrjpg').'</p>';
        if (!class_exists('ZipArchive')) {
            echo '<div class="notice notice-error inline"><p>'.esc_html__('The PHP zip extension is missing on this server, and the plugin needs it to unpack the converted files. Ask your host to enable it.', 'hdrjpg').'</p></div>';
        }
        echo '<form method="post" action="options.php">';
        settings_fields('hdrjpg');
        do_settings_sections('hdrjpg');
        submit_button();
        echo '</form></div>';
    }
}
