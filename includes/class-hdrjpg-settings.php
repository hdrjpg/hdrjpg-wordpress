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
        'expand_sdr' => 1,
        'auto_convert' => 0,
    ];

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'fields']);
        add_filter('plugin_action_links_'.plugin_basename(HDRJPG_FILE), [self::class, 'actionLinks']);
        add_action('admin_enqueue_scripts', [self::class, 'styles']);
        add_action('admin_notices', [self::class, 'noKeyNotice']);
    }

    const SIGNUP_URL = 'https://hdrjpg.com/signup';
    const API_KEY_URL = 'https://hdrjpg.com/account/api-key';
    const EXAMPLES_URL = 'https://hdrjpg.com/examples';

    /**
     * The screens that remind an administrator without a key to add one: where they land after activating the plugin,
     * the dashboard, and the Media Library, where photos would otherwise fail to convert without them knowing why.
     */
    const NOTICE_SCREENS = ['plugins', 'dashboard', 'upload'];

    public static function hasKey(): bool
    {
        return (string)self::get('api_key') !== '';
    }

    private static function needsKeyNotice(): bool
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        return !self::hasKey() && current_user_can('manage_options') && $screen && in_array($screen->id, self::NOTICE_SCREENS, true);
    }

    public static function styles(string $hook): void
    {
        if ($hook !== 'settings_page_hdrjpg' && !self::needsKeyNotice()) {
            return;
        }
        wp_enqueue_style('hdrjpg-settings', HDRJPG_URL.'assets/settings.css', [], HDRJPG_VERSION);
    }

    /**
     * Gone by itself once a key is saved.
     */
    public static function noKeyNotice(): void
    {
        if (!self::needsKeyNotice()) {
            return;
        }
        echo '<div class="notice notice-info hdrjpg-notice">';
        echo '<img src="'.esc_url(HDRJPG_URL.'assets/logo.svg').'" alt="" width="40" height="40">';
        echo '<p><strong>'.esc_html__('HDRJPG is almost ready.', 'hdrjpg').'</strong> ';
        echo esc_html__('Add your free API key to start converting your HDR photos.', 'hdrjpg').'</p>';
        echo '<p><a class="button button-primary" href="'.esc_url(admin_url('options-general.php?page=hdrjpg')).'">'.esc_html__('Set up HDRJPG', 'hdrjpg').'</a></p>';
        echo '</div>';
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
        add_settings_field('expand_sdr', __('SDR photos', 'hdrjpg'), [self::class, 'fieldExpandSdr'], 'hdrjpg', 'hdrjpg_account');
        add_settings_field('auto_convert', __('New uploads', 'hdrjpg'), [self::class, 'fieldAutoConvert'], 'hdrjpg', 'hdrjpg_account');
    }

    public static function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];
        $current = get_option(self::OPTION, []);
        $apiKey = trim((string)($input['api_key'] ?? ''));
        // The welcome screen's form sends the key alone; the other settings stay as they are, where a missing
        // checkbox from the full form would mean "off"
        if (!empty($input['key_only'])) {
            if ($apiKey === '') {
                add_settings_error(self::OPTION, 'hdrjpg_no_key', __('Paste your API key first.', 'hdrjpg'));
            }
            $input = ['api_key' => $apiKey] + (is_array($current) ? $current : []) + self::DEFAULTS;
        }
        return [
            // An empty field keeps the saved key, which the form never shows
            'api_key' => $apiKey !== '' ? sanitize_text_field($apiKey) : (is_array($current) ? (string)($current['api_key'] ?? '') : ''),
            'widths' => sanitize_text_field((string)($input['widths'] ?? self::DEFAULTS['widths'])),
            'avif' => empty($input['avif']) ? 0 : 1,
            'mp4' => empty($input['mp4']) ? 0 : 1,
            'expand_sdr' => empty($input['expand_sdr']) ? 0 : 1,
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

    public static function fieldExpandSdr(): void
    {
        printf(
            '<label><input type="checkbox" name="%s[expand_sdr]" value="1" %s> %s</label>',
            esc_attr(self::OPTION),
            checked((bool)self::get('expand_sdr'), true, false),
            esc_html__('Convert SDR photos to HDR', 'hdrjpg')
        );
        echo '<p class="description">'.esc_html__('A photo without HDR, such as a normal JPG, gets its brightness range expanded artificially to HDR. It looks like the original, with brighter highlights on HDR screens. Otherwise, converting an SDR photo fails and does not count as a conversion.', 'hdrjpg').'</p>';
    }

    public static function fieldAutoConvert(): void
    {
        printf(
            '<label><input type="checkbox" name="%s[auto_convert]" value="1" %s> %s</label>',
            esc_attr(self::OPTION),
            checked((bool)self::get('auto_convert'), true, false),
            esc_html__('Convert photos as they are uploaded', 'hdrjpg')
        );
        echo '<p class="description">'.esc_html__('HEIC, EXR, and JPGs that carry a gain map. With "Convert SDR photos to HDR" on, every JPG.', 'hdrjpg').'</p>';
        echo '<p class="description">'.esc_html__('Otherwise, convert a photo from its details in the Media Library, or several at once with the Convert to HDR bulk action.', 'hdrjpg').'</p>';
    }

    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!self::hasKey()) {
            self::welcome();
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
    /**
     * What Settings > HDRJPG shows until a key is saved: why the plugin needs one, where to get it, and where to paste it.
     */
    private static function welcome(): void
    {
        $features = [
            [__('Keeps the HDR', 'hdrjpg'), __('iPhone HEIC, Android Ultra HDR JPG and EXR photos become web files that keep their full brightness.', 'hdrjpg')],
            [__('HDR in almost every browser', 'hdrjpg'), __('Your photos show in HDR in Chrome, Edge, Safari and Firefox on HDR screens, and as a normal photo everywhere else.', 'hdrjpg')],
            [__('Nothing to change on your site', 'hdrjpg'), __('Convert photos as you upload them, or later from the Media Library. Your theme and posts stay as they are.', 'hdrjpg')],
            [__('Free to start', 'hdrjpg'), __('300 conversions a month for free, no card needed. Free photos carry a small HDRJPG watermark; the paid plan removes it.', 'hdrjpg')],
        ];

        echo '<div class="wrap hdrjpg-welcome">';
        echo '<h1 class="hdrjpg-welcome-header"><img src="'.esc_url(HDRJPG_URL.'assets/logo.svg').'" alt="" width="56" height="56"> '.esc_html__('HDRJPG', 'hdrjpg').'</h1>';
        if (!class_exists('ZipArchive')) {
            echo '<div class="notice notice-error inline"><p>'.esc_html__('The PHP zip extension is missing on this server, and the plugin needs it to unpack the converted files. Ask your host to enable it.', 'hdrjpg').'</p></div>';
        }

        echo '<div class="hdrjpg-card">';
        echo '<h2>'.esc_html__('Show your photos in HDR', 'hdrjpg').'</h2>';
        echo '<p class="hdrjpg-lead">'.esc_html__('The plugin converts your photos with HDRJPG. To start, it needs your free HDRJPG API key.', 'hdrjpg').'</p>';
        echo '<p class="hdrjpg-examples"><a href="'.esc_url(self::EXAMPLES_URL).'" target="_blank" rel="noopener">'.esc_html__('See example photos in HDR on hdrjpg.com', 'hdrjpg').'</a></p>';
        echo '<ul class="hdrjpg-features">';
        foreach ($features as [$title, $text]) {
            echo '<li><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><div><strong>'.esc_html($title).'</strong><p>'.esc_html($text).'</p></div></li>';
        }
        echo '</ul>';
        echo '<p><a class="button button-primary button-hero" href="'.esc_url(self::SIGNUP_URL).'" target="_blank" rel="noopener">'.esc_html__('Get your free API key', 'hdrjpg').'</a></p>';
        echo '<p class="description">'.esc_html__('Sign up with your email address on hdrjpg.com, copy the key from your account, and paste it below.', 'hdrjpg').'</p>';
        echo '</div>';

        echo '<div class="hdrjpg-card">';
        echo '<form method="post" action="options.php">';
        settings_fields('hdrjpg');
        echo '<input type="hidden" name="'.esc_attr(self::OPTION).'[key_only]" value="1">';
        echo '<label for="hdrjpg_api_key"><strong>'.esc_html__('Already have a key? Paste it here', 'hdrjpg').'</strong></label>';
        echo '<div class="hdrjpg-key-row">';
        printf('<input type="password" id="hdrjpg_api_key" name="%s[api_key]" value="" class="regular-text" autocomplete="off">', esc_attr(self::OPTION));
        submit_button(__('Save key', 'hdrjpg'), 'primary', 'submit', false);
        echo '</div>';
        printf(
            '<p class="description">%s <a href="%s" target="_blank" rel="noopener">%s</a></p>',
            esc_html__('Your key is in your HDRJPG account.', 'hdrjpg'),
            esc_url(self::API_KEY_URL),
            esc_html__('Find your key', 'hdrjpg')
        );
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }
}
