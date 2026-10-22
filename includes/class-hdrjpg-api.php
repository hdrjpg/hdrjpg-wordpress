<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A client for the HDRJPG API: https://hdrjpg.com/documentation/api
 */
class Hdrjpg_Api
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        // HDRJPG_API_URL lets a test site talk to a stand-in for the API
        $this->baseUrl = rtrim(defined('HDRJPG_API_URL') ? HDRJPG_API_URL : 'https://api.hdrjpg.com', '/');
    }

    /**
     * Submits a source photo. Returns the conversion.
     */
    public function submit(string $filePath, array $variants): array
    {
        $boundary = 'hdrjpg'.wp_generate_password(24, false);
        $body =
            '--'.$boundary."\r\n"
            .'Content-Disposition: form-data; name="variant"'."\r\n\r\n"
            .wp_json_encode($variants)."\r\n"
            .'--'.$boundary."\r\n"
            .'Content-Disposition: form-data; name="sourceImage"; filename="'.str_replace(['"', "\r", "\n"], '', wp_basename($filePath)).'"'."\r\n"
            .'Content-Type: application/octet-stream'."\r\n\r\n"
            .file_get_contents($filePath)."\r\n"
            .'--'.$boundary."--\r\n";

        return $this->conversionFrom($this->request('POST', 'convert', [], $body, 'multipart/form-data; boundary='.$boundary));
    }

    public function status(string $conversionUuid): array
    {
        return $this->conversionFrom($this->request('GET', 'status', ['conversionUuid' => $conversionUuid]));
    }

    /**
     * Downloads the conversion's ZIP into a temporary file and returns its path.
     */
    public function download(string $conversionUuid): string
    {
        $file = wp_tempnam('hdrjpg.zip');
        $this->request('GET', 'download', ['conversionUuid' => $conversionUuid], null, null, $file);
        return $file;
    }

    private function request(string $method, string $endpoint, array $query = [], ?string $body = null, ?string $contentType = null, ?string $saveTo = null): array
    {
        $headers = ['api-key' => $this->apiKey];
        if ($contentType) {
            $headers['Content-Type'] = $contentType;
        }
        $arguments = [
            'method' => $method,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $endpoint === 'status' ? 20 : 120,
            // As in the official SDKs: never follow a redirect with the key
            'redirection' => 0,
            'user-agent' => 'hdrjpg-wordpress/'.HDRJPG_VERSION.' (https://hdrjpg.com) WordPress/'.get_bloginfo('version'),
        ];
        if ($saveTo) {
            $arguments['stream'] = true;
            $arguments['filename'] = $saveTo;
        }

        $response = wp_remote_request(add_query_arg(array_map('rawurlencode', $query), $this->baseUrl.'/'.$endpoint), $arguments);
        if (is_wp_error($response)) {
            throw new Hdrjpg_Api_Exception(sprintf(__('Could not reach HDRJPG: %s', 'hdrjpg'), $response->get_error_message()));
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        $responseBody = $saveTo ? ($code === 200 ? '' : (string)file_get_contents($saveTo)) : wp_remote_retrieve_body($response);
        if ($code !== 200) {
            if ($saveTo) {
                wp_delete_file($saveTo);
            }
            $data = json_decode($responseBody, true);
            throw new Hdrjpg_Api_Exception(
                sprintf(__('HDRJPG answered %1$d: %2$s', 'hdrjpg'), $code, is_array($data) && !empty($data['errorDescription']) ? $data['errorDescription'] : wp_remote_retrieve_response_message($response)),
                $code
            );
        }
        return $saveTo ? [] : (array)json_decode($responseBody, true);
    }

    private function conversionFrom(array $data): array
    {
        $conversion = isset($data['conversions']) && is_array($data['conversions']) ? reset($data['conversions']) : null;
        if (!is_array($conversion) || empty($conversion['uuid'])) {
            throw new Hdrjpg_Api_Exception(__('HDRJPG sent an answer the plugin does not understand.', 'hdrjpg'));
        }
        return $conversion;
    }
}

class Hdrjpg_Api_Exception extends Exception
{
}
