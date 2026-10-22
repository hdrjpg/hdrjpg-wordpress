<?php
// Run with: wp eval-file tests/e2e.php (inside a test WordPress with HDRJPG_API_URL pointing at tests/mock-api.mjs)

function check($label, $ok) { echo ($ok ? 'PASS ' : 'FAIL ').$label."\n"; if (!$ok) { $GLOBALS['failures'] = ($GLOBALS['failures'] ?? 0) + 1; } }

function make_jpeg(string $path, bool $gainMap): void {
    $image = imagecreatetruecolor(3000, 2000);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 40));
    ob_start(); imagejpeg($image, null, 80); $jpeg = ob_get_clean();
    if ($gainMap) {
        $xmp = "http://ns.adobe.com/xap/1.0/\0".'<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description xmlns:hdrgm="http://ns.adobe.com/hdr-gain-map/1.0/" hdrgm:Version="1.0"/></rdf:RDF></x:xmpmeta>';
        $jpeg = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($xmp) + 2).$xmp.substr($jpeg, 2);
    }
    file_put_contents($path, $jpeg);
}

require_once ABSPATH.'wp-admin/includes/image.php';
require_once ABSPATH.'wp-admin/includes/file.php';
require_once ABSPATH.'wp-admin/includes/media.php';
wp_set_current_user(1);

// 1. Settings: no key yet
update_option('hdrjpg_settings', ['api_key' => '', 'widths' => '2400, 1200', 'avif' => 1, 'mp4' => 1, 'auto_convert' => 1]);
make_jpeg('/tmp/sunset.jpg', true);
$id = media_handle_sideload(['name' => 'sunset.jpg', 'tmp_name' => '/tmp/sunset.jpg'], 0);
check('upload without a key records why it did not convert', (Hdrjpg_Converter::state($id)['status'] ?? '') === 'failed' && strpos(Hdrjpg_Converter::state($id)['error'], 'API key') !== false);

// 2. With a key, a gain map upload converts automatically from the original, not the scaled copy
update_option('hdrjpg_settings', ['api_key' => 'test-key', 'widths' => '2400, 1200', 'avif' => 1, 'mp4' => 1, 'auto_convert' => 1]);
make_jpeg('/tmp/sunset2.jpg', true);
$id = media_handle_sideload(['name' => 'sunset2.jpg', 'tmp_name' => '/tmp/sunset2.jpg'], 0);
check('big upload was scaled by WordPress', strpos(get_attached_file($id), '-scaled') !== false);
check('source is the original upload', strpos(Hdrjpg_Converter::sourcePath($id), '-scaled') === false);
$state = Hdrjpg_Converter::state($id);
check('auto conversion started', ($state['status'] ?? '') === 'running' && !empty($state['uuid']));
check('a check is scheduled', (bool)wp_next_scheduled(Hdrjpg_Converter::CRON_HOOK, [$id]));
check('variants', Hdrjpg_Converter::variants('/x/Sunset 2.jpg') === [
    ['width' => 2400, 'format' => 'jpeg-xt', 'fileName' => 'sunset-2-2400'], ['width' => 2400, 'format' => 'avif', 'fileName' => 'sunset-2-2400'],
    ['width' => 1200, 'format' => 'jpeg-xt', 'fileName' => 'sunset-2-1200'], ['width' => 1200, 'format' => 'avif', 'fileName' => 'sunset-2-1200'],
    ['width' => 2400, 'format' => 'mp4', 'fileName' => 'sunset-2-2400']]);

Hdrjpg_Converter::checkFromCron($id);
$state = Hdrjpg_Converter::state($id);
check('ready after the check', ($state['status'] ?? '') === 'ready');
check('5 files stored, largest first', count($state['files']) === 5 && $state['files'][0]['width'] === 2400);
$dir = wp_get_upload_dir()['basedir'].'/hdrjpg/'.$id;
check('files on disk', file_exists($dir.'/sunset2-2400.jpg') && file_exists($dir.'/sunset2-1200.avif') && file_exists($dir.'/sunset2-2400.mp4'));
check('no further check scheduled', !wp_next_scheduled(Hdrjpg_Converter::CRON_HOOK, [$id]));

// 3. A plain JPEG upload is not converted automatically
make_jpeg('/tmp/plain.jpg', false);
$plain = media_handle_sideload(['name' => 'plain.jpg', 'tmp_name' => '/tmp/plain.jpg'], 0);
check('plain JPEG left alone on upload', Hdrjpg_Converter::state($plain) === []);

// 4. A failing conversion keeps the reason
make_jpeg('/tmp/flat.jpg', false);
$flat = media_handle_sideload(['name' => 'flat.jpg', 'tmp_name' => '/tmp/flat.jpg'], 0);
Hdrjpg_Converter::start($flat);
Hdrjpg_Converter::check($flat);
$state = Hdrjpg_Converter::state($flat);
check('failed with the API reason', ($state['status'] ?? '') === 'failed' && $state['error'] === 'The source image is not an HDR image');

// 5. A wrong key fails at once with the API's answer
update_option('hdrjpg_settings', ['api_key' => 'wrong', 'widths' => '2400, 1200', 'avif' => 1, 'mp4' => 1, 'auto_convert' => 0]);
try { Hdrjpg_Converter::start($plain); check('wrong key throws', false); } catch (Hdrjpg_Api_Exception $e) { check('wrong key throws with the reason', strpos($e->getMessage(), 'Invalid API key') !== false && $e->getCode() === 401); }
update_option('hdrjpg_settings', ['api_key' => 'test-key', 'widths' => '2400, 1200', 'avif' => 1, 'mp4' => 1, 'auto_convert' => 0]);

// 6. Front end: an image block of the converted photo becomes an HDRJPG embed
$content = '<!-- wp:image {"id":'.$id.',"sizeSlug":"large"} --><figure class="wp-block-image size-large">'.wp_get_attachment_image($id, 'large', false, ['class' => 'wp-image-'.$id]).'</figure><!-- /wp:image -->';
$post = wp_insert_post(['post_title' => 'HDR', 'post_content' => $content, 'post_status' => 'publish']);
$GLOBALS['post'] = get_post($post); setup_postdata($GLOBALS['post']);
wp_enqueue_scripts();
$html = apply_filters('the_content', $content);
echo "--- rendered:\n".$html."\n---\n";
check('wrapped in the embed', strpos($html, '<span class="hdrjpg" data-mp4-src="') !== false && strpos($html, 'sunset2-2400.mp4') !== false);
check('img uses the gain map JPEGs', preg_match('/<img[^>]+src="[^"]+sunset2-2400\.jpg"/', $html) && strpos($html, 'sunset2-1200.jpg 1200w') !== false);
check('no SDR srcset left', strpos($html, '-1024x683') === false);
check('AVIF source', strpos($html, '<source type="image/avif" srcset="') !== false && strpos($html, 'sunset2-1200.avif 1200w') !== false);
check('only once', substr_count($html, 'class="hdrjpg"') === 1);
check('script enqueued', wp_script_is('hdrjpg', 'enqueued'));

// 7. A cropped thumbnail is left alone
$thumb = wp_get_attachment_image($id, 'thumbnail');
check('square thumbnail untouched', strpos(Hdrjpg_Frontend::embed($thumb, $id), 'hdrjpg') === false);

// 8. Deleting the attachment deletes its files
wp_delete_attachment($id, true);
check('files removed with the attachment', !is_dir($dir));

echo empty($GLOBALS['failures']) ? "ALL PASSED\n" : $GLOBALS['failures']." FAILED\n";
