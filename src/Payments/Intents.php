<?php
/**
 * Creating the server's record of what is about to be charged.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar\Payments;

use STG\Core\Subscriptions\Plans;
use STG\Core\Subscriptions\Repository as Subscription_Repository;
use STG\Core\Subscriptions\Service as Subscription_Service;
use STG\Core\Support\Response;
use STG\Moyasar\Settings;
use STG\Moyasar\Support\Log;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where the amount is decided, once, by the server.
 *
 * This is the class that makes price tampering structurally impossible rather
 * than merely checked for. The browser sends a plan SLUG. It has no way to
 * express a price, because no code path anywhere reads a price from a request:
 * the figure is looked up from the plan and written into a database row before
 * the customer ever sees the payment form, and settlement later compares what
 * was actually charged against that stored row rather than against anything
 * that came back from the client.
 *
 * A snapshot rather than a live lookup, deliberately. If an administrator edits
 * a plan's price while somebody is half-way through paying, the customer is
 * charged what they were quoted - and if Moyasar reports anything else, the
 * mismatch is caught because the comparison is against the snapshot.
 */
final class Intents {

	/**
	 * Find or create the open intent for a user and plan.
	 *
	 * Idempotent by construction. A double-click, a second browser tab and a
	 * refreshed form post all arrive here, and all three get the same row: the
	 * unique index on open intents refuses the second insert, and the refusal
	 * is read as "somebody already made this" rather than as an error.
	 *
	 * @param int    $user_id   User.
	 * @param string $plan_slug Plan.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function open( int $user_id, string $plan_slug ): array|WP_Error {
		if ( $user_id < 1 ) {
			return Response::unauthenticated();
		}

		$plan = Plans::find( $plan_slug );

		if ( ! $plan ) {
			return Response::not_found();
		}

		/*
		 * An unpriced plan cannot be bought.
		 *
		 * STG ships its paid plans with `price => null` because the commercial
		 * questions behind them are open, and `Plans::is_purchasable()` is the
		 * project's existing answer to "is this a real offer yet". Honouring it
		 * here means the checkout cannot be reached for a plan that has no
		 * price, rather than charging zero.
		 */
		/*
		 * Nor a plan priced in a currency the merchant account cannot take.
		 * It is never charged in SAR instead, and never converted: the
		 * amount and currency on the intent are the plan's, and Settlement
		 * refuses a payment whose amount or currency differs from them.
		 */
		if ( null !== $plan['price'] && ! Settings::accepts_currency( (string) $plan['currency'] ) ) {
			return Response::conflict(
				__( 'This plan cannot be paid for online in its currency yet. Please contact us to arrange it.', 'stg-moyasar-payments' )
			);
		}

		// Nor can a plan made for the other side of the marketplace.
		if ( ! Plans::is_purchasable( $plan ) || ! Plans::open_to( $plan, $user_id ) ) {
			return Response::conflict(
				__( 'That plan is not available to buy online yet.', 'stg-moyasar-payments' )
			);
		}

		if ( ! Settings::is_operational() ) {
			return Response::error(
				'stg_moyasar_unavailable',
				__( 'Online payment is not available right now. Please contact us to arrange a plan.', 'stg-moyasar-payments' ),
				503
			);
		}

		/*
		 * Do not sell somebody what they already have - until it is nearly
		 * over. In the last days of a plan its holder may buy the next period
		 * (Subscription_Service::can_renew); Settlement starts that period
		 * when the current one ends, so no paid day is lost. Before then the
		 * same plan is refused, which is what stops a double click on the
		 * pricing page becoming two months.
		 */
		$live = Subscription_Repository::live_for_user( $user_id );

		if ( $live && (string) $live['plan_slug'] === $plan['slug'] && ! Subscription_Service::can_renew( $user_id ) ) {
			$opens = Subscription_Service::renewal_opens_at( $live );

			return Response::conflict(
				$opens > 0
					? sprintf(
						/* translators: %s: date */
						__( 'You are already on this plan. You can renew it from %s.', 'stg-moyasar-payments' ),
						wp_date( (string) get_option( 'date_format' ), $opens )
					)
					: __( 'You are already on this plan.', 'stg-moyasar-payments' )
			);
		}

		$mode     = Settings::mode();
		$amount   = (int) $plan['price'];
		$currency = strtoupper( (string) $plan['currency'] );
		$ttl      = max( 5, min( 120, (int) Settings::get( 'intent_ttl_minutes', 30 ) ) );

		$existing = Repository::open_intent( $user_id, $plan['slug'] );

		if ( $existing ) {
			$fresh = strtotime( (string) $existing['expires_at'] . ' UTC' ) > time();
			$same  = (int) $existing['amount'] === $amount
				&& strtoupper( (string) $existing['currency'] ) === $currency
				&& (string) $existing['mode'] === $mode;

			if ( $fresh && $same ) {
				return $existing;
			}

			/*
			 * Stale, or quoted at a price that no longer applies, or minted in
			 * a different mode. Close it rather than reusing it - honouring
			 * yesterday's price for a day is a decision nobody made, and
			 * settling a test-mode intent against live keys is worse.
			 */
			Repository::transition( (int) $existing['id'], Status::PENDING, Status::EXPIRED );
		}

		$reference = Repository::new_reference();

		$id = Repository::insert(
			array(
				'public_ref'  => $reference,
				'user_id'     => $user_id,
				'plan_slug'   => $plan['slug'],
				'amount'      => $amount,
				'currency'    => $currency,
				'period_days' => self::period_days( (string) $plan['interval'] ),
				'provider'    => 'moyasar',
				'mode'        => $mode,
				'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + ( $ttl * MINUTE_IN_SECONDS ) ),
			)
		);

		if ( $id < 1 ) {
			// The constraint refused it, which means a concurrent request won.
			// Its row is the right answer.
			$concurrent = Repository::open_intent( $user_id, $plan['slug'] );

			if ( $concurrent ) {
				return $concurrent;
			}

			Log::error(
				'Could not open a payment intent and no concurrent one exists.',
				array(
					'user' => $user_id,
					'plan' => $plan['slug'],
				)
			);

			return Response::error(
				'stg_moyasar_intent_failed',
				__( 'We could not start that payment. Please try again.', 'stg-moyasar-payments' ),
				500
			);
		}

		Log::info(
			'Payment intent opened.',
			array(
				'ref'    => $reference,
				'user'   => $user_id,
				'plan'   => $plan['slug'],
				'amount' => $amount,
				'mode'   => $mode,
			)
		);

		$row = Repository::find( $id );

		return $row ? $row : Response::not_found();
	}

	/**
	 * How many days a plan's interval buys.
	 *
	 * Fixed lengths rather than calendar months, matching what
	 * `Subscriptions\Service::start()` already does with `$period_days`.
	 * Calendar arithmetic would be more correct and would also mean a
	 * subscription bought on the 31st has no renewal date in February, which is
	 * a problem worth not having until somebody asks for it.
	 *
	 * @param string $interval 'month' or 'year'.
	 * @return int
	 */
	public static function period_days( string $interval ): int {
		return 'year' === $interval ? 365 : 30;
	}

	/**
	 * The checkout URL for an intent.
	 *
	 * @param string $reference Public reference.
	 * @return string
	 */
	public static function checkout_url( string $reference ): string {
		return home_url( '/account/checkout/' . $reference . '/' );
	}

	/**
	 * The URL Moyasar returns the customer to.
	 *
	 * Built from the intent's own path with no query string of its own, because
	 * the provider appends `id`, `status` and `message` to whatever it is given.
	 *
	 * @param string $reference Public reference.
	 * @return string
	 */
	public static function return_url( string $reference ): string {
		return home_url( '/account/checkout/' . $reference . '/return/' );
	}
}
