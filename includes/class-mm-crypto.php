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

			$iv = openssl_random_pseudo_bytes( 16 );
			if ( false === $iv ) {
				return '';
			}

			$cipher = openssl_encrypt( (string) $plain_text, 'AES-256-CBC', $this->get_key_material(), 0, $iv );
			if ( false === $cipher ) {
				return '';
			}

			return base64_encode( $iv . '::' . $cipher );
		}

		public function decrypt( $encoded_text ) {
			if ( '' === $encoded_text || null === $encoded_text ) {
				return '';
			}

			$decoded = base64_decode( (string) $encoded_text, true );
			if ( false === $decoded ) {
				return '';
			}

			$parts = explode( '::', $decoded, 2 );
			if ( 2 !== count( $parts ) ) {
				return '';
			}

			$plain = openssl_decrypt( $parts[1], 'AES-256-CBC', $this->get_key_material(), 0, $parts[0] );
			return false === $plain ? '' : (string) $plain;
		}
	}
}
