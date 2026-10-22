<?php
// wp eval-file tests/make-upload.php: uploads a 3000x2000 JPEG with gain map metadata and prints its attachment id
require_once ABSPATH.'wp-admin/includes/image.php';
require_once ABSPATH.'wp-admin/includes/file.php';
require_once ABSPATH.'wp-admin/includes/media.php';
$image = imagecreatetruecolor(3000, 2000);
ob_start(); imagejpeg($image, null, 80); $jpeg = ob_get_clean();
$xmp = "http://ns.adobe.com/xap/1.0/\0".'<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:Description xmlns:hdrgm="http://ns.adobe.com/hdr-gain-map/1.0/"/></x:xmpmeta>';
file_put_contents('/tmp/upload.jpg', substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($xmp) + 2).$xmp.substr($jpeg, 2));
echo media_handle_sideload(['name' => 'manual.jpg', 'tmp_name' => '/tmp/upload.jpg'], 0);
