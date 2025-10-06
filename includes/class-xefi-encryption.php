<?php
/**
 * Encryption helper for sensitive data in WP External Featured Image.
 *
 * @package WP_External_Featured_Image
 */

namespace XEFI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles encryption and decryption of sensitive data.
 */
class Encryption {
    /**
     * Encrypt a string.
     *
     * @param string $value The value to encrypt.
     * @return string The encrypted value.
     */
    public static function encrypt( string $value ): string {
        if ( '' === $value ) {
            return '';
        }

        $key       = self::get_key();
        $iv        = openssl_random_pseudo_bytes( openssl_cipher_iv_length( 'aes-256-cbc' ) );
        $encrypted = openssl_encrypt( $value, 'aes-256-cbc', $key, 0, $iv );

        if ( false === $encrypted ) {
            return '';
        }

        return base64_encode( $iv . $encrypted );
    }

    /**
     * Decrypt a string.
     *
     * @param string $value The encrypted value.
     * @return string The decrypted value.
     */
    public static function decrypt( string $value ): string {
        if ( '' === $value ) {
            return '';
        }

        $key  = self::get_key();
        $data = base64_decode( $value, true );

        if ( false === $data ) {
            return '';
        }

        $iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
        if ( strlen( $data ) < $iv_length ) {
            return '';
        }

        $iv        = substr( $data, 0, $iv_length );
        $encrypted = substr( $data, $iv_length );
        $decrypted = openssl_decrypt( $encrypted, 'aes-256-cbc', $key, 0, $iv );

        if ( false === $decrypted ) {
            return '';
        }

        return $decrypted;
    }

    /**
     * Obscure a string for display, showing only the last 4 characters.
     *
     * @param string $value The value to obscure.
     * @return string The obscured value.
     */
    public static function obscure( string $value ): string {
        if ( '' === $value ) {
            return '';
        }

        $length = strlen( $value );
        if ( $length <= 4 ) {
            return str_repeat( 'x', $length );
        }

        return str_repeat( 'x', $length - 4 ) . substr( $value, -4 );
    }

    /**
     * Get the encryption key.
     *
     * @return string
     */
    protected static function get_key(): string {
        if ( defined( 'AUTH_KEY' ) && '' !== AUTH_KEY ) {
            return hash( 'sha256', AUTH_KEY );
        }

        return hash( 'sha256', 'xefi-default-key' );
    }
}
