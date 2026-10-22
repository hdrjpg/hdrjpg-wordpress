# HDRJPG for WordPress

Converts the HDR photos in a WordPress Media Library (iPhone HEIC, Android Ultra HDR JPG, EXR) into web-ready HDR
files with the [HDRJPG](https://hdrjpg.com) API, and serves them with the HDRJPG markup so they show in HDR in almost
every browser. See [readme.txt](readme.txt) for the user-facing description.

## Layout

| File | What it does |
|---|---|
| `includes/class-hdrjpg-api.php` | The API client, on the WordPress HTTP API |
| `includes/class-hdrjpg-converter.php` | Starts conversions, follows them with WP-Cron, stores the files in `uploads/hdrjpg/<id>/` |
| `includes/class-hdrjpg-media.php` | The HDR field in the Media Library, its AJAX calls and the bulk action |
| `includes/class-hdrjpg-frontend.php` | Turns `<img>` tags of converted photos into HDRJPG embeds |
| `includes/class-hdrjpg-settings.php` | Settings > HDRJPG |
| `assets/hdrjpg.js` | A build of [hdrjpg-js](https://github.com/hdrjpg/hdrjpg-js) |

## Testing

`tests/mock-api.mjs` stands in for the HDRJPG API. Point a test site at it with
`define('HDRJPG_API_URL', 'http://<mock host>:8080');` in `wp-config.php` and run `wp eval-file tests/e2e.php`.
