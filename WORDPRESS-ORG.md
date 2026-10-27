# WordPress.org plugin directory

Everything we need to release and maintain the plugin on WordPress.org, taken from the approval
emails of 2026-10-27. No need to dig the emails up again.

## Status

- **Approved 2026-10-27.** Review ID `APPROVED hdrjpg/tincat/26Oct26/T3 27Oct26/4.3 (P0TDX392387HGN)`.
- **1.0.0 released 2026-10-27** (SVN r3768237: trunk, tags/1.0.0, assets). `svn` is installed on
  this machine; the SVN password is in `~/.claude/secrets/wordpress-svn.env` (`WP_SVN_PASSWORD`).
  Plugin files come from the Git repo; `.wordpress-org/banner.svg` and `neon-source.jpg` are source
  files and are not uploaded.
- Display name: **HDRJPG – HDR photos**. Slug: **`hdrjpg`** (permanent, cannot be changed after approval).
- Review history: submitted ~2026-10-22. The automated pre-review (2026-10-26) flagged no code
  issues, only asked how the plugin stands out from the ~300 "image optimization" plugins. We
  answered that it is not an optimizer: it keeps HDR photos in HDR (gain map JPEG, HDR AVIF, HDR MP4,
  served per browser) where WordPress and optimizers/CDNs strip the gain map (cf. Trac #58288).
  That was accepted.

## Repository and accounts

| | |
|---|---|
| SVN URL | https://plugins.svn.wordpress.org/hdrjpg |
| Public page | https://wordpress.org/plugins/hdrjpg (only visible once files are committed to SVN) |
| SVN username | `tincat` (case sensitive; the email address is NOT accepted) |
| SVN password | Separate from the WordPress.org login password. Set/get it in Account & Security: https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password. One password for all of the account's repositories. |
| Contact address | plugins@wordpress.org — must be whitelisted in the owner's mail (loren@tin.cat). If they cannot reach us, the plugin may be closed. |

Commit access is granted within one hour of approval. Search results and the profile page can take
up to 72 hours to update.

## How SVN works here

- SVN is a **release system, not a dev repo**. Only commit ready-to-use versions. Development stays
  in this Git repo (github.com/hdrjpg/hdrjpg-wordpress); SVN gets releases only.
- Nobody at WordPress.org uploads code for us; we use our own SVN client.
- Standard layout:
  - `trunk/` — current plugin code.
  - `tags/<version>/` — one copy per release (e.g. `tags/1.0.0`).
  - `assets/` — directory-page artwork (banners, icons, screenshots). This is our `.wordpress-org/`
    folder; it is NOT part of the plugin zip.
- The `Stable tag:` in `trunk/readme.txt` decides which tag users get. Keep it equal to the tag
  being released, and keep `Version:` in `hdrjpg.php` in sync.
- `readme.txt` controls the public page (description, FAQ, changelog, screenshots captions).
  Validate it before committing: https://wordpress.org/plugins/developers/readme-validator/

Typical release (to adapt once we have done the first one):

```bash
svn co https://plugins.svn.wordpress.org/hdrjpg hdrjpg-svn
# copy plugin files (no .git, tests, .wordpress-org, README.md) into hdrjpg-svn/trunk/
# copy .wordpress-org/* into hdrjpg-svn/assets/
svn add --force hdrjpg-svn/trunk hdrjpg-svn/assets
svn cp hdrjpg-svn/trunk hdrjpg-svn/tags/1.0.0
svn ci hdrjpg-svn -m "Release 1.0.0" --username tincat
```

Committing to SVN publishes to every WordPress site: it needs the owner's go-ahead each time.

## Compliance (ongoing; violations can close the plugin)

After approval the plugin can be re-reviewed at any time by the Plugins Team, outside experts, the
community and automated security scanners. Being approved does not mean it is free of issues.
Things that get plugins removed:

- non-GPL-compatible code;
- not maintaining the source code;
- tracking users without consent;
- intentionally restricting included functionality (trialware);
- manipulating reviews.

Before each release, run **Plugin Check** (https://wordpress.org/plugins/plugin-check/) and
PHPCS with WPCS.

Do not reply to the approval email unless there is a real problem. Announcements:
https://make.wordpress.org/plugins

## Reference links

- Using Subversion: https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/
- SVN password tutorial: https://make.wordpress.org/meta/handbook/tutorials-guides/svn-access/
- Developer FAQ: https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/
- readme.txt standard: https://wordpress.org/plugins/developers/#readme
- readme validator: https://wordpress.org/plugins/developers/readme-validator/
- Plugin assets (banners, icons, screenshots): https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/
- Guidelines: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- Block plugin guidelines: https://developer.wordpress.org/plugins/wordpress-org/block-specific-plugin-guidelines/
