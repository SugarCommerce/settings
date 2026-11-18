<?php
/**
 * The REST classes a controller is handed, as far as the kit's use them.
 *
 * Just enough of core's to call a controller the way the server does: a
 * request with parameters and headers, and a response with data, a status
 * and headers. Header names are normalised as core normalises them --
 * lower case, hyphens as underscores -- because a controller reading
 * `if_none_match` is reading what core would hand it.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

if ( ! class_exists( 'WP_REST_Server' ) ) {
	/**
	 * The method constants.
	 */
	class WP_REST_Server {
		public const READABLE  = 'GET';
		public const CREATABLE = 'POST';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * A request: parameters and headers.
	 */
	class WP_REST_Request {
		private $params  = [];
		private $headers = [];
		private $method;
		private $route;

		public function __construct( $method = '', $route = '' ) {
			$this->method = $method;
			$this->route  = $route;
		}

		public function set_param( $key, $value ) {
			$this->params[ $key ] = $value;
		}

		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function set_header( $key, $value ) {
			$this->headers[ strtolower( str_replace( '-', '_', $key ) ) ] = $value;
		}

		public function get_header( $key ) {
			return $this->headers[ strtolower( str_replace( '-', '_', $key ) ) ] ?? null;
		}

		public function get_method() {
			return $this->method;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * A response: data, a status and headers.
	 */
	class WP_REST_Response {
		private $data;
		private $status;
		private $headers = [];

		public function __construct( $data = null, $status = 200, $headers = [] ) {
			$this->data    = $data;
			$this->status  = $status;
			$this->headers = $headers;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status() {
			return $this->status;
		}

		public function set_headers( $headers ) {
			$this->headers = $headers;
		}

		public function header( $key, $value, $replace = true ) {
			$this->headers[ $key ] = $value;
		}

		public function get_headers() {
			return $this->headers;
		}
	}
}
