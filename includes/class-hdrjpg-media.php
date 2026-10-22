<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The Media Library side: an HDRJPG field in each photo's details, and a bulk action.
 */
class Hdrjpg_Media
{
    public static function register(): void
    {
        add_filter('attachment_fields_to_edit', [self::class, 'field'], 10, 2);
        add_action('admin_enqueue_scripts', [self::class, 'scripts']);
        add_action('wp_ajax_hdrjpg_convert', [self::class, 'ajaxConvert']);
        add_action('wp_ajax_hdrjpg_status', [self::class, 'ajaxStatus']);
        add_filter('bulk_actions-upload', [self::class, 'bulkActions']);
        add_filter('handle_bulk_actions-upload', [self::class, 'handleBulkAction'], 10, 3);
        add_action('admin_notices', [self::class, 'bulkNotice']);
    }

    public static function scripts(): void
    {
        wp_enqueue_script('hdrjpg-admin', HDRJPG_URL.'assets/admin.js', [], HDRJPG_VERSION, true);
        wp_localize_script('hdrjpg-admin', 'hdrjpgAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hdrjpg'),
        ]);
    }

    public static function field(array $fields, WP_Post $post): array
    {
        if (!Hdrjpg_Converter::isSource($post->ID)) {
            return $fields;
        }
        $fields['hdrjpg'] = [
            'label' => __('HDR', 'hdrjpg'),
            'input' => 'html',
            'html' => '<div class="hdrjpg-field" data-id="'.esc_attr((string)$post->ID).'">'.self::fieldHtml($post->ID).'</div>',
        ];
        return $fields;
    }

    /**
     * The field's content for the conversion's current state.
     */
    public static function fieldHtml(int $attachmentId): string
    {
        $state = Hdrjpg_Converter::state($attachmentId);
        $status = $state['status'] ?? '';
        $button = fn (string $label) => '<button type="button" class="button hdrjpg-convert">'.esc_html($label).'</button>';

        if ($status === 'running') {
            return '<p class="hdrjpg-running"><span class="spinner is-active" style="float:none;margin:0 4px 0 0"></span>'.esc_html__('Converting with HDRJPG…', 'hdrjpg').'</p>';
        }
        if ($status === 'ready') {
            $formats = array_unique(array_map(fn ($f) => $f['format'] === 'jpeg-xt' ? 'JPEG' : strtoupper($f['format']), $state['files']));
            return '<p>'.esc_html(sprintf(
                /* translators: 1: number of files, 2: formats */
                __('Converted: %1$d files (%2$s). Wherever this photo is used in a post or page, it shows in HDR.', 'hdrjpg'),
                count($state['files']),
                implode(', ', $formats)
            )).'</p>'.$button(__('Convert again', 'hdrjpg'));
        }
        if ($status === 'failed') {
            return '<p class="hdrjpg-error" style="color:#b32d2e">'.esc_html($state['error'] ?? __('The conversion failed.', 'hdrjpg')).'</p>'.$button(__('Try again', 'hdrjpg'));
        }
        return '<p>'.esc_html__('Make this photo show in HDR on your site.', 'hdrjpg').'</p>'.$button(__('Convert to HDR', 'hdrjpg'));
    }

    private static function checkRequest(): int
    {
        check_ajax_referer('hdrjpg', 'nonce');
        $attachmentId = isset($_POST['id']) ? absint($_POST['id']) : 0;
        if (!$attachmentId || get_post_type($attachmentId) !== 'attachment' || !current_user_can('edit_post', $attachmentId)) {
            wp_send_json_error(['message' => __('You cannot convert this file.', 'hdrjpg')], 403);
        }
        return $attachmentId;
    }

    public static function ajaxConvert(): void
    {
        $attachmentId = self::checkRequest();
        if ((Hdrjpg_Converter::state($attachmentId)['status'] ?? '') !== 'running') {
            try {
                Hdrjpg_Converter::start($attachmentId);
            } catch (Exception $e) {
                $state = Hdrjpg_Converter::state($attachmentId);
                $state['status'] = 'failed';
                $state['error'] = $e->getMessage();
                update_post_meta($attachmentId, Hdrjpg_Converter::META, $state);
            }
        }
        self::respond($attachmentId);
    }

    public static function ajaxStatus(): void
    {
        $attachmentId = self::checkRequest();
        Hdrjpg_Converter::check($attachmentId);
        self::respond($attachmentId);
    }

    private static function respond(int $attachmentId): void
    {
        wp_send_json_success([
            'status' => Hdrjpg_Converter::state($attachmentId)['status'] ?? '',
            'html' => self::fieldHtml($attachmentId),
        ]);
    }

    public static function bulkActions(array $actions): array
    {
        $actions['hdrjpg_convert'] = __('Convert to HDR', 'hdrjpg');
        return $actions;
    }

    public static function handleBulkAction(string $redirect, string $action, array $ids): string
    {
        if ($action !== 'hdrjpg_convert') {
            return $redirect;
        }
        $started = 0;
        $failed = 0;
        foreach (array_map('absint', $ids) as $id) {
            if (!current_user_can('edit_post', $id) || !Hdrjpg_Converter::isSource($id) || (Hdrjpg_Converter::state($id)['status'] ?? '') === 'running') {
                continue;
            }
            try {
                Hdrjpg_Converter::start($id);
                $started++;
            } catch (Exception $e) {
                $failed++;
                $state = Hdrjpg_Converter::state($id);
                $state['status'] = 'failed';
                $state['error'] = $e->getMessage();
                update_post_meta($id, Hdrjpg_Converter::META, $state);
            }
        }
        return add_query_arg(['hdrjpg_started' => $started, 'hdrjpg_failed' => $failed], $redirect);
    }

    public static function bulkNotice(): void
    {
        if (!isset($_GET['hdrjpg_started']) || get_current_screen()->id !== 'upload') {
            return;
        }
        $started = absint($_GET['hdrjpg_started']);
        $failed = absint($_GET['hdrjpg_failed'] ?? 0);
        echo '<div class="notice notice-'.($failed ? 'warning' : 'success').' is-dismissible"><p>';
        echo esc_html(sprintf(
            /* translators: %d: number of photos */
            _n('%d photo is being converted to HDR. It takes a minute or two.', '%d photos are being converted to HDR. It takes a minute or two each.', $started, 'hdrjpg'),
            $started
        ));
        if ($failed) {
            echo ' '.esc_html(sprintf(
                /* translators: %d: number of photos */
                _n('%d could not start; open it to see why.', '%d could not start; open them to see why.', $failed, 'hdrjpg'),
                $failed
            ));
        }
        echo '</p></div>';
    }
}
