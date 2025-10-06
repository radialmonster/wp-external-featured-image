<?php
/**
 * Flickr resolver utilities for WP External Featured Image.
 *
 * @package WP_External_Featured_Image
 */

namespace XEFI;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles resolving Flickr photo page URLs to direct image URLs.
 */
class Flickr_Resolver {
    /**
     * Singleton instance.
     *
     * @var Flickr_Resolver|null
     */
    protected static $instance = null;

    /**
     * Get singleton instance.
     */
    public static function instance(): Flickr_Resolver {
        if ( null === static::$instance ) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    /**
     * Extract the Flickr photo ID from a page URL.
     *
     * @param string $url Flickr page URL.
     * @return string|null The extracted photo ID or null.
     */
    public function extract_photo_id( string $url ): ?string {
        $pattern = '#^https://(?:www\.)?flickr\.com/photos/[^/]+/(\d+)(?:/|$)#i';
        if ( ! preg_match( $pattern, $url, $matches ) ) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Resolve a Flickr photo page URL to the best matching image URL.
     *
     * @param string $url Flickr page URL.
     * @param array  $settings Plugin settings array.
     * @return array|WP_Error Array with keys url, photo_id or WP_Error on failure.
     */
    public function resolve( string $url, array $settings ) {
        $photo_id = $this->extract_photo_id( $url );
        if ( ! $photo_id ) {
            return new WP_Error( 'xefi_invalid_flickr_url', __( 'Unable to determine Flickr photo ID from URL.', 'wp-external-featured-image' ) );
        }

        $preference = $settings['size_preference'] ?? 'optimize_social';
        $cache_ttl  = absint( $settings['cache_ttl'] ?? DAY_IN_SECONDS );

        /**
         * Filters the Flickr cache TTL before it is applied.
         *
         * @param int    $ttl      Cache TTL in seconds.
         * @param string $photo_id Flickr photo ID.
         */
        $cache_ttl = (int) apply_filters( 'xefi_cache_ttl', $cache_ttl, $photo_id );
        if ( $cache_ttl <= 0 ) {
            $cache_ttl = DAY_IN_SECONDS;
        }

        $cache_key = 'xefi_flickr_' . md5( $photo_id . '|' . $preference );
        $cached    = get_transient( $cache_key );
        if ( $cached ) {
            return [
                'url'      => $cached,
                'photo_id' => $photo_id,
                'from'     => 'cache',
            ];
        }

        $api_key = $settings['flickr_api_key'] ?? '';
        if ( empty( $api_key ) ) {
            return new WP_Error( 'xefi_missing_api_key', __( 'Flickr API key is not configured.', 'wp-external-featured-image' ) );
        }

        $response = wp_remote_get(
            add_query_arg(
                [
                    'method'         => 'flickr.photos.getSizes',
                    'api_key'        => $api_key,
                    'photo_id'       => $photo_id,
                    'format'         => 'json',
                    'nojsoncallback' => '1',
                ],
                'https://www.flickr.com/services/rest/'
            ),
            [
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'xefi_flickr_http_error', $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return new WP_Error( 'xefi_flickr_http_error', sprintf( __( 'Unexpected Flickr response code: %d', 'wp-external-featured-image' ), $code ) );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        if ( empty( $data['sizes']['size'] ) ) {
            if ( ! empty( $data['message'] ) ) {
                return new WP_Error( 'xefi_flickr_api_error', $data['message'] );
            }

            return new WP_Error( 'xefi_flickr_api_error', __( 'Unexpected Flickr API response.', 'wp-external-featured-image' ) );
        }

        $sizes = $data['sizes']['size'];
        $url   = $this->choose_best_size( $sizes, $preference );
        if ( empty( $url ) ) {
            return new WP_Error( 'xefi_no_suitable_size', __( 'Unable to determine a suitable Flickr image size.', 'wp-external-featured-image' ) );
        }

        $context = [
            'photo_id'   => $photo_id,
            'preference' => $preference,
        ];

        /**
         * Allow overriding the resolved Flickr image URL.
         *
         * @param string $url     The selected URL.
         * @param array  $sizes   Array of Flickr size data.
         * @param array  $context Contextual data including photo_id and preference.
         */
        $url = apply_filters( 'xefi_resolve_flickr_sizes', $url, $sizes, $context );

        if ( ! $url ) {
            return new WP_Error( 'xefi_no_suitable_size', __( 'Flickr size selection was overridden to an empty value.', 'wp-external-featured-image' ) );
        }

        set_transient( $cache_key, $url, $cache_ttl );

        return [
            'url'      => $url,
            'photo_id' => $photo_id,
            'from'     => 'api',
        ];
    }

    /**
     * Choose the best Flickr size based on the selection rules.
     *
     * @param array  $sizes      Sizes array from the API.
     * @param string $preference Preference key.
     * @return string|null
     */
    protected function choose_best_size( array $sizes, string $preference ): ?string {
        if ( 'largest_available' === $preference ) {
            usort(
                $sizes,
                static function ( $a, $b ) {
                    $aw = (int) ( $a['width'] ?? 0 );
                    $bw = (int) ( $b['width'] ?? 0 );
                    if ( $aw === $bw ) {
                        $ah = (int) ( $a['height'] ?? 0 );
                        $bh = (int) ( $b['height'] ?? 0 );
                        return $bh <=> $ah;
                    }

                    return $bw <=> $aw;
                }
            );

            return $sizes[0]['source'] ?? null;
        }

        $candidates = array_filter(
            $sizes,
            static function ( $size ) {
                if ( ! isset( $size['media'] ) || 'photo' !== $size['media'] ) {
                    return false;
                }

                return ! empty( $size['source'] );
            }
        );

        if ( empty( $candidates ) ) {
            return null;
        }

        usort(
            $candidates,
            static function ( $a, $b ) {
                $aw = (int) ( $a['width'] ?? 0 );
                $bw = (int) ( $b['width'] ?? 0 );
                $ah = (int) ( $a['height'] ?? 0 );
                $bh = (int) ( $b['height'] ?? 0 );

                $a_pref = ( $aw >= 1200 && $aw >= $ah ) ? 0 : 1;
                $b_pref = ( $bw >= 1200 && $bw >= $bh ) ? 0 : 1;

                if ( $a_pref !== $b_pref ) {
                    return $a_pref <=> $b_pref;
                }

                if ( $aw !== $bw ) {
                    return $bw <=> $aw;
                }

                return $bh <=> $ah;
            }
        );

        return $candidates[0]['source'] ?? null;
    }
}
