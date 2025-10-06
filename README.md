# WP External Featured Image

WP External Featured Image lets editors use remote images as if they were native featured images. Paste a direct `.jpg`/`.png` URL or a Flickr photo page and the plugin renders that image everywhere the theme calls `the_post_thumbnail()`. It also adds Open Graph and Twitter Card tags so social shares pick up the same image.

## Features

- Toggle between native Media Library thumbnails and external URLs per post.
- Automatic Flickr integration (no authentication required) that resolves the best size for social sharing.
- Generates `<meta>` tags for Open Graph and Twitter when an external image is active.
- Works with any theme that relies on `has_post_thumbnail()` / `the_post_thumbnail()` (including Twenty Twenty-Five).
- Graceful error handling with inline editor notices and cached Flickr lookups to avoid repeat API calls.

## Installation

1. Copy the plugin folder into your WordPress `wp-content/plugins/` directory.
2. Activate **WP External Featured Image** from the Plugins screen.
3. Navigate to **Settings → External Featured Image** and enter your Flickr API key (required to resolve Flickr page URLs). Configure the default size preference and cache duration if needed.

## Usage

1. Edit a post and open the **Featured Image Source** panel in the document settings sidebar.
2. Select **External** and paste either:
   - A direct HTTPS image URL that ends in `.jpg`, `.jpeg`, or `.png`, or
   - A Flickr photo page URL (e.g. `https://www.flickr.com/photos/user/1234567890/`).
3. Save or update the post. The plugin resolves Flickr URLs to the best available image size (preferring ≥1200px landscape when possible) and caches the result.
4. On the front end, the external image is output wherever the theme requests the featured image. If you set a native featured image from the Media Library, it automatically overrides the external URL.

The plugin also injects Open Graph and Twitter Card tags for external images so social platforms share the correct thumbnail. SEO plugins can disable this behaviour via the `xefi_og_enabled` filter.

## Filters

- `xefi_should_override_thumbnail( $allow, $post_id )` — Return `false` to prevent external thumbnails for a post.
- `xefi_resolve_flickr_sizes( $url, $sizes, $context )` — Override the selected Flickr size URL.
- `xefi_thumbnail_img_attrs( $attrs, $post_id )` — Modify attributes on the generated `<img>` tag.
- `xefi_og_enabled( $enabled, $post_id )` — Disable Open Graph/Twitter tag output.
- `xefi_cache_ttl( $seconds, $photo_id )` — Adjust Flickr cache duration globally.

## Requirements

- WordPress 6.2 or later.
- PHP 8.0 or later.
- A Flickr API key to resolve Flickr page URLs.

## Development

All plugin source lives in this repository. The editor UI is written in vanilla JavaScript and does not require a build step.
