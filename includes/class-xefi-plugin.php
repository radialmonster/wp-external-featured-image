<?php
/**
 * Main plugin bootstrap for WP External Featured Image.
 *
 * @package WP_External_Featured_Image
 */

namespace XEFI;

use WP_Error;
use WP_Post;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Primary plugin controller.
 */
class Plugin {
    /**
     * Singleton instance.
     *
     * @var Plugin|null
     */
    protected static $instance = null;

    /**
     * Plugin option name.
     */
    public const OPTION_NAME = 'xefi_settings';

    /**
     * Meta keys used by the plugin.
     */
    public const META_SOURCE        = '_xefi_source';
    public const META_URL           = '_xefi_url';
    public const META_RESOLVED      = '_xefi_resolved';
    public const META_RESOLVED_AT   = '_xefi_resolved_at';
    public const META_PHOTO_ID      = '_xefi_photo_id';
    public const META_ERROR         = '_xefi_error';
    public const META_CACHED_INPUT  = '_xefi_cached_input';

    /**
     * Default settings.
     */
    protected $default_settings = [
        'flickr_api_key'    => '',
        'size_preference'   => 'optimize_social',
        'cache_ttl_value'   => 24,
        'cache_ttl_unit'    => 'hours',
        'cache_ttl'         => DAY_IN_SECONDS,
        'facebook_app_id'   => '',
        'open_graph_enabled' => false,
    ];

    /**
     * Get singleton instance.
     */
    public static function instance(): Plugin {
        if ( null === static::$instance ) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    /**
     * Bootstraps hooks.
     */
    public function init(): void {
        add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );
        add_action( 'init', [ $this, 'register_meta' ] );
        add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
        add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );
        add_action( 'save_post', [ $this, 'handle_save_post' ], 20, 3 );
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
        add_filter( 'has_post_thumbnail', [ $this, 'filter_has_post_thumbnail' ], 10, 3 );
        add_filter( 'post_thumbnail_html', [ $this, 'filter_post_thumbnail_html' ], 10, 5 );
        add_filter( 'get_the_post_thumbnail_url', [ $this, 'filter_post_thumbnail_url' ], 10, 3 );
        add_filter( 'post_thumbnail_url', [ $this, 'filter_post_thumbnail_url' ], 10, 3 );
        add_action( 'wp_head', [ $this, 'output_social_meta' ], 5 );
        add_action( 'admin_menu', [ $this, 'register_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_filter(
            'plugin_action_links_' . plugin_basename( XEFI_PLUGIN_FILE ),
            [ $this, 'add_settings_link' ]
        );
    }

    /**
     * Registers the post meta fields used by the plugin.
     */
    public function register_meta(): void {
        $auth_callback = static function ( $allowed, $meta_key, $post_id ) {
            return current_user_can( 'edit_post', $post_id );
        };

        register_post_meta(
            '',
            self::META_SOURCE,
            [
                'type'              => 'string',
                'single'            => true,
                'default'           => 'media',
                'show_in_rest'      => true,
                'auth_callback'     => $auth_callback,
                'sanitize_callback' => [ $this, 'sanitize_meta_source' ],
            ]
        );

        register_post_meta(
            '',
            self::META_URL,
            [
                'type'              => 'string',
                'single'            => true,
                'default'           => '',
                'show_in_rest'      => true,
                'auth_callback'     => $auth_callback,
                'sanitize_callback' => [ $this, 'sanitize_meta_url' ],
            ]
        );

        register_post_meta(
            '',
            self::META_RESOLVED,
            [
                'type'              => 'string',
                'single'            => true,
                'default'           => '',
                'show_in_rest'      => true,
                'auth_callback'     => $auth_callback,
                'sanitize_callback' => 'esc_url_raw',
            ]
        );

        register_post_meta(
            '',
            self::META_RESOLVED_AT,
            [
                'type'              => 'integer',
                'single'            => true,
                'default'           => 0,
                'show_in_rest'      => true,
                'auth_callback'     => $auth_callback,
                'sanitize_callback' => 'absint',
            ]
        );

        register_post_meta(
            '',
            self::META_PHOTO_ID,
            [
                'type'              => 'string',
                'single'            => true,
                'default'           => '',
                'show_in_rest'      => true,
                'auth_callback'     => $auth_callback,
                'sanitize_callback' => 'sanitize_text_field',
            ]
        );

        register_post_meta(
            '',
            self::META_ERROR,
            [
                'type'              => 'string',
                'single'            => true,
                'default'           => '',
                'show_in_rest'      => true,
                'auth_callback'     => $auth_callback,
                'sanitize_callback' => 'sanitize_text_field',
            ]
        );

        register_post_meta(
            '',
            self::META_CACHED_INPUT,
            [
                'type'              => 'string',
                'single'            => true,
                'default'           => '',
                'show_in_rest'      => false,
                'auth_callback'     => $auth_callback,
                'sanitize_callback' => 'esc_url_raw',
            ]
        );
    }

    /**
     * Enqueues the block editor UI script.
     */
    public function enqueue_editor_assets(): void {
        $asset_path = XEFI_PLUGIN_DIR . 'assets/js/editor.js';
        if ( ! file_exists( $asset_path ) ) {
            return;
        }

        $handle = 'xefi-editor-panel';
        wp_register_script(
            $handle,
            XEFI_PLUGIN_URL . 'assets/js/editor.js',
            [ 'wp-data', 'wp-edit-post', 'wp-components', 'wp-element', 'wp-i18n', 'wp-plugins', 'wp-compose', 'wp-api-fetch', 'wp-blocks' ],
            XEFI_PLUGIN_VERSION,
            true
        );

        $settings = $this->with_decrypted_flickr_api_key();

        wp_localize_script(
            $handle,
            'XEFIEditorData',
            [
                'strings' => [
                    'panelTitle'     => __( 'WP External Featured Image (Block)', 'wp-external-featured-image' ),
                    'fieldLabel'     => __( 'External image or Flickr page URL', 'wp-external-featured-image' ),
                    'helperText'     => __( 'Paste a direct image URL (.jpg/.png) or a Flickr photo URL.', 'wp-external-featured-image' ),
                    'mediaLibrary'   => __( 'Media Library', 'wp-external-featured-image' ),
                    'externalSource' => __( 'External', 'wp-external-featured-image' ),
                    'invalidUrl'     => __( 'Enter a valid HTTPS image URL or Flickr page URL.', 'wp-external-featured-image' ),
                    'nativeOverride' => __( 'A native featured image is set. It will override the external image.', 'wp-external-featured-image' ),
                    'flickrApiKeyRequired' => __( 'Add a Flickr API key to resolve Flickr URLs.', 'wp-external-featured-image' ),
                    'resolvingPreview'     => __( 'Resolving preview…', 'wp-external-featured-image' ),
                ],
                'validation' => [
                    'imageExtensions' => [ 'jpg', 'jpeg', 'png' ],
                ],
                'settings' => [
                    'supportsFlickr' => '' !== $settings['flickr_api_key'],
                ],
            ]
        );

        wp_enqueue_script( $handle );
    }

    /**
     * Registers REST API routes used by the block editor integration.
     */
    public function register_rest_routes(): void {
        register_rest_route(
            'xefi/v1',
            '/resolve',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'handle_rest_resolve' ],
                'permission_callback' => [ $this, 'can_resolve_request' ],
                'args'                => [
                    'postId' => [
                        'type'     => 'integer',
                        'required' => true,
                    ],
                    'url'    => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                ],
            ]
        );
    }

    /**
     * Permission callback for the resolve REST endpoint.
     */
    public function can_resolve_request( WP_REST_Request $request ): bool {
        $post_id = (int) $request->get_param( 'postId' );
        if ( $post_id <= 0 ) {
            return current_user_can( 'edit_posts' );
        }

        return current_user_can( 'edit_post', $post_id );
    }

    /**
     * Resolve a URL for previewing in the editor.
     */
    public function handle_rest_resolve( WP_REST_Request $request ) {
        $raw_url = (string) $request->get_param( 'url' );
        $url     = $this->sanitize_meta_url( $raw_url );

        if ( '' === $url ) {
            return new WP_Error( 'xefi_invalid_url', __( 'Enter a direct .jpg/.png image URL or a Flickr photo URL.', 'wp-external-featured-image' ), [ 'status' => 400 ] );
        }

        if ( $this->is_direct_image_url( $url ) ) {
            return [
                'url'          => $url,
                'original_url' => $url,
                'type'         => 'direct',
            ];
        }

        if ( ! $this->is_flickr_url( $url ) ) {
            return new WP_Error( 'xefi_invalid_url', __( 'Enter a direct .jpg/.png image URL or a Flickr photo URL.', 'wp-external-featured-image' ), [ 'status' => 400 ] );
        }

        $settings = $this->with_decrypted_flickr_api_key();

        $resolver = Flickr_Resolver::instance();
        $result   = $resolver->resolve( $url, $settings );

        if ( is_wp_error( $result ) ) {
            $result->add_data( [ 'status' => 400 ] );
            return $result;
        }

        return [
            'url'          => $result['url'],
            'original_url' => $url,
            'photo_id'     => $result['photo_id'],
            'type'         => 'flickr',
        ];
    }

    /**
     * Registers the classic editor meta box.
     */
    public function register_meta_box(): void {
        // Don't show classic meta box in block editor since we have the panel.
        $screen = get_current_screen();
        if ( $screen && $screen->is_block_editor() ) {
            return;
        }

        $post_types = get_post_types_by_support( 'thumbnail' );
        foreach ( $post_types as $post_type ) {
            add_meta_box(
                'xefi-external-featured-image',
                __( 'WP External Featured Image (Classic)', 'wp-external-featured-image' ),
                [ $this, 'render_meta_box' ],
                $post_type,
                'side',
                'low'
            );
        }

        // Enqueue classic editor JavaScript.
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_classic_editor_assets' ] );
    }

    /**
     * Enqueue assets for the classic editor.
     */
    public function enqueue_classic_editor_assets( string $hook ): void {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || $screen->is_block_editor() ) {
            return;
        }

        $asset_path = XEFI_PLUGIN_DIR . 'assets/js/classic-editor.js';
        if ( ! file_exists( $asset_path ) ) {
            return;
        }

        $handle = 'xefi-classic-editor';
        wp_enqueue_script(
            $handle,
            XEFI_PLUGIN_URL . 'assets/js/classic-editor.js',
            [ 'jquery', 'wp-api' ],
            XEFI_PLUGIN_VERSION,
            true
        );

        $settings       = $this->with_decrypted_flickr_api_key();
        $flickr_api_key = $settings['flickr_api_key'];

        wp_localize_script(
            $handle,
            'XEFIEditorData',
            [
                'validation' => [
                    'imageExtensions' => [ 'jpg', 'jpeg', 'png' ],
                ],
                'settings' => [
                    'supportsFlickr' => '' !== $flickr_api_key,
                ],
            ]
        );
    }

    /**
     * Outputs the classic editor meta box markup.
     */
    public function render_meta_box( WP_Post $post ): void {
        wp_nonce_field( 'xefi_save_meta', 'xefi_meta_nonce' );

        $source   = get_post_meta( $post->ID, self::META_SOURCE, true ) ?: 'media';
        $url      = get_post_meta( $post->ID, self::META_URL, true );
        $error    = get_post_meta( $post->ID, self::META_ERROR, true );
        $has_native = (bool) get_post_meta( $post->ID, '_thumbnail_id', true );
        ?>
        <p>
            <label>
                <input type="radio" name="<?php echo esc_attr( self::META_SOURCE ); ?>" value="media" <?php checked( 'media', $source ); ?> />
                <?php esc_html_e( 'Media Library (default)', 'wp-external-featured-image' ); ?>
            </label><br />
            <label>
                <input type="radio" name="<?php echo esc_attr( self::META_SOURCE ); ?>" value="external" <?php checked( 'external', $source ); ?> />
                <?php esc_html_e( 'External', 'wp-external-featured-image' ); ?>
            </label>
        </p>
        <p>
            <label for="xefi-external-url">
                <?php esc_html_e( 'External image or Flickr page URL', 'wp-external-featured-image' ); ?>
            </label>
            <input type="url" id="xefi-external-url" name="<?php echo esc_attr( self::META_URL ); ?>" value="<?php echo esc_attr( $url ); ?>" class="widefat" placeholder="https://" />
            <span class="description"><?php esc_html_e( 'Paste a direct image URL (.jpg/.png) or a Flickr photo URL.', 'wp-external-featured-image' ); ?></span>
        </p>
        <?php if ( $has_native ) : ?>
            <p><em><?php esc_html_e( 'A native featured image is set and will be used instead of the external URL.', 'wp-external-featured-image' ); ?></em></p>
        <?php endif; ?>
        <?php if ( $error ) : ?>
            <p><strong><?php esc_html_e( 'Error:', 'wp-external-featured-image' ); ?></strong> <?php echo esc_html( $error ); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Handles saving post meta from both the block and classic editors.
     */
    public function handle_save_post( int $post_id, WP_Post $post, bool $update ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( isset( $_POST['xefi_meta_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['xefi_meta_nonce'] ) ), 'xefi_save_meta' ) ) {
            $source = isset( $_POST[ self::META_SOURCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_SOURCE ] ) ) : 'media';
            if ( 'external' !== $source ) {
                $source = 'media';
            }

            $url = '';
            if ( isset( $_POST[ self::META_URL ] ) ) {
                $url = esc_url_raw( wp_unslash( $_POST[ self::META_URL ] ) );
            }

            update_post_meta( $post_id, self::META_SOURCE, $source );
            update_post_meta( $post_id, self::META_URL, $url );
        }

        $this->process_post_image( $post_id );
    }

    /**
     * Ensures the stored metadata reflects the chosen external image.
     */
    protected function process_post_image( int $post_id ): void {
        $source = get_post_meta( $post_id, self::META_SOURCE, true ) ?: 'media';
        $url    = trim( (string) get_post_meta( $post_id, self::META_URL, true ) );

        if ( 'external' !== $source || '' === $url ) {
            $this->clear_external_state( $post_id, '' !== $url );
            $this->clear_error( $post_id );
            return;
        }

        $cached_input = get_post_meta( $post_id, self::META_CACHED_INPUT, true );
        $resolved     = get_post_meta( $post_id, self::META_RESOLVED, true );
        $error        = get_post_meta( $post_id, self::META_ERROR, true );

        if ( $resolved && $cached_input === $url && ! $error ) {
            // Already resolved with the same input.
            return;
        }

        $result = $this->maybe_resolve_post_image( $post_id, true );
        if ( is_wp_error( $result ) ) {
            $this->set_error( $post_id, $result->get_error_message() );
        } else {
            $this->clear_error( $post_id );
        }
    }

    /**
     * Attempt to resolve the external image for a post.
     *
     * @param int  $post_id Post ID.
     * @param bool $force   Whether to force re-resolution.
     * @return array|WP_Error|false
     */
    public function maybe_resolve_post_image( int $post_id, bool $force = false ) {
        $source = get_post_meta( $post_id, self::META_SOURCE, true );
        if ( 'external' !== $source ) {
            return false;
        }

        $url = trim( (string) get_post_meta( $post_id, self::META_URL, true ) );
        if ( '' === $url ) {
            $this->clear_external_state( $post_id, true );
            return new WP_Error( 'xefi_empty_url', __( 'No external URL provided.', 'wp-external-featured-image' ) );
        }

        if ( ! $this->is_https_url( $url ) ) {
            $this->clear_external_state( $post_id, true );
            return new WP_Error( 'xefi_invalid_scheme', __( 'Only HTTPS image URLs are supported.', 'wp-external-featured-image' ) );
        }

        $is_flickr = $this->is_flickr_url( $url );
        $is_direct = $this->is_direct_image_url( $url );

        if ( ! $is_flickr && ! $is_direct ) {
            $this->clear_external_state( $post_id, true );
            return new WP_Error( 'xefi_invalid_url', __( 'Enter a direct .jpg/.png image URL or a Flickr photo URL.', 'wp-external-featured-image' ) );
        }

        $cached_input = get_post_meta( $post_id, self::META_CACHED_INPUT, true );
        $resolved     = get_post_meta( $post_id, self::META_RESOLVED, true );
        $error        = get_post_meta( $post_id, self::META_ERROR, true );

        if ( ! $force && $resolved && $cached_input === $url && ! $error ) {
            return [
                'url'          => $resolved,
                'original_url' => $url,
                'type'         => $is_flickr ? 'flickr' : 'direct',
            ];
        }

        if ( $is_direct ) {
            update_post_meta( $post_id, self::META_RESOLVED, $url );
            update_post_meta( $post_id, self::META_RESOLVED_AT, time() );
            update_post_meta( $post_id, self::META_CACHED_INPUT, $url );
            delete_post_meta( $post_id, self::META_PHOTO_ID );

            return [
                'url'          => $url,
                'original_url' => $url,
                'type'         => 'direct',
            ];
        }

        $resolver = Flickr_Resolver::instance();
        $settings = $this->with_decrypted_flickr_api_key();

        $result = $resolver->resolve( $url, $settings );

        if ( is_wp_error( $result ) ) {
            if ( $resolved && ! $force ) {
                // Keep the last resolved URL if available.
                return [
                    'url'          => $resolved,
                    'original_url' => $url,
                    'type'         => 'flickr',
                ];
            }

            return $result;
        }

        update_post_meta( $post_id, self::META_RESOLVED, $result['url'] );
        update_post_meta( $post_id, self::META_RESOLVED_AT, time() );
        update_post_meta( $post_id, self::META_PHOTO_ID, $result['photo_id'] );
        update_post_meta( $post_id, self::META_CACHED_INPUT, $url );

        return [
            'url'          => $result['url'],
            'original_url' => $url,
            'type'         => 'flickr',
        ];
    }

    /**
     * Filter whether a post has a thumbnail.
     */
    public function filter_has_post_thumbnail( bool $has_thumbnail, $post, $thumbnail_id ): bool {
        if ( $has_thumbnail ) {
            return $has_thumbnail;
        }

        $post_id = $post instanceof WP_Post ? $post->ID : (int) $post;

        $override = $this->should_override_thumbnail( $post_id );
        return $override ? true : $has_thumbnail;
    }

    /**
     * Filter the HTML for the post thumbnail.
     */
    public function filter_post_thumbnail_html( string $html, int $post_id, $post_thumbnail_id, $size, $attr ): string {
        if ( '' !== $html ) {
            return $html;
        }

        $data = $this->get_external_image_data( $post_id );
        if ( empty( $data['url'] ) ) {
            return $html;
        }

        $attributes = [
            'src'      => $data['url'],
            'class'    => 'wp-post-image',
            'alt'      => get_the_title( $post_id ),
            'loading'  => 'lazy',
            'decoding' => 'async',
        ];

        $attributes = apply_filters( 'xefi_thumbnail_img_attrs', $attributes, $post_id );

        $attr_pairs = [];
        foreach ( $attributes as $key => $value ) {
            if ( is_array( $value ) ) {
                $value = implode( ' ', array_map( 'sanitize_html_class', $value ) );
            }

            if ( ( null === $value || '' === $value ) && 'alt' !== $key ) {
                continue;
            }
            $formatted = 'src' === $key ? esc_url( $value ) : esc_attr( $value );
            $attr_pairs[] = sprintf( '%s="%s"', esc_attr( $key ), $formatted );
        }

        return '<img ' . implode( ' ', $attr_pairs ) . ' />';
    }

    /**
     * Filter the computed thumbnail URL helper.
     */
    public function filter_post_thumbnail_url( $url, $post_id, $size ) {
        if ( $url ) {
            return $url;
        }

        $data = $this->get_external_image_data( (int) $post_id );
        if ( empty( $data['url'] ) ) {
            return $url;
        }

        return $data['url'];
    }

    /**
     * Output Open Graph and Twitter tags when needed.
     */
    public function output_social_meta(): void {
        $setting_enabled = $this->is_open_graph_setting_enabled();

        if ( ! $setting_enabled ) {
            return;
        }

        if ( ! is_singular() ) {
            return;
        }

        $post_id = get_queried_object_id();
        if ( ! $post_id ) {
            return;
        }

        if ( get_post_meta( $post_id, '_thumbnail_id', true ) ) {
            return;
        }

        $enabled = apply_filters( 'xefi_og_enabled', $setting_enabled, $post_id );
        if ( ! $enabled ) {
            return;
        }

        $data = $this->get_external_image_data( $post_id );
        if ( empty( $data['url'] ) ) {
            return;
        }

        $url = esc_url( $data['url'] );

        $og_tags = [
            'og:type'        => $this->get_og_type( $post_id ),
            'og:description' => $this->get_og_description( $post_id ),
            'og:locale'      => $this->get_og_locale(),
            'og:url'         => $this->get_og_url( $post_id ),
            'og:title'       => $this->get_og_title( $post_id ),
            'og:site_name'   => $this->get_og_site_name(),
            'og:image'       => $url,
            'fb:app_id'      => $this->get_fb_app_id(),
        ];

        $logo = $this->get_og_logo_url( $post_id );
        if ( $logo ) {
            $og_tags['og:logo'] = $logo;
        }

        $og_tags = apply_filters( 'xefi_og_tags', $og_tags, $post_id );

        foreach ( $og_tags as $property => $value ) {
            if ( ! $value ) {
                continue;
            }

            $value = 'og:url' === $property || 'og:image' === $property || 'og:logo' === $property
                ? esc_url( $value )
                : esc_attr( $value );

            printf( "\n<meta property=\"%s\" content=\"%s\" />\n", esc_attr( $property ), $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        echo '<meta name="twitter:card" content="summary_large_image" />' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo "<meta name=\"twitter:image\" content=\"{$url}\" />\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Determine the Open Graph type for the current object.
     */
    private function get_og_type( int $post_id ): string {
        $type = 'article';

        $post_type = get_post_type( $post_id );
        if ( 'page' === $post_type ) {
            $type = 'website';
        }

        $type = $type ?: 'website';

        return apply_filters( 'xefi_og_type', $type, $post_id );
    }

    /**
     * Build an Open Graph description from the post excerpt or content.
     */
    private function get_og_description( int $post_id ): string {
        $description = get_post_field( 'post_excerpt', $post_id );

        if ( ! $description ) {
            $content = get_post_field( 'post_content', $post_id );
            $content = wp_strip_all_tags( (string) $content );
            $description = wp_trim_words( $content, 55, '…' );
        }

        $description = wp_strip_all_tags( (string) $description );

        if ( '' === $description ) {
            $description = get_bloginfo( 'description', 'display' );
        }

        if ( ! is_string( $description ) ) {
            $description = '';
        }

        $description = trim( $description );

        if ( '' === $description ) {
            $description = get_bloginfo( 'name', 'display' );
        }

        $description = $description ?: '';

        return apply_filters( 'xefi_og_description', $description, $post_id );
    }

    /**
     * Get the current site locale formatted for Open Graph.
     */
    private function get_og_locale(): string {
        $locale = get_locale();

        if ( $locale ) {
            $locale = str_replace( '_', '-', $locale );
        }

        return apply_filters( 'xefi_og_locale', $locale );
    }

    /**
     * Retrieve the canonical URL for the current object.
     */
    private function get_og_url( int $post_id ): string {
        $url = get_permalink( $post_id );

        if ( ! $url ) {
            $url = home_url( '/' );
        }

        return apply_filters( 'xefi_og_url', $url, $post_id );
    }

    /**
     * Retrieve a sanitized Open Graph title for the post.
     */
    private function get_og_title( int $post_id ): string {
        $title = get_the_title( $post_id );
        $title = wp_strip_all_tags( (string) $title );

        if ( '' === $title ) {
            $title = get_bloginfo( 'name', 'display' );
        }

        $title = $title ?: '';

        return apply_filters( 'xefi_og_title', $title, $post_id );

    }

    /**
     * Retrieve the Open Graph site name value.
     */
    private function get_og_site_name(): string {
        $site_name = get_bloginfo( 'name', 'display' );
        $site_name = is_string( $site_name ) ? trim( wp_strip_all_tags( $site_name ) ) : '';

        if ( '' === $site_name ) {
            $site_name = wp_parse_url( home_url(), PHP_URL_HOST );
        }

        $site_name = is_string( $site_name ) ? $site_name : '';

        return apply_filters( 'xefi_og_site_name', (string) $site_name );

    }

    /**
     * Retrieve the configured Facebook App ID.
     */
    private function get_fb_app_id(): string {
        $settings = $this->get_settings();
        $app_id   = isset( $settings['facebook_app_id'] ) ? (string) $settings['facebook_app_id'] : '';

        $app_id = trim( $app_id );

        return apply_filters( 'xefi_fb_app_id', $app_id );
    }

    /**
     * Check if Open Graph output is enabled in settings.
     */
    private function is_open_graph_setting_enabled(): bool {
        $settings = $this->get_settings();

        return ! empty( $settings['open_graph_enabled'] );
    }

    /**
     * Attempt to load a site logo URL for Open Graph consumers.
     */
    private function get_og_logo_url( int $post_id ): string {
        $logo_id = get_theme_mod( 'custom_logo' );
        $logo    = '';

        if ( $logo_id ) {
            $logo = wp_get_attachment_image_url( $logo_id, 'full' );
        }

        return apply_filters( 'xefi_og_logo_url', $logo, $post_id );
    }

    /**
     * Adds the plugin settings page.
     */
    public function register_settings_page(): void {
        add_options_page(
            __( 'WP External Featured Image', 'wp-external-featured-image' ),
            __( 'WP External Featured Image', 'wp-external-featured-image' ),
            'manage_options',
            'xefi-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    /**
     * Adds a settings shortcut to the Plugins listing.
     */
    public function add_settings_link( array $links ): array {
        $url            = admin_url( 'options-general.php?page=xefi-settings' );
        $settings_label = __( 'Settings', 'wp-external-featured-image' );
        $settings_link  = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $settings_label ) );

        array_unshift( $links, $settings_link );

        return $links;
    }

    /**
     * Registers plugin settings.
     */
    public function register_settings(): void {
        register_setting( 'xefi_settings', self::OPTION_NAME, [ $this, 'sanitize_settings' ] );

        add_settings_section(
            'xefi_main',
            __( 'External Featured Image Settings', 'wp-external-featured-image' ),
            function () {
                echo '<p>' . esc_html__( 'Configure Flickr integration and caching.', 'wp-external-featured-image' ) . '</p>';
            },
            'xefi-settings'
        );

        add_settings_field(
            'xefi_flickr_api_key',
            __( 'Flickr API key', 'wp-external-featured-image' ),
            [ $this, 'render_setting_api_key' ],
            'xefi-settings',
            'xefi_main'
        );

        add_settings_field(
            'xefi_facebook_app_id',
            __( 'Facebook App ID', 'wp-external-featured-image' ),
            [ $this, 'render_setting_facebook_app_id' ],
            'xefi-settings',
            'xefi_main'
        );

        add_settings_field(
            'xefi_open_graph_enabled',
            __( 'Output Open Graph tags', 'wp-external-featured-image' ),
            [ $this, 'render_setting_open_graph_enabled' ],
            'xefi-settings',
            'xefi_main'
        );

        add_settings_field(
            'xefi_size_preference',
            __( 'Default size preference', 'wp-external-featured-image' ),
            [ $this, 'render_setting_size_preference' ],
            'xefi-settings',
            'xefi_main'
        );

        add_settings_field(
            'xefi_cache_ttl',
            __( 'Cache TTL', 'wp-external-featured-image' ),
            [ $this, 'render_setting_cache_ttl' ],
            'xefi-settings',
            'xefi_main'
        );
    }

    /**
     * Sanitize settings.
     */
    public function sanitize_settings( array $input ): array {
        $settings = $this->get_settings();

        if ( isset( $input['flickr_api_key'] ) ) {
            $api_key = sanitize_text_field( $input['flickr_api_key'] );

            // If the input is obscured (starts with x's), don't update.
            if ( '' !== $api_key && ! preg_match( '/^x+/', $api_key ) ) {
                // Encrypt the API key before storing.
                $settings['flickr_api_key'] = Encryption::encrypt( $api_key );
            }
        }

        if ( isset( $input['size_preference'] ) && in_array( $input['size_preference'], [ 'optimize_social', 'largest_available' ], true ) ) {
            $settings['size_preference'] = $input['size_preference'];
        } else {
            $settings['size_preference'] = 'optimize_social';
        }

        if ( isset( $input['facebook_app_id'] ) ) {
            $app_id = preg_replace( '/[^0-9]/', '', (string) $input['facebook_app_id'] );
            $settings['facebook_app_id'] = $app_id;
        }

        $settings['open_graph_enabled'] = ! empty( $input['open_graph_enabled'] );

        $value = isset( $input['cache_ttl_value'] ) ? absint( $input['cache_ttl_value'] ) : $settings['cache_ttl_value'];
        $unit  = isset( $input['cache_ttl_unit'] ) && in_array( $input['cache_ttl_unit'], [ 'minutes', 'hours', 'days' ], true ) ? $input['cache_ttl_unit'] : $settings['cache_ttl_unit'];

        if ( $value <= 0 ) {
            $value = 24;
            $unit  = 'hours';
        }

        $seconds = $value * MINUTE_IN_SECONDS;
        if ( 'hours' === $unit ) {
            $seconds = $value * HOUR_IN_SECONDS;
        } elseif ( 'days' === $unit ) {
            $seconds = $value * DAY_IN_SECONDS;
        }

        $settings['cache_ttl_value'] = $value;
        $settings['cache_ttl_unit']  = $unit;
        $settings['cache_ttl']       = max( MINUTE_IN_SECONDS, $seconds );

        return $settings;
    }

    /**
     * Render settings page wrapper.
     */
    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WP External Featured Image', 'wp-external-featured-image' ); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'xefi_settings' );
                do_settings_sections( 'xefi-settings' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render Flickr API key field.
     */
    public function render_setting_api_key(): void {
        $settings = $this->with_decrypted_flickr_api_key();
        $api_key  = $settings['flickr_api_key'];

        // Obscure the decrypted key for display.
        $display_value = '' !== $api_key ? Encryption::obscure( $api_key ) : '';
        ?>
        <input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[flickr_api_key]" value="<?php echo esc_attr( $display_value ); ?>" autocomplete="off" placeholder="<?php esc_attr_e( 'Enter your Flickr API key', 'wp-external-featured-image' ); ?>" />
        <p class="description"><?php esc_html_e( 'Required to resolve Flickr photo page URLs.', 'wp-external-featured-image' ); ?></p>
        <?php
    }

    /**
     * Render Facebook App ID field.
     */
    public function render_setting_facebook_app_id(): void {
        $settings = $this->get_settings();
        $app_id   = isset( $settings['facebook_app_id'] ) ? (string) $settings['facebook_app_id'] : '';
        ?>
        <input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[facebook_app_id]" value="<?php echo esc_attr( $app_id ); ?>" placeholder="<?php esc_attr_e( 'Enter your Facebook App ID', 'wp-external-featured-image' ); ?>" />
        <p class="description"><?php esc_html_e( 'Used for Facebook Open Graph validation.', 'wp-external-featured-image' ); ?></p>
        <?php
    }

    /**
     * Render Open Graph toggle field.
     */
    public function render_setting_open_graph_enabled(): void {
        $settings = $this->get_settings();
        $enabled  = ! empty( $settings['open_graph_enabled'] );
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[open_graph_enabled]" value="1" <?php checked( $enabled ); ?> />
            <?php esc_html_e( 'Enable Open Graph tags when no local featured image exists.', 'wp-external-featured-image' ); ?>
        </label>
        <p class="description"><?php esc_html_e( 'Leave unchecked if another SEO or social plugin manages these tags.', 'wp-external-featured-image' ); ?></p>
        <?php
    }

    /**
     * Render size preference field.
     */
    public function render_setting_size_preference(): void {
        $settings = $this->get_settings();
        ?>
        <label>
            <input type="radio" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[size_preference]" value="optimize_social" <?php checked( 'optimize_social', $settings['size_preference'] ); ?> />
            <?php esc_html_e( 'Optimize for Social (≥1200px landscape when available)', 'wp-external-featured-image' ); ?>
        </label><br />
        <label>
            <input type="radio" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[size_preference]" value="largest_available" <?php checked( 'largest_available', $settings['size_preference'] ); ?> />
            <?php esc_html_e( 'Largest available', 'wp-external-featured-image' ); ?>
        </label>
        <?php
    }

    /**
     * Render cache TTL field.
     */
    public function render_setting_cache_ttl(): void {
        $settings = $this->get_settings();
        ?>
        <input type="number" min="1" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[cache_ttl_value]" value="<?php echo esc_attr( $settings['cache_ttl_value'] ); ?>" class="small-text" />
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[cache_ttl_unit]">
            <option value="minutes" <?php selected( 'minutes', $settings['cache_ttl_unit'] ); ?>><?php esc_html_e( 'Minutes', 'wp-external-featured-image' ); ?></option>
            <option value="hours" <?php selected( 'hours', $settings['cache_ttl_unit'] ); ?>><?php esc_html_e( 'Hours', 'wp-external-featured-image' ); ?></option>
            <option value="days" <?php selected( 'days', $settings['cache_ttl_unit'] ); ?>><?php esc_html_e( 'Days', 'wp-external-featured-image' ); ?></option>
        </select>
        <p class="description"><?php esc_html_e( 'How long to cache Flickr size lookups (defaults to 24 hours).', 'wp-external-featured-image' ); ?></p>
        <?php
    }

    /**
     * Get plugin settings merged with defaults.
     */
    public function get_settings(): array {
        $saved = get_option( self::OPTION_NAME, [] );
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }

        return wp_parse_args( $saved, $this->default_settings );
    }

    /**
     * Return settings with a decrypted Flickr API key.
     *
     * @return array
     */
    protected function with_decrypted_flickr_api_key(): array {
        $settings = $this->get_settings();

        $api_key = isset( $settings['flickr_api_key'] ) ? (string) $settings['flickr_api_key'] : '';
        if ( '' === $api_key ) {
            $settings['flickr_api_key'] = '';
            return $settings;
        }

        $settings['flickr_api_key'] = Encryption::decrypt( $api_key );
        return $settings;
    }

    /**
     * Check if we should override the thumbnail output.
     */
    protected function should_override_thumbnail( int $post_id ): bool {
        if ( $post_id <= 0 ) {
            return false;
        }

        $allow = apply_filters( 'xefi_should_override_thumbnail', true, $post_id );
        if ( ! $allow ) {
            return false;
        }

        if ( get_post_meta( $post_id, '_thumbnail_id', true ) ) {
            return false;
        }

        $source = get_post_meta( $post_id, self::META_SOURCE, true );
        if ( 'external' !== $source ) {
            return false;
        }

        $data = $this->get_external_image_data( $post_id );
        return ! empty( $data['url'] );
    }

    /**
     * Retrieve external image data for a post.
     */
    public function get_external_image_data( int $post_id ): array {
        if ( $post_id <= 0 ) {
            return [];
        }

        $source = get_post_meta( $post_id, self::META_SOURCE, true );
        if ( 'external' !== $source ) {
            return [];
        }

        $resolved = get_post_meta( $post_id, self::META_RESOLVED, true );
        $url      = get_post_meta( $post_id, self::META_URL, true );

        if ( ! $resolved || ! $url ) {
            $result = $this->maybe_resolve_post_image( $post_id, false );
            if ( is_wp_error( $result ) ) {
                return [];
            }

            $resolved = $result['url'] ?? '';
            $url      = $result['original_url'] ?? $url;
        }

        if ( ! $resolved ) {
            return [];
        }

        return [
            'url'          => $resolved,
            'original_url' => $url,
            'type'         => $this->is_flickr_url( $url ) ? 'flickr' : 'direct',
            'photo_id'     => get_post_meta( $post_id, self::META_PHOTO_ID, true ),
        ];
    }

    /**
     * Clear cached state when no external image should be used.
     */
    protected function clear_external_state( int $post_id, bool $preserve_url = true ): void {
        delete_post_meta( $post_id, self::META_RESOLVED );
        delete_post_meta( $post_id, self::META_RESOLVED_AT );
        delete_post_meta( $post_id, self::META_PHOTO_ID );
        delete_post_meta( $post_id, self::META_CACHED_INPUT );
        if ( ! $preserve_url ) {
            delete_post_meta( $post_id, self::META_URL );
        }
    }

    /**
     * Load the plugin textdomain.
     */
    public function load_textdomain(): void {
        load_plugin_textdomain( 'wp-external-featured-image', false, dirname( plugin_basename( XEFI_PLUGIN_FILE ) ) . '/languages' );
    }

    /**
     * Store an error message for the editor.
     */
    protected function set_error( int $post_id, string $message ): void {
        update_post_meta( $post_id, self::META_ERROR, $message );
    }

    /**
     * Clear the stored error message.
     */
    protected function clear_error( int $post_id ): void {
        delete_post_meta( $post_id, self::META_ERROR );
    }

    /**
     * Sanitize the source meta value.
     */
    public function sanitize_meta_source( $value ) {
        return 'external' === $value ? 'external' : 'media';
    }

    /**
     * Sanitize the external URL meta value.
     */
    public function sanitize_meta_url( $value ) {
        $value = is_string( $value ) ? trim( $value ) : '';
        if ( '' === $value ) {
            return '';
        }

        $sanitized = esc_url_raw( $value );
        if ( ! $sanitized ) {
            return '';
        }

        if ( ! $this->is_https_url( $sanitized ) ) {
            return '';
        }

        return $sanitized;
    }

    /**
     * Determine if URL uses HTTPS.
     */
    protected function is_https_url( string $url ): bool {
        return 'https' === wp_parse_url( $url, PHP_URL_SCHEME );
    }

    /**
     * Determine if the URL is a Flickr page.
     */
    protected function is_flickr_url( string $url ): bool {
        return (bool) preg_match( '#^https://(?:www\.)?flickr\.com/photos/[^/]+/\d+(?:/|$)#i', $url );
    }

    /**
     * Determine if the URL appears to be a direct image URL.
     */
    protected function is_direct_image_url( string $url ): bool {
        $path = wp_parse_url( $url, PHP_URL_PATH );
        if ( ! $path ) {
            return false;
        }

        $extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        return in_array( $extension, [ 'jpg', 'jpeg', 'png' ], true );
    }
}
