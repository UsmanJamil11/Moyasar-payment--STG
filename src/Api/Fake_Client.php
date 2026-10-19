<?php
/**
 * A local stand-in for Moyasar's API.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar\Api;

use RuntimeException;
use STG\Moyasar\Credentials;
use STG\Moyasar\Mode;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Simulates Moyasar so the whole lifecycle can be exercised with no account.
 *
 * **It replaces the HTTP client, not the gateway.** That distinction is the
 * entire value of this class. Everything above the `Provider` boundary -
 * intent creation, the compare-and-set, status mapping, the flagged paths, the
 * webhook handler, all six front-end states - runs completely unchanged under
 * test, so what the test suite proves is a property of the code that ships. A
 * fake that implemented `Subscriptions\Gateway` instead would exercise a path
 * that exists only in tests, which is the expensive kind of green.
 *
 * ## Why it cannot run in production
 *
 * Four conditions, checked together, and the constructor THROWS rather than
 * degrading if any fails. A filter pointed at this class on a live site
 * produces a fatal error on the next request, which is loud, immediate and
 * fixable - unlike a payment page that silently grants plans for free.
 *
 * 1. `wp_get_environment_type()` is `local`.
 * 2. `STG_MOYASAR_FAKE` is defined and truthy. A wp-config constant, so it
 *    cannot be set from the database, from wp-admin, or by any plugin.
 * 3. `WP_DEBUG` is on.
 * 4. No live credential is resolvable anywhere. A site that can reach live
 *    Moyasar must never be running a fake provider, whatever else is true.
 *
 * Note that none of the four is a setting. An administrator cannot switch this
 * on, and neither can a compromised administrator account.
 *
 * Every payment it produces is stamped `mode = 'fake'`, and every subscription
 * that results carries `gateway = 'moyasar_fake'`. Those markers are permanent
 * and queryable, so fake data is distinguishable from real test data for ever
 * and is excluded from any revenue report by a WHERE clause rather than by
 * somebody remembering.
 *
 * ## Scripted outcomes
 *
 * Behaviour is keyed off the last two digits of the amount, so tests are
 * deterministic and need no fixtures:
 *
 * - `…00` paid
 * - `…01` failed
 * - `…02` initiated on the first fetch, paid on the second (the "confirming" state)
 * - `…03` paid, but for the wrong amount (the mismatch path)
 * - `…04` paid, but reports a different payment id (the double-charge path)
 * - `…05` paid and then fully refunded
 * - `…06` paid but claims `live: true` (the mode-mismatch path)
 * - `…07` authorized, never captured (the "looks like success, is not" path)
 * - `…08` expired at the provider before anybody finished paying
 */
final class Fake_Client implements Provider {

	/**
	 * Transient prefix for scripted state.
	 */
	private const STATE = 'stg_moyasar_fake_';

	/**
	 * Constructor.
	 *
	 * @throws RuntimeException When any gate is not satisfied.
	 */
	public function __construct() {
		if ( ! self::is_installed() ) {
			throw new RuntimeException(
				'The fake Moyasar client refused to start. It runs only on a local environment, '
				. 'with STG_MOYASAR_FAKE defined, WP_DEBUG on, and no live credentials present.'
			);
		}
	}

	/**
	 * Whether every gate is satisfied.
	 *
	 * Static so callers can ask without constructing, and so `Settings::mode()`
	 * can consult it without risking an exception on a normal page load.
	 *
	 * @return bool
	 */
	public static function is_installed(): bool {
		if ( ! defined( 'STG_MOYASAR_FAKE' ) || ! constant( 'STG_MOYASAR_FAKE' ) ) {
			return false;
		}

		if ( 'local' !== wp_get_environment_type() ) {
			return false;
		}

		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return false;
		}

		// The decisive one. A site holding a live key is a site that can take
		// real money, whatever its environment type claims.
		if ( Credentials::has_any_live() ) {
			return false;
		}

		return true;
	}

	/**
	 * Which world this client talks to.
	 *
	 * @return string
	 */
	public function mode(): string {
		return Mode::FAKE;
	}

	/**
	 * Mint a payment id for a scripted scenario.
	 *
	 * Called by the test harness in place of the browser form.
	 *
	 * @param string $reference STG public reference.
	 * @param int    $amount    Amount in minor units.
	 * @param string $currency  Currency.
	 * @return string The fake provider payment id.
	 */
	public function simulate( string $reference, int $amount, string $currency = 'SAR' ): string {
		$id = 'fake_' . bin2hex( random_bytes( 12 ) );

		set_transient(
			self::STATE . $id,
			array(
				'reference' => $reference,
				'amount'    => $amount,
				'currency'  => $currency,
				'fetches'   => 0,
			),
			HOUR_IN_SECONDS
		);

		return $id;
	}

	/**
	 * Ask the fake provider about one payment.
	 *
	 * @param string $payment_id Provider payment id.
	 * @return Payment_Result|WP_Error
	 */
	public function fetch( string $payment_id ): Payment_Result|WP_Error {
		$state = get_transient( self::STATE . $payment_id );

		if ( ! is_array( $state ) ) {
			return new WP_Error(
				'stg_moyasar_not_found',
				__( 'The payment provider has no record of that payment.', 'stg-moyasar-payments' ),
				array( 'status' => 404 )
			);
		}

		$amount   = (int) $state['amount'];
		$scenario = $amount % 100;

		++$state['fetches'];
		set_transient( self::STATE . $payment_id, $state, HOUR_IN_SECONDS );

		$payload = array(
			'id'       => $payment_id,
			'status'   => 'paid',
			'amount'   => $amount,
			'currency' => (string) $state['currency'],
			'live'     => false,
			'source'   => array( 'type' => 'creditcard' ),
			'metadata' => array( 'stg_ref' => (string) $state['reference'] ),
		);

		switch ( $scenario ) {
			case 1:
				$payload['status'] = 'failed';
				$payload['source'] = array(
					'type'               => 'creditcard',
					'message'            => 'Insufficient funds',
					'transaction_status' => 'declined',
				);
				break;

			case 2:
				// Unfinished on the first look, settled on the second.
				if ( (int) $state['fetches'] < 2 ) {
					$payload['status'] = 'initiated';
				}
				break;

			case 3:
				$payload['amount'] = $amount + 1000;
				break;

			case 4:
				$payload['id'] = 'fake_other_' . substr( $payment_id, 5, 8 );
				break;

			case 5:
				$payload['status']   = 'refunded';
				$payload['refunded'] = $amount;
				break;

			case 6:
				$payload['live'] = true;
				break;

			case 7:
				$payload['status'] = 'authorized';
				break;

			case 8:
				$payload['status'] = 'expired';
				break;
		}

		return Payment_Result::from_payload( $payload );
	}

	/**
	 * Find a simulated payment by its STG reference.
	 *
	 * @param string $reference STG public reference.
	 * @return Payment_Result|WP_Error|null
	 */
	public function find_by_reference( string $reference ): Payment_Result|WP_Error|null {
		$index = get_transient( self::STATE . 'index' );

		if ( ! is_array( $index ) || ! isset( $index[ $reference ] ) ) {
			return null;
		}

		return $this->fetch( (string) $index[ $reference ] );
	}

	/**
	 * Register a reference so reconciliation can find it.
	 *
	 * @param string $reference  STG public reference.
	 * @param string $payment_id Fake provider payment id.
	 * @return void
	 */
	public function index( string $reference, string $payment_id ): void {
		$index = get_transient( self::STATE . 'index' );
		$index = is_array( $index ) ? $index : array();

		$index[ $reference ] = $payment_id;

		set_transient( self::STATE . 'index', $index, HOUR_IN_SECONDS );
	}

	/**
	 * The fake provider is always reachable.
	 *
	 * @return true
	 */
	public function ping(): bool|WP_Error {
		return true;
	}
}
