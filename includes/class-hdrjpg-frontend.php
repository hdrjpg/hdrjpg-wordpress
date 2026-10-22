<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Serves converted photos with the HDRJPG markup wherever WordPress outputs them: in post and page content,
 * and through wp_get_attachment_image() (featured images, galleries and most themes).
 */
class Hdrjpg_Frontend
{
    // From HDRJPG's own embed markup: lets Edge pick the AVIF, as the official embed does
    const AVIF_MEDIA = 'all and (min--moz-device-pixel-ratio:0) and (min-resolution: 3e1dpcm) {}';

    public static function register(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'registerScript']);
        add_filter('wp_content_img_tag', [self::class, 'filterContentImage'], 20, 3);
        add_filter('wp_get_attachment_image', [self::class, 'filterAttachmentImage'], 20, 2);
    }

    public static function registerScript(): void
    {
        wp_register_script('hdrjpg', HDRJPG_URL.'assets/hdrjpg.js', [], '1.2.0', ['in_footer' => true, 'strategy' => 'defer']);
    }

    public static function filterContentImage(string $html, string $context, int $attachmentId): string
    {
        return $attachmentId ? self::embed($html, $attachmentId) : $html;
    }

    public static function filterAttachmentImage(string $html, int $attachmentId): string
    {
        return is_admin() ? $html : self::embed($html, $attachmentId);
    }

    /**
     * Turns an <img> of a converted photo into an HDRJPG embed. Anything else comes back unchanged.
     */
    public static function embed(string $html, int $attachmentId): string
    {
        $state = Hdrjpg_Converter::state($attachmentId);
        if (($state['status'] ?? '') !== 'ready' || strpos($html, 'data-hdrjpg') !== false) {
            return $html;
        }

        $byFormat = [];
        foreach ($state['files'] as $file) {
            $byFormat[$file['format']][] = $file;
        }
        if (empty($byFormat['jpeg-xt'])) {
            return $html;
        }
        $largest = $byFormat['jpeg-xt'][0];

        $img = new WP_HTML_Tag_Processor($html);
        if (!$img->next_tag('img')) {
            return $html;
        }

        // A cropped size (a square thumbnail, say) shows another framing than the converted photo: leave it as it is
        $width = (int)$img->get_attribute('width');
        $height = (int)$img->get_attribute('height');
        if ($width && $height && $largest['height'] && abs($width / $height - $largest['width'] / $largest['height']) > 0.02) {
            return $html;
        }

        $sizes = $img->get_attribute('sizes');
        if (!is_string($sizes) || $sizes === '' || $sizes === 'auto') {
            $displayWidth = $width ?: $largest['width'];
            $sizes = ($sizes === 'auto' ? 'auto, ' : '').sprintf('(max-width: %1$dpx) 100vw, %1$dpx', $displayWidth);
        }

        $img->set_attribute('src', self::url($largest));
        $img->set_attribute('srcset', self::srcset($byFormat['jpeg-xt']));
        $img->set_attribute('sizes', $sizes);
        $img->set_attribute('data-hdrjpg', '');
        if (!$width || !$height) {
            $img->set_attribute('width', (string)$largest['width']);
            $img->set_attribute('height', (string)$largest['height']);
        }
        // From HDRJPG's own embed markup: fixes large HDR images disappearing after loading in some browsers
        $style = trim((string)$img->get_attribute('style'));
        $img->set_attribute('style', ($style !== '' ? rtrim($style, ';').'; ' : '').'image-rendering: -webkit-optimize-contrast;');

        $source = '';
        if (!empty($byFormat['avif'])) {
            $source = sprintf(
                '<source type="image/avif" srcset="%s" sizes="%s" media="%s">',
                esc_attr(self::srcset($byFormat['avif'])),
                esc_attr($sizes),
                esc_attr(self::AVIF_MEDIA)
            );
        }

        $video = '';
        if (!empty($byFormat['mp4'])) {
            $video = ' data-mp4-src="'.esc_url(self::url($byFormat['mp4'][0])).'"';
            wp_enqueue_script('hdrjpg');
        }

        // A span, so the markup stays valid where the image sits inside a paragraph
        return '<span class="hdrjpg"'.$video.'><picture>'.$source.$img->get_updated_html().'</picture></span>';
    }

    private static function url(array $file): string
    {
        return trailingslashit(wp_get_upload_dir()['baseurl']).$file['file'];
    }

    private static function srcset(array $files): string
    {
        return implode(', ', array_map(fn ($file) => self::url($file).' '.$file['width'].'w', $files));
    }
}
