<?php
/**
 * The Moyasar HTTP client.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar\Api;

use STG\Moyasar\Credentials;
use STG\Moyasar\Mode;
use STG\Moyasar\Settings;
use STG\Moyasar\Support\Log;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only outbound HTTP in this project.
 *
 * STG Core makes no remote requests at all, so this class is establishing a
 * convention as much as implementing a feature. The rules it sets:
 *
 * - **`wp_remote_*`, never raw cURL.** Proxy configuration, the site's
 *   certificate bundle, `http_request_args` filters and the request timeout all
 *   come free, and a host that routes egress through a proxy does not need this
 *   file changed.
 * - **Certificate verification is never disabled.** Not behind a setting, not
 *   behind a constant, not "temporarily for local development". A payment
 *   client that will talk to anything holding a certificate is a payment client
 *   that can be talked to by anything.
 * - **A bounded timeout, always.** An unbounded request here would hold a
 *   PHP-FPM worker open for as long as the network felt like it, on the page a
 *   customer is watching.
 * - **The Authorization header is never logged**, and neither is a response
 *   body. See `Support\Log` for why allow-listing beats redaction.
 * - **No retries on this layer.** Every caller is idempotent by construction -
 *   these are all reads - so a retry belongs to the caller that knows whether
 *   it can afford the latency, not to a client that would silently triple a
 *   request during an outage.
 *
 * Authentication is HTTP Basic with the API key as the USERNAME and an EMPTY
 * PASSWORD. That is unusual enough to be worth stating: `sk_test_xxx:` with the
 * trailing colon and nothing after it.
 */
final class Client implements Provider {

	/**
	 * API base.
	 */
	public const BASE = 'https://api.moyasar.com/v1';

	/**
	 * Mode this client talks to.
	 *
	 * @var string
	 */
	private string $mode;

	/**
	 * Constructor.
	 *
	 * @param string $mode Mode.
	 */
	public function __construct( string $mode ) {
		$this->mode = Mode::normalise( $mode );
	}

	/**
	 * Which world this client talks to.
	 *
	 * @return string
	 */
	public function mode(): string {
		return $this->mode;
	}

	/**
	 * Ask the provider about one payment.
	 *
	 * @param string $payment_id Provider payment id.
	 * @return Payment_Result|WP_Error
	 */
	public function fetch( string $payment_id ): Payment_Result|WP_Error {
		$payment_id = trim( $payment_id );

		/*
		 * Validate before building a URL.
		 *
		 * The id arrives from a query string and from a webhook body, both of
		 * which are attacker-controlled. Interpolating an unchecked value into
		 * a path is how a request to `api.moyasar.com` becomes a request to
		 * somewhere else, and the cheapest place to stop that is before the
		 * string exists.
		 */
		if ( ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $payment_id ) ) {
			return new WP_Error(
				'stg_moyasar_bad_payment_id',
				__( 'That payment reference is not valid.', 'stg-moyasar-payments' )
			);
		}

		$response = $this->request( 'GET', '/payments/' . rawurlencode( $payment_id ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response['id'] ) ) {
			Log::error( 'Moyasar returned a payment with no id.', array( 'payment_id' => $payment_id ) );

			return new WP_Error(
				'stg_moyasar_malformed',
				__( 'The payment provider returned an unexpected response.', 'stg-moyasar-payments' )
			);
		}

		return Payment_Result::from_payload( $response );
	}

	/**
	 * Find a payment by the STG reference in its metadata.
	 *
	 * Moyasar documents metadata as searchable through List Payments, so the
	 * reference is asked for by name and the answer is still matched locally:
	 * a page that came back unfiltered is scanned exactly as it used to be. It
	 * runs from a cron rather than from a page load.
	 *
	 * @param string $reference STG public reference.
	 * @return Payment_Result|WP_Error|null
	 */
	public function find_by_reference( string $reference ): Payment_Result|WP_Error|null {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $reference ) ) {
			return null;
		}

		/*
		 * Ask for this reference by its metadata, which the API reference
		 * documents as searchable (`metadata[key]=value`). The page is still
		 * matched locally below, so a filter the API ignored costs nothing; and
		 * if it is refused outright, the unfiltered recent page is asked for
		 * instead, which is what this method did before.
		 */
		$response = $this->request( 'GET', '/payments?per=100&metadata[stg_ref]=' . rawurlencode( $reference ) );

		if ( is_wp_error( $response ) && in_array( $response->get_error_code(), array( 'stg_moyasar_http_400', 'stg_moyasar_http_422' ), true ) ) {
			$response = $this->request( 'GET', '/payments?per=100' );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$payments = is_array( $response['payments'] ?? null ) ? $response['payments'] : array();

		foreach ( $payments as $payment ) {
			if ( ! is_array( $payment ) ) {
				continue;
			}

			$result = Payment_Result::from_payload( $payment );

			if ( $result->stg_reference() === $reference ) {
				return $result;
			}
		}

		return null;
	}

	/**
	 * Check that the configured credentials authenticate.
	 *
	 * Deliberately a list request with the smallest possible page rather than a
	 * create: pressing "test connection" on a live site must not leave a
	 * payment behind.
	 *
	 * @return true|WP_Error
	 */
	public function ping(): bool|WP_Error {
		$response = $this->request( 'GET', '/payments?per=1' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Perform a request and decode the response.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path below the API base.
	 * @return array<string, mixed>|WP_Error
	 */
	private function request( string $method, string $path ): array|WP_Error {
		$secret = Credentials::secret( $this->mode );

		/*
		 * The last-line-of-defence check.
		 *
		 * A wrong constant in wp-config, a hand-edited option row, or a
		 * database restored from another environment all produce the same
		 * symptom: a key from the wrong world. Asserting the prefix here costs
		 * one regular expression and turns "customers were charged on a sandbox
		 * account for a week" into an error in the log on the first request.
		 */
		if ( ! Credentials::assert_shape( $secret, 'sk', $this->mode ) ) {
			Log::critical(
				'Refused a Moyasar request: the secret key is missing or does not match the active mode.',
				array(
					'mode'      => $this->mode,
					'has_value' => '' !== $secret,
				)
			);

			return new WP_Error(
				'stg_moyasar_not_configured',
				__( 'Payments are not configured correctly.', 'stg-moyasar-payments' ),
				array( 'status' => 503 )
			);
		}

		$url = self::BASE . $path;

		$args = array(
			'method'  => $method,
			'timeout' => max( 5, min( 30, (int) Settings::get( 'api_timeout', 15 ) ) ),
			// Never disabled. See the class comment.
			'sslverify' => true,
			'headers' => array(
				// Basic auth, key as username, EMPTY password - hence the bare
				// trailing colon.
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required by HTTP Basic authentication, not obfuscation.
				'Authorization' => 'Basic ' . base64_encode( $secret . ':' ),
				'Accept'        => 'application/json',
				'User-Agent'    => 'STG-Moyasar/' . STG_MOYASAR_VERSION . '; ' . home_url( '/' ),
			),
		);

		$started = microtime( true );

		/*
		 * `wp_remote_request`, not `wp_safe_remote_request`.
		 *
		 * The "safe" variants exist to stop a URL supplied by a user reaching
		 * internal network addresses. Here the host is a compile-time constant
		 * and the only variable part is a path segment that has already been
		 * validated against a character class - so there is no SSRF surface to
		 * protect, while the safe variant's blocked-host rules would break a
		 * legitimate outbound proxy on some hosts.
		 */
		$response = wp_remote_request( $url, $args );

		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			Log::error(
				'Moyasar request failed before a response arrived.',
				array(
					'method'  => $method,
					'path'    => $this->safe_path( $path ),
					'mode'    => $this->mode,
					'ms'      => $elapsed,
					'wp_code' => $response->get_error_code(),
				)
			);

			return new WP_Error(
				'stg_moyasar_unreachable',
				__( 'We could not reach the payment provider. Please try again in a moment.', 'stg-moyasar-payments' ),
				array( 'status' => 502 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		update_option( 'stg_moyasar_last_api_call', time(), false );

		if ( 200 !== $code ) {
			/*
			 * 404 is not an error worth shouting about, and the reason matters:
			 * asking the test API about a live payment id returns 404, which is
			 * exactly what should happen. It is how mode separation shows up on
			 * the wire.
			 */
			$context = array(
				'method' => $method,
				'path'   => $this->safe_path( $path ),
				'mode'   => $this->mode,
				'ms'     => $elapsed,
				'http'   => $code,
			);

			if ( 404 === $code ) {
				Log::warning( 'Moyasar has no record of that payment in this mode.', $context );
			} else {
				Log::error( 'Moyasar refused a request.', $context );
			}

			if ( 401 === $code || 403 === $code ) {
				return new WP_Error(
					'stg_moyasar_unauthorised',
					__( 'The payment provider rejected these API credentials.', 'stg-moyasar-payments' ),
					array( 'status' => 502 )
				);
			}

			if ( 404 === $code ) {
				return new WP_Error(
					'stg_moyasar_not_found',
					__( 'The payment provider has no record of that payment.', 'stg-moyasar-payments' ),
					array( 'status' => 404 )
				);
			}

			return new WP_Error(
				'stg_moyasar_http_' . $code,
				__( 'The payment provider returned an error.', 'stg-moyasar-payments' ),
				array( 'status' => 502 )
			);
		}

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			Log::error(
				'Moyasar returned something that is not JSON.',
				array(
					'path'  => $this->safe_path( $path ),
					'mode'  => $this->mode,
					'bytes' => strlen( $body ),
				)
			);

			return new WP_Error(
				'stg_moyasar_malformed',
				__( 'The payment provider returned an unexpected response.', 'stg-moyasar-payments' ),
				array( 'status' => 502 )
			);
		}

		Log::debug(
			'Moyasar request completed.',
			array(
				'path' => $this->safe_path( $path ),
				'mode' => $this->mode,
				'ms'   => $elapsed,
			)
		);

		return $decoded;
	}

	/**
	 * A path safe to write into a log line.
	 *
	 * Strips the payment id, which is not secret but is a correlation handle
	 * that belongs in the structured context rather than sprayed through log
	 * messages where it is harder to scrub later. The query string goes for
	 * the same reason: it can carry a checkout reference.
	 *
	 * @param string $path Request path.
	 * @return string
	 */
	private function safe_path( string $path ): string {
		$path = (string) preg_replace( '/\?.*$/', '', $path );

		return (string) preg_replace( '#/payments/[^/?]+#', '/payments/{id}', $path );
	}
}
