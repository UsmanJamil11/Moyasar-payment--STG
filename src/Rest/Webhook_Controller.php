<?php
/**
 * The Moyasar webhook endpoint.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar\Rest;

use STG\Core\Rest\Controller;
use STG\Core\Support\Rate_Limiter;
use STG\Core\Support\Response;
use STG\Moyasar\Credentials;
use STG\Moyasar\Mode;
use STG\Moyasar\Payments\Repository;
use STG\Moyasar\Payments\Settlement;
use STG\Moyasar\Payments\Status;
use STG\Moyasar\Settings;
use STG\Moyasar\Support\Log;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `POST /wp-json/stg/v1/payments/moyasar/webhook`
 *
 * ## The authentication is weak, and the design accounts for that
 *
 * Moyasar authenticates a webhook with a `secret_token` **inside the JSON
 * body**. That is materially weaker than a signed webhook, and it is worth
 * being precise about why rather than waving at it:
 *
 * - **No integrity binding.** The token proves the sender once knew a secret.
 *   It proves nothing about the body that arrived with it, so anyone who learns
 *   the token can forge any event for any payment id.
 * - **No replay protection.** No timestamp, no nonce, nothing that expires. A
 *   captured request stays valid indefinitely.
 * - **It is in the worst possible place for a secret.** Request bodies are what
 *   proxies, WAFs, APM agents and "log every REST request" plugins capture by
 *   default. A signature header leaking is survivable; the credential itself
 *   leaking is not.
 *
 * The mitigation is not a cleverer check. It is that **the token authenticates
 * the channel, not the fact**. Every event this endpoint accepts causes an
 * independent `GET /payments/{id}` against Moyasar with the secret key, and
 * only that answer is acted on. A forged webhook can therefore do exactly one
 * thing: ask STG to re-check a payment it already knows about. The
 * compare-and-set in `Repository::claim_paid()` means even a correctly-forged
 * `payment_paid` for a real payment cannot activate anything twice.
 *
 * ## Why the permission callback is named
 *
 * `verify_token()` rather than `public_route()`. STG Core's base controller
 * documents `public_route()` as the greppable marker for "genuinely public and
 * harmless to probe", and this route is neither - it is authenticated, just not
 * by WordPress. Reusing that name would corrupt an audit the project relies on.
 *
 * ## Timing
 *
 * Moyasar retries roughly six times over two hours and then gives up, so the
 * cost of being slow is low and the cost of a wrong 2xx is permanent. The
 * handler therefore verifies inline rather than queueing: `wp-cron` is
 * traffic-triggered, so on a quiet site a deferred job could sit for minutes
 * while a paying customer stares at a pending screen. The cases that return a
 * non-2xx deliberately are an unreachable provider and a refused API key,
 * because those are exactly the cases where a retry helps.
 */
final class Webhook_Controller extends Controller {

	/**
	 * Largest body this endpoint will parse.
	 *
	 * A Moyasar event is a few kilobytes. Anything far larger is not a webhook,
	 * and deciding that before `json_decode` keeps a memory-exhaustion attempt
	 * cheap to refuse.
	 */
	private const MAX_BYTES = 65536;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/payments/moyasar/webhook',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle' ),
					'permission_callback' => array( $this, 'verify_token' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/me/payments/(?P<reference>[a-f0-9]{32})',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'mine' ),
					'args'                => array(
						'reference' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => static fn( $value ): string => strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) $value ) ),
						),
					),
					'permission_callback' => array( $this, 'require_login' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/me/payments/(?P<reference>[a-f0-9]{32})/attempt',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'attempt' ),
					'args'                => array(
						'reference'  => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => static fn( $value ): string => strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) $value ) ),
						),
						'payment_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => static fn( $value ): string => trim( (string) $value ),
						),
					),
					'permission_callback' => array( $this, 'require_login' ),
				),
			)
		);
	}

	/**
	 * Authenticate the request from the shared secret in its body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function verify_token( WP_REST_Request $request ): bool|WP_Error {
		/*
		 * Rate limit first, and generously.
		 *
		 * Generous because Moyasar's own retry behaviour is bursty and locking
		 * out a legitimate retry is worse than absorbing a few junk requests.
		 * Present because this endpoint is unauthenticated until the token is
		 * checked, and an attacker who cannot guess the token can still try.
		 */
		$limited = $this->rate_limit( 'moyasar.webhook', 120, 5 * MINUTE_IN_SECONDS );

		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$body = (string) $request->get_body();

		if ( '' === $body || strlen( $body ) > self::MAX_BYTES ) {
			return new WP_Error(
				'stg_moyasar_bad_request',
				__( 'Malformed request.', 'stg-moyasar-payments' ),
				array( 'status' => 400 )
			);
		}

		$payload = json_decode( $body, true );

		if ( ! is_array( $payload ) ) {
			return new WP_Error(
				'stg_moyasar_bad_request',
				__( 'Malformed request.', 'stg-moyasar-payments' ),
				array( 'status' => 400 )
			);
		}

		$mode      = Settings::mode();
		$configured = Credentials::webhook_secret( $mode );

		/*
		 * No secret configured means the endpoint is not ready, not that
		 * anything may pass. An empty configured value must never compare equal
		 * to an empty supplied one, which is exactly what a naive `===` would
		 * do.
		 */
		if ( '' === $configured ) {
			Log::warning( 'A webhook arrived but no webhook secret is configured for this mode.', array( 'mode' => $mode ) );

			return new WP_Error(
				'stg_moyasar_webhook_unconfigured',
				__( 'This endpoint is not configured.', 'stg-moyasar-payments' ),
				array( 'status' => 503 )
			);
		}

		// Trimmed like the stored value is: a space copied into Moyasar's
		// dashboard field is not part of anybody's secret.
		$supplied = isset( $payload['secret_token'] ) && is_scalar( $payload['secret_token'] )
			? trim( (string) $payload['secret_token'] )
			: '';

		// Constant time. A `===` here leaks the secret one byte at a time to
		// anyone patient enough to measure.
		if ( ! hash_equals( $configured, $supplied ) ) {
			/*
			 * Warning, not critical, and with nothing identifying in it. A
			 * scanner hitting this route should not be able to fill the log,
			 * and the token itself is of course never written down - Core's
			 * logger would redact a key called `secret_token` twice over, but
			 * the value is not passed in the first place.
			 */
			/*
			 * Enough to tell "no token sent", "a different token" and "the
			 * same token with something extra" apart, and nothing of either
			 * value: lengths, the body's top-level field names, and whether
			 * the provider claims live.
			 */
			Log::warning(
				'Rejected a webhook whose secret did not match.',
				array(
					'mode'            => $mode,
					// Named plainly: Core's logger redacts keys containing "token" or "secret".
					'field_present'   => isset( $payload['secret_token'] ) ? 'yes' : 'no',
					'sent_length'     => strlen( $supplied ),
					'saved_length'    => strlen( $configured ),
					'body_fields'     => implode( ',', array_slice( array_map( 'strval', array_keys( $payload ) ), 0, 12 ) ),
					'provider_live'   => Mode::from_live_flag( $payload['live'] ?? false ),
				)
			);

			return new WP_Error(
				'stg_moyasar_webhook_unauthorised',
				__( 'Not authorised.', 'stg-moyasar-payments' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Handle a verified webhook.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$payload = json_decode( (string) $request->get_body(), true );
		$payload = is_array( $payload ) ? $payload : array();

		$event = isset( $payload['type'] ) && is_scalar( $payload['type'] ) ? (string) $payload['type'] : '';
		$data  = is_array( $payload['data'] ?? null ) ? $payload['data'] : array();
		$mode  = Settings::mode();

		// Powers the "last webhook received" line on the diagnostics screen,
		// which is the first thing to check when confirmations stop arriving.
		update_option( 'stg_moyasar_last_webhook', time(), false );

		/*
		 * The provider's own view of which environment this is.
		 *
		 * A mismatch means the webhook endpoint in the Moyasar dashboard is
		 * pointed at the wrong environment - an easy mistake whose symptom
		 * would otherwise be sandbox events granting real plans. Answered 200
		 * rather than rejected, because the event is genuinely not ours and
		 * making Moyasar retry it six times changes nothing.
		 *
		 * Checked only when the body carries `live`, as Settlement does for
		 * payment objects (which turned out to carry no such field). Treating a
		 * missing flag as "test" would make a live site ignore every real event
		 * with a 200, so Moyasar would never retry it. Without the flag nothing
		 * is lost: settlement re-fetches the payment with this site's own key,
		 * and a sandbox payment id is unknown to the live key.
		 */
		$live_known = array_key_exists( 'live', $payload );

		if ( $live_known && ! Mode::agrees_with_live_flag( $mode, $payload['live'] ) ) {
			Log::critical(
				'A webhook arrived for a different environment than this site is configured for. It was ignored.',
				array(
					'event'         => $event,
					'mode'          => $mode,
					'provider_says' => Mode::from_live_flag( $payload['live'] ),
				)
			);

			return new WP_REST_Response( array( 'ignored' => 'mode_mismatch' ), 200 );
		}

		$known = Status::webhook_events();

		if ( ! array_key_exists( $event, $known ) ) {
			/*
			 * 200, never 400.
			 *
			 * A new event type Moyasar adds should not burn six retries and get
			 * dropped as though something were broken. Logged so somebody
			 * notices the vocabulary changed.
			 */
			Log::info( 'Ignoring a webhook event this integration does not handle.', array( 'event' => $event ) );

			return new WP_REST_Response( array( 'ignored' => 'unknown_event' ), 200 );
		}

		if ( ! $known[ $event ] ) {
			// Acknowledged and deliberately inert: the card-authentication
			// events belong to a standalone 3DS flow STG does not use.
			return new WP_REST_Response( array( 'ignored' => 'not_applicable' ), 200 );
		}

		$payment_id = isset( $data['id'] ) && is_scalar( $data['id'] ) ? (string) $data['id'] : '';

		if ( '' === $payment_id ) {
			Log::warning( 'A webhook arrived with no payment id.', array( 'event' => $event ) );

			return new WP_REST_Response( array( 'ignored' => 'no_payment_id' ), 200 );
		}

		$reference = '';

		if ( isset( $data['metadata']['stg_ref'] ) && is_scalar( $data['metadata']['stg_ref'] ) ) {
			$candidate = (string) $data['metadata']['stg_ref'];
			$reference = preg_match( '/^[a-f0-9]{32}$/', $candidate ) ? $candidate : '';
		}

		/*
		 * Everything above was about deciding WHICH payment to ask about.
		 * Nothing in the body has been treated as a fact, and the settlement
		 * below goes to the API for the answer.
		 *
		 * Note that `payment_faild` - the spelling Moyasar's dashboard guide
		 * uses, alongside `payment_failed` in its API reference - lands here
		 * exactly like every other event, because the event name selects
		 * nothing but a log line.
		 */
		$row = Settlement::settle( $payment_id, $reference, 'webhook' );

		/*
		 * Nobody could be asked, so nothing is known.
		 *
		 * Answering 200 here told Moyasar the event was dealt with, and it
		 * never sent it again - leaving the hourly sweep as the only thing
		 * that would ever notice the payment. A 503 makes Moyasar retry.
		 *
		 * The same when this site's own key was refused: the event has not
		 * been verified, and a corrected key inside the retry window settles it.
		 */
		if ( Settlement::provider_unavailable() || Settlement::provider_refused_credentials() ) {
			$response = new WP_REST_Response( array( 'received' => false ), 503 );
			$response->header( 'Retry-After', '60' );

			return $response;
		}

		Log::info(
			'Webhook processed.',
			array(
				'event'        => $event,
				'mode'         => $mode,
				// Whether Moyasar sent its environment flag, and which way.
				// The first live delivery is where this gets confirmed.
				'live_present' => $live_known ? 'yes' : 'no',
				'provider'     => $live_known ? Mode::from_live_flag( $payload['live'] ) : '',
				'ref'          => $row ? (string) $row['public_ref'] : '',
				'result'       => $row ? (string) $row['status'] : 'unresolved',
			)
		);

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Note which provider payment a caller's own checkout has just started.
	 *
	 * Moyasar's form reports the payment it created before it sends the
	 * customer to their bank's 3-D Secure page, and its documentation asks for
	 * the id to be kept at that moment: if the customer never comes back, it is
	 * how the payment is found again. The id is stored as a hint for the
	 * reconciler and decides nothing - settlement still asks the provider.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function attempt( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$limited = $this->rate_limit( 'moyasar.attempt', 30, 10 * MINUTE_IN_SECONDS );

		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$row = Repository::find_by_reference( (string) $request->get_param( 'reference' ) );

		if ( ! $row || (int) $row['user_id'] !== get_current_user_id() ) {
			return Response::not_found();
		}

		$payment_id = (string) $request->get_param( 'payment_id' );

		// The same shape Api\Client::fetch() will insist on before asking.
		if ( ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $payment_id ) ) {
			return Response::error(
				'stg_moyasar_bad_payment_id',
				__( 'That payment reference is not valid.', 'stg-moyasar-payments' ),
				400
			);
		}

		return Response::ok(
			array(
				'recorded' => Repository::note_attempt( (int) $row['id'], $payment_id ),
			)
		);
	}

	/**
	 * A caller's own payment, for the "we are confirming this" poll.
	 *
	 * The return screen shows a confirming state when the provider says a
	 * payment is still in flight, and polls this until it settles. The endpoint
	 * exists on the API rather than as an admin-ajax call so the mobile app can
	 * use the same one.
	 *
	 * Ownership is checked even though the reference is 128 bits of entropy.
	 * Unguessable is not an authorisation model, and a reference can end up in
	 * a shared screenshot or a browser history on a family computer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function mine( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row = Repository::find_by_reference( (string) $request->get_param( 'reference' ) );

		// 404 rather than 403 for somebody else's payment. A 403 would confirm
		// the reference exists, which is the existence oracle STG Core's base
		// controller exists to prevent.
		if ( ! $row || (int) $row['user_id'] !== get_current_user_id() ) {
			return Response::not_found();
		}

		return Response::ok(
			array(
				'reference' => (string) $row['public_ref'],
				'status'    => (string) $row['status'],
				'settled'   => in_array( (string) $row['status'], Status::terminal(), true ),
				'plan'      => (string) $row['plan_slug'],
				'amount'    => (int) $row['amount'],
				'currency'  => (string) $row['currency'],
				'mode'      => (string) $row['mode'],
				/*
				 * Note what is absent: `provider_payment_id`, `failure_message`
				 * and `flag_reason`. The provider id is an internal correlation
				 * handle and publishing it invites somebody to try it against
				 * Moyasar's API - the same reasoning Core applies to
				 * `gateway_ref`. The failure message is English-only provider
				 * text that names internals; the screen renders a translated
				 * message from the status instead.
				 */
			)
		);
	}
}
