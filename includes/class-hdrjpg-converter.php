<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Converts an attachment's original photo with the HDRJPG API and keeps the results next to it.
 *
 * State is kept in the attachment's _hdrjpg meta:
 * status (running, ready or failed), uuid, error, started, files (each with file, format, width and height).
 * A running conversion is followed by a WP-Cron event, and by the Media Library's own polling when someone is watching.
 */
class Hdrjpg_Converter
{
    const META = '_hdrjpg';
    const CRON_HOOK = 'hdrjpg_check_conversion';
    const CHECK_DELAY = 15;
    const GIVE_UP_AFTER = 1800;

    const SOURCE_MIME_TYPES = ['image/heic', 'image/heif', 'image/jpeg', 'image/x-exr'];

    public static function register(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'checkFromCron']);
        add_action('add_attachment', [self::class, 'onUpload']);
        add_action('delete_attachment', [self::class, 'deleteFiles']);
        add_filter('upload_mimes', [self::class, 'allowExr']);
    }

    public static function allowExr(array $mimes): array
    {
        if (current_user_can('upload_files')) {
            $mimes['exr'] = 'image/x-exr';
        }
        return $mimes;
    }

    public static function state(int $attachmentId): array
    {
        $state = get_post_meta($attachmentId, self::META, true);
        return is_array($state) ? $state : [];
    }

    public static function isSource(int $attachmentId): bool
    {
        return in_array(get_post_mime_type($attachmentId), self::SOURCE_MIME_TYPES, true);
    }

    /**
     * The file as uploaded. WordPress replaces large uploads, and HEIC uploads, with a scaled JPEG made on the server,
     * which has lost the HDR; the upload itself is kept as the "original image".
     */
    public static function sourcePath(int $attachmentId): string
    {
        $original = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($attachmentId) : '';
        return $original ?: (string)get_attached_file($attachmentId);
    }

    public static function onUpload(int $attachmentId): void
    {
        if (!Hdrjpg_Settings::get('auto_convert') || !self::isSource($attachmentId)) {
            return;
        }
        // Only photos that carry HDR: every JPEG upload would otherwise spend a conversion and fail on the flat ones
        $path = self::sourcePath($attachmentId);
        if (get_post_mime_type($attachmentId) === 'image/jpeg' && !self::hasGainMap($path)) {
            return;
        }
        try {
            self::start($attachmentId);
        } catch (Exception $e) {
            self::fail($attachmentId, $e->getMessage());
        }
    }

    /**
     * Whether a JPEG carries a gain map, by the metadata every gain map dialect writes in its first segments:
     * the Adobe/Google XMP namespace (hdrgm) or the ISO 21496-1 URN.
     */
    public static function hasGainMap(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return false;
        }
        $head = (string)fread($handle, 262144);
        fclose($handle);
        return strpos($head, 'http://ns.adobe.com/hdr-gain-map/1.0/') !== false || strpos($head, 'urn:iso:std:iso:ts:21496:-1') !== false;
    }

    /**
     * @throws Exception
     */
    public static function start(int $attachmentId): array
    {
        $apiKey = (string)Hdrjpg_Settings::get('api_key');
        if ($apiKey === '') {
            throw new Exception(__('Add your HDRJPG API key in Settings > HDRJPG first.', 'hdrjpg'));
        }
        if (!self::isSource($attachmentId)) {
            throw new Exception(__('Only HEIC, JPG and EXR photos can be converted.', 'hdrjpg'));
        }
        $path = self::sourcePath($attachmentId);
        if (!is_readable($path)) {
            throw new Exception(__('The uploaded file is missing from the server.', 'hdrjpg'));
        }

        $conversion = (new Hdrjpg_Api($apiKey))->submit($path, self::variants($path));

        $state = [
            'status' => 'running',
            'uuid' => $conversion['uuid'],
            'started' => time(),
            'files' => self::state($attachmentId)['files'] ?? [],
        ];
        update_post_meta($attachmentId, self::META, $state);
        wp_schedule_single_event(time() + self::CHECK_DELAY, self::CRON_HOOK, [$attachmentId]);
        return $state;
    }

    public static function checkFromCron(int $attachmentId): void
    {
        $state = self::check($attachmentId);
        if (($state['status'] ?? '') === 'running') {
            wp_schedule_single_event(time() + self::CHECK_DELAY, self::CRON_HOOK, [$attachmentId]);
        }
    }

    /**
     * Asks HDRJPG how a running conversion is doing, and stores its files once it is done.
     */
    public static function check(int $attachmentId): array
    {
        $state = self::state($attachmentId);
        if (($state['status'] ?? '') !== 'running') {
            return $state;
        }

        // Two checks at once (the cron event and someone watching the Media Library) would download the files twice
        $lock = 'hdrjpg_lock_'.$attachmentId;
        if (get_transient($lock)) {
            return $state;
        }
        set_transient($lock, 1, 120);

        try {
            $api = new Hdrjpg_Api((string)Hdrjpg_Settings::get('api_key'));
            $conversion = $api->status($state['uuid']);

            if (in_array($conversion['status'], ['Ready', 'Delivered'], true)) {
                $state = [
                    'status' => 'ready',
                    'uuid' => $state['uuid'],
                    'completed' => time(),
                    'files' => self::storeFiles($attachmentId, $api->download($state['uuid']), $conversion),
                ];
                update_post_meta($attachmentId, self::META, $state);
            } elseif (in_array($conversion['status'], ['Failed', 'Cancelled', 'Deleted'], true)) {
                $state = self::fail($attachmentId, self::failDescription($conversion));
            } elseif (time() - (int)$state['started'] > self::GIVE_UP_AFTER) {
                $state = self::fail($attachmentId, __('The conversion took too long. Please try again.', 'hdrjpg'));
            }
        } catch (Hdrjpg_Api_Exception $e) {
            // A network hiccup is retried at the next check; an answer from the API about this conversion is final
            if ($e->getCode() >= 400 && $e->getCode() < 500) {
                $state = self::fail($attachmentId, $e->getMessage());
            }
        } catch (Exception $e) {
            $state = self::fail($attachmentId, $e->getMessage());
        } finally {
            delete_transient($lock);
        }

        if ($state['status'] !== 'running') {
            // Finished from the Media Library: the pending cron check has nothing left to do
            wp_clear_scheduled_hook(self::CRON_HOOK, [$attachmentId]);
        }

        return $state;
    }

    private static function fail(int $attachmentId, string $error): array
    {
        $state = self::state($attachmentId);
        $state['status'] = 'failed';
        $state['error'] = $error;
        update_post_meta($attachmentId, self::META, $state);
        return $state;
    }

    private static function failDescription(array $conversion): string
    {
        $descriptions = (array)($conversion['failDescriptions'] ?? []);
        foreach ((array)($conversion['variants'] ?? []) as $variant) {
            foreach ((array)($variant['failDescriptions'] ?? []) as $description) {
                $descriptions[] = $description;
            }
        }
        $descriptions = array_unique(array_filter(array_map('strval', $descriptions)));
        return $descriptions ? implode(' ', $descriptions) : __('The conversion failed.', 'hdrjpg');
    }

    /**
     * The variants the settings ask for: a gain map JPEG and an AVIF at each width, and one MP4 at the largest.
     */
    public static function variants(string $sourcePath): array
    {
        $base = sanitize_title(pathinfo($sourcePath, PATHINFO_FILENAME)) ?: 'photo';
        $base = substr(preg_replace('/[^a-z0-9_-]+/', '-', strtolower($base)), 0, 60);
        $widths = Hdrjpg_Settings::widths();
        $formats = ['jpeg-xt'];
        if (Hdrjpg_Settings::get('avif')) {
            $formats[] = 'avif';
        }

        $variants = [];
        foreach ($widths as $width) {
            foreach ($formats as $format) {
                $variants[] = ['width' => $width, 'format' => $format, 'fileName' => $base.'-'.$width];
            }
        }
        if (Hdrjpg_Settings::get('mp4')) {
            $variants[] = ['width' => $widths[0], 'format' => 'mp4', 'fileName' => $base.'-'.$widths[0]];
        }
        return $variants;
    }

    private static function directory(int $attachmentId): array
    {
        $uploads = wp_get_upload_dir();
        return [
            'path' => trailingslashit($uploads['basedir']).'hdrjpg/'.$attachmentId,
            'relative' => 'hdrjpg/'.$attachmentId,
        ];
    }

    /**
     * @throws Exception
     */
    private static function storeFiles(int $attachmentId, string $zipFile, array $conversion): array
    {
        $directory = self::directory($attachmentId);
        self::deleteFiles($attachmentId);
        if (!wp_mkdir_p($directory['path'])) {
            wp_delete_file($zipFile);
            throw new Exception(__('Could not create the folder for the converted files in wp-content/uploads.', 'hdrjpg'));
        }

        // What the API knows about each output file, by file name
        $outputs = [];
        foreach ((array)($conversion['variants'] ?? []) as $variant) {
            if (!empty($variant['outputImageFileName'])) {
                $outputs[$variant['outputImageFileName']] = $variant;
            }
        }

        if (!class_exists('ZipArchive')) {
            wp_delete_file($zipFile);
            throw new Exception(__('The PHP zip extension is needed to unpack the converted files. Ask your host to enable it.', 'hdrjpg'));
        }
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            wp_delete_file($zipFile);
            throw new Exception(__('The converted files could not be unpacked.', 'hdrjpg'));
        }
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            // Only the file name is used, so an entry can never land outside the folder
            $name = sanitize_file_name(wp_basename((string)$zip->getNameIndex($i)));
            if ($name === '' || !isset($outputs[$name])) {
                continue;
            }
            file_put_contents($directory['path'].'/'.$name, $zip->getFromIndex($i));
            $files[] = [
                'file' => $directory['relative'].'/'.$name,
                'format' => (string)$outputs[$name]['format'],
                'width' => (int)$outputs[$name]['outputImageWidth'],
                'height' => (int)$outputs[$name]['outputImageHeight'],
            ];
        }
        $zip->close();
        wp_delete_file($zipFile);

        if (!$files) {
            throw new Exception(__('The conversion finished but returned no files.', 'hdrjpg'));
        }
        usort($files, fn ($a, $b) => $b['width'] <=> $a['width']);
        return $files;
    }

    public static function deleteFiles(int $attachmentId): void
    {
        $path = self::directory($attachmentId)['path'];
        if (is_dir($path)) {
            foreach ((array)glob($path.'/*') as $file) {
                wp_delete_file($file);
            }
            @rmdir($path);
        }
    }
}
