<?php

if ( ! class_exists( 'MM_Crypto' ) ) {
	class MM_Crypto {

		private function get_key_material() {
			$auth = defined( 'AUTH_KEY' ) ? AUTH_KEY : '';
			$salt = defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '';
			return hash( 'sha256', $auth . $salt, true );
		}

		public function encrypt( $plain_text ) {
			if ( '' === $plain_text || null === $plain_text ) {
				return '';
			}

			$iv = openssl_random_pseudo_bytes( 12 );
			if ( false === $iv ) {
				return '';
			}

			$tag = '';
			$cipher = openssl_encrypt( (string) $plain_text, 'aes-256-gcm', $this->get_key_material(), OPENSSL_RAW_DATA, $iv, $tag );
			if ( false === $cipher ) {
				return '';
			}

			return base64_encode( $iv . $tag . $cipher );
		}

		public function decrypt( $encoded_text ) {
			if ( '' === $encoded_text || null === $encoded_text ) {
				return '';
			}

			$decoded = base64_decode( (string) $encoded_text, true );
			if ( false === $decoded ) {
				return '';
			}

			// Minimum: 12 (IV) + 16 (tag) + 1 (ciphertext) = 29 bytes.
			if ( strlen( $decoded ) < 29 ) {
				return '';
			}

			$iv     = substr( $decoded, 0, 12 );
			$tag    = substr( $decoded, 12, 16 );
			$cipher = substr( $decoded, 28 );
			$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $this->get_key_material(), OPENSSL_RAW_DATA, $iv, $tag );
			return false === $plain ? '' : (string) $plain;
		}
	}
}
