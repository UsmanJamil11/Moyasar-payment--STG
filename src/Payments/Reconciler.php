<?php
/**
 * The sweep that makes a dropped webhook survivable.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar\Payments;

use STG\Moyasar\Api\Payment_Result;
use STG\Moyasar\Api\Provider;
use STG\Moyasar\Plugin;
use WP_Error;
use STG\Moyasar\Support\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Goes looking for payments nobody told STG about, and closes stale intents -
 * in that order, and never closing one it could not ask about.
 *
 * This is not a nice-to-have. Both of the channels that normally report a
 * payment can fail in ordinary ways:
 *
 * - The **browser callback** is lost if the customer closes the tab, loses
 *   signal on the way back from their bank's 3-D Secure page, or their battery
 *   dies. This is not rare; it is what a mobile payment on a train looks like.
 * - The **webhook** is lost if the site is down for the two hours Moyasar
 *   spends retrying, if a firewall eats it, or - as on this project's own
 *   development environment - if the site is simply not reachable from the
 *   internet at all.
 *
 * Without this sweep, a customer in either case has been charged and has no
 * plan, and nothing in the system ever notices. With it, webhook delivery is a
 * latency optimisation rather than a correctness dependency.
 *
 * It is also the only channel that works on `new-stg.local`, which is why the
 * whole lifecycle can be tested before the client's account exists.
 */
final class Reconciler {

	/**
	 * Run one sweep.
	 *
	 * @return array{expired: int, recovered: int}
	 */
	public static function run(): array {
		/*
		 * Ask first, close second.
		 *
		 * This used to close stale intents and then look for unreported
		 * payments among the ones still pending - so the intent most likely to
		 * hold a lost payment, one old enough to have timed out, was closed a
		 * moment before anybody asked the provider about it, and was never
		 * asked about again.
		 */
		$asked     = array();
		$reachable = true;
		$recovered = self::recover_unreported( $asked, $reachable );

		return array(
			'expired'   => self::expire_stale( $asked, $reachable, $recovered ),
			'recovered' => $recovered,
		);
	}

	/**
	 * Close intents nobody completed in time.
	 *
	 * Compare-and-set, so an intent that is being paid for at this exact moment
	 * cannot be expired out from under the customer: if settlement has already
	 * claimed it, the status is no longer `pending` and this affects no rows.
	 *
	 * An intent is only closed once the provider has said it holds no payment
	 * for it. While the provider cannot be reached the intent stays open; the
	 * next sweep asks again.
	 *
	 * @param array<int, bool> $asked     Intent ids already asked about in this sweep.
	 * @param bool             $reachable Whether the provider answered so far.
	 * @param int              $recovered Running count of recovered payments.
	 * @return int How many were closed.
	 */
	private static function expire_stale( array $asked, bool $reachable, int &$recovered ): int {
		$provider = Plugin::instance()->provider();
		$closed   = 0;

		foreach ( Repository::stale() as $row ) {
			$id = (int) $row['id'];

			if ( ! isset( $asked[ $id ] ) ) {
				if ( ! $reachable ) {
					continue;
				}

				$found = self::ask( $provider, $row );

				if ( is_wp_error( $found ) ) {
					$reachable = false;

					Log::warning(
						'Stale checkouts were left open: the provider is unreachable, so nobody can say they were not paid.',
						array( 'code' => $found->get_error_code() )
					);

					continue;
				}

				if ( null !== $found ) {
					$settled = Settlement::settle( $found->id, (string) $row['public_ref'], 'reconcile' );

					if ( $settled && Status::PAID === (string) $settled['status'] ) {
						++$recovered;
					}
				}
			}

			if ( Repository::transition( $id, Status::PENDING, Status::EXPIRED ) ) {
				++$closed;
			}
		}

		return $closed;
	}

	/**
	 * Ask the provider whether it holds a payment for one open checkout.
	 *
	 * The payment the customer's browser reported starting is asked about
	 * first, by id: it is one exact request. If that payment did not succeed,
	 * or the provider has never heard of it, the checkout's reference is
	 * searched for instead, because a customer who was declined and tried
	 * again may have paid with a payment the browser never got to report.
	 *
	 * @param Provider             $provider Provider client.
	 * @param array<string, mixed> $row      Open payment row.
	 * @return Payment_Result|WP_Error|null Null when the provider holds nothing for it.
	 */
	private static function ask( Provider $provider, array $row ): Payment_Result|WP_Error|null {
		$hint = (string) ( $row['attempt_payment_id'] ?? '' );

		if ( '' !== $hint ) {
			$hinted = $provider->fetch( $hint );

			if ( $hinted instanceof Payment_Result && Status::entitles( $hinted->status ) ) {
				return $hinted;
			}

			// Unknown or malformed ids are the browser's word against the
			// provider's, and say nothing about whether it can be reached.
			if ( is_wp_error( $hinted ) && ! in_array( $hinted->get_error_code(), array( 'stg_moyasar_not_found', 'stg_moyasar_bad_payment_id' ), true ) ) {
				return $hinted;
			}
		}

		return $provider->find_by_reference( (string) $row['public_ref'] );
	}

	/**
	 * Ask the provider about intents that never reported back.
	 *
	 * Deliberately narrow: only intents old enough that both channels have had
	 * a fair chance, and a bounded number of them, so a cheap hourly job stays
	 * cheap. The stale ones it does not reach are asked about one by one in
	 * expire_stale() before they are closed.
	 *
	 * @param array<int, bool> $asked     Filled with the intent ids asked about.
	 * @param bool             $reachable Set false when the provider does not answer.
	 * @return int How many turned out to have been paid.
	 */
	private static function recover_unreported( array &$asked, bool &$reachable ): int {
		$provider  = Plugin::instance()->provider();
		$recovered = 0;

		foreach ( Repository::unreconciled() as $row ) {
			$found = self::ask( $provider, $row );

			if ( is_wp_error( $found ) ) {
				// The provider is unreachable. Stop rather than hammering it;
				// the next sweep will pick these up.
				$reachable = false;

				Log::warning(
					'Reconciliation stopped early: the provider is unreachable.',
					array( 'code' => $found->get_error_code() )
				);

				break;
			}

			$asked[ (int) $row['id'] ] = true;

			if ( null === $found ) {
				continue;
			}

			$settled = Settlement::settle( $found->id, (string) $row['public_ref'], 'reconcile' );

			if ( $settled && Status::PAID === (string) $settled['status'] ) {
				++$recovered;

				Log::warning(
					'Recovered a payment that neither the browser nor a webhook reported.',
					array(
						'ref'  => (string) $row['public_ref'],
						'user' => (int) $row['user_id'],
					)
				);
			}
		}

		return $recovered;
	}
}
