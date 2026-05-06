<?php

if ( ! class_exists( 'MM_DataForSEO' ) ) {
	class MM_DataForSEO {

		private function not_implemented_error( $method ) {
			return new WP_Error(
				'mm_dataforseo_not_implemented',
				sprintf( 'MM_DataForSEO::%s is not implemented yet.', $method )
			);
		}

		public function get_rankings( $keywords = array(), $location = '' ) {
			return $this->not_implemented_error( 'get_rankings' );
		}

		public function get_competitors( $domain = '', $location = '' ) {
			return $this->not_implemented_error( 'get_competitors' );
		}

		public function get_keyword_volume( $keywords = array(), $location = '' ) {
			return $this->not_implemented_error( 'get_keyword_volume' );
		}

		public function get_keyword_suggestions( $seed_keyword = '', $location = '' ) {
			return $this->not_implemented_error( 'get_keyword_suggestions' );
		}
	}
}
