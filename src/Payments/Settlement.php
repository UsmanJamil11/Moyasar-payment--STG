<?php
/**
 * Turning a payment into an entitlement, exactly once.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar\Payments;

use STG\Core\Subscriptions\Repository as Subscription_Repository;
use STG\Core\Subscriptions\Service as Subscription_Service;
use STG\Moyasar\Api\Payment_Result;
use STG\Moyasar\Mode;
use STG\Moyasar\Plugin;
use STG\Moyasar\Support\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one place a Moyasar payment can start a subscription.
 *
 * Four things can tell this class to look at a payment: the customer's browser
 * coming back from the hosted form, a webhook, an administrator pressing
 * "re-check", and the reconciliation cron. **None of them is believed.** Every
 * one of them is treated as a hint that says "go and look at payment X", and
 * the only fact in the system is what `GET /payments/{id}` returns over TLS
 * with the secret key.
 *
 * Framing it that way is what makes the rest tractable:
 *
 * - The webhook's shared secret, which is a bearer token sitting in a request
 *   body with no signature and no replay window, stops being load-bearing. A
 *   forged webhook can at worst ask STG to re-check a payment it already knows
 *   about, which is harmless.
 * - The callback and the webhook racing stops being a special case. They run
 *   the same function and the compare-and-set decides which one does the work.
 * - Local development stops being blocked. Moyasar cannot reach
 *   `new-stg.local`, and it does not need to: the browser callback and the
 *   reconciler both work without it.
 *
 * ## The mutex
 *
 * `Repository::claim_paid()` is a single UPDATE whose WHERE clause contains the
 * status the caller believes the row is in. Exactly one concurrent caller can
 * affect one row; everybody else affects zero and must re-read instead of
 * acting. `Subscription_Service::start()` is called from inside that winning
 * branch and nowhere else in this plugin, which `grep -rn "Service::start"`
 * will confirm.
 *
 * ## What never happens automatically
 *
 * Money is never returned, reversed or re-charged by this code. When an
 * invariant breaks after a charge has succeeded - a mismatched amount, a
 * payment that belongs to no intent, an activation that fails - the row is
 * marked `flagged`, a critical line is logged, and a person decides. An
 * automated refund is a second money movement triggered by the same logic that
 * just proved it could be wrong.
 */
final class Settlement {

	/**
	 * Error code of the last settle() that could not ask the provider, or ''.
	 *
	 * @var string
	 */
	private static string $last_failure = '';

	/**
	 * Whether the last settle() failed because the provider did not answer.
	 *
	 * A timeout, a 5xx and a 429 pass; asking again later gets an answer. A
	 * 404 or a rejected key does not, so those are not counted: a retry
	 * changes nothing until somebody changes the configuration.
	 *
	 * @return bool
	 */
	public static function provider_unavailable(): bool {
		return 'stg_moyasar_unreachable' === self::$last_failure
			|| 'stg_moyasar_http_429' === self::$last_failure
			|| 1 === preg_match( '/^stg_moyasar_http_5\d\d$/', self::$last_failure );
	}

	/**
	 * Whether the last settle() failed because this site's own key was refused
	 * or is not set.
	 *
	 * Nothing was learned about the payment, and nothing will be until somebody
	 * corrects the key - which they may well do inside the hours the provider
	 * keeps retrying a webhook for.
	 *
	 * @return bool
	 */
	public static function provider_refused_credentials(): bool {
		return in_array( self::$last_failure, array( 'stg_moyasar_unauthorised', 'stg_moyasar_not_configured' ), true );
	}

	/**
	 * Look at a payment and bring STG's records into line with it.
	 *
	 * @param string $provider_payment_id The provider's payment id.
	 * @param string $reference           STG public reference, if the channel knew one.
	 * @param string $origin              'callback', 'webhook', 'admin' or 'reconcile'.
	 * @return array<string, mixed>|null The payment row as it now stands, or null if nothing could be resolved.
	 */
	public static function settle( string $provider_payment_id, string $reference = '', string $origin = 'callback' ): ?array {
		$plugin   = Plugin::instance();
		$provider = $plugin->provider();
		$mode     = $provider->mode();

		self::$last_failure = '';

		$result = $provider->fetch( $provider_payment_id );

		if ( is_wp_error( $result ) ) {
			self::$last_failure = (string) $result->get_error_code();

			/*
			 * A 404 here is meaningful rather than merely unfortunate: it is
			 * what asking the test API about a live payment looks like, and
			 * vice versa. The response is to stop, not to try the other mode's
			 * key - a fallback would be the one thing mode separation exists to
			 * prevent.
			 */
			Log::warning(
				'Could not verify a payment with the provider.',
				array(
					'origin' => $origin,
					'mode'   => $mode,
					'code'   => $result->get_error_code(),
				)
			);

			return $reference ? Repository::find_by_reference( $reference ) : null;
		}

		return self::apply( $result, $reference, $origin, $mode );
	}

	/**
	 * Apply a verified provider result to STG's records.
	 *
	 * @param Payment_Result $result    What the provider says.
	 * @param string         $reference STG reference the channel supplied, if any.
	 * @param string         $origin    Which channel asked.
	 * @param string         $mode      Active mode.
	 * @return array<string, mixed>|null
	 */
	private static function apply( Payment_Result $result, string $reference, string $origin, string $mode ): ?array {
		$context = array(
			'origin'     => $origin,
			'mode'       => $mode,
			'payment_id' => $result->id,
			'status'     => $result->status,
		);

		/*
		 * Resolve which intent this payment belongs to, most trustworthy first.
		 *
		 * The metadata the provider echoes back beats the reference the channel
		 * handed us, because the channel's copy came from a URL or a request
		 * body that anybody could have written, while the metadata came back
		 * from the provider over an authenticated connection.
		 */
		$row = null;

		if ( '' !== $result->stg_reference() ) {
			$row = Repository::find_by_reference( $result->stg_reference() );
		}

		if ( ! $row ) {
			$row = Repository::find_by_provider_id( $result->id, $mode );
		}

		if ( ! $row && '' !== $reference ) {
			$row = Repository::find_by_reference( $reference );
		}

		if ( ! $row ) {
			return self::unattributable( $result, $context );
		}

		$context['ref'] = (string) $row['public_ref'];

		Repository::note_channel( (int) $row['id'], $origin );

		// A row minted in one world is never settled by another.
		if ( (string) $row['mode'] !== $mode ) {
			Log::critical( 'A payment was reported in a different mode from the one it was created in.', $context );

			return $row;
		}

		/*
		 * The provider's own view of which world it is in must agree with ours.
		 *
		 * This catches a webhook endpoint configured against the wrong
		 * environment in the Moyasar dashboard - a real and easy mistake, and
		 * one whose symptom would otherwise be sandbox events granting live
		 * plans.
		 *
		 * Only when the provider says. A payment object from the API does not
		 * carry the flag, and it does not need to: the key that fetched it
		 * belongs to one environment and can see no payment from the other.
		 */
		if ( $result->live_known && ! Mode::agrees_with_live_flag( $mode, $result->live ) ) {
			Log::critical(
				'The provider says this payment belongs to a different environment. Nothing was granted.',
				array_merge( $context, array( 'provider_says' => Mode::from_live_flag( $result->live ) ) )
			);

			return $row;
		}

		// Already settled. Whichever channel got here first did the work.
		if ( Status::PAID === (string) $row['status'] ) {
			/*
			 * Only the payment that bought the plan can take it away.
			 *
			 * The hosted form makes a new payment on every submission, all
			 * carrying this checkout's reference. A customer charged twice is
			 * refunded the second charge - and that refund arrived here, found
			 * this row by reference, and ended the plan the first charge paid
			 * for. A report about any other payment is a different matter from
			 * a report about this one.
			 */
			$bound = (string) ( $row['provider_payment_id'] ?? '' );

			if ( '' !== $bound && $result->id !== $bound ) {
				return self::second_payment( $row, $result, $context );
			}

			return self::maybe_reverse( $row, $result, $context );
		}

		if ( in_array( (string) $row['status'], Status::terminal(), true ) ) {
			/*
			 * Closed here, paid there.
			 *
			 * A card declined once and accepted on the second try; a bank page
			 * that took longer than the checkout stayed open. The customer has
			 * been charged for a checkout STG had already written off, and
			 * returning quietly left them charged, shown "declined", and
			 * nobody told.
			 */
			if ( Status::entitles( $result->status ) && in_array( (string) $row['status'], array( Status::FAILED, Status::EXPIRED ), true ) ) {
				return self::paid_after_close( $row, $result, $context );
			}

			return $row;
		}

		$mapped = Status::from_provider( $result->status );

		if ( '' === $mapped ) {
			Log::warning( 'The provider returned a status this integration does not recognise.', $context );
			self::flag( $row, 'unknown_provider_status' );

			return Repository::find( (int) $row['id'] );
		}

		// Still in flight. Leave the intent open so the customer can finish.
		if ( Status::PENDING === $mapped ) {
			return $row;
		}

		if ( Status::PAID !== $mapped ) {
			return self::record_non_payment( $row, $result, $mapped, $context );
		}

		return self::activate( $row, $result, $context );
	}

	/**
	 * A successful payment that matches an intent. The only path that entitles.
	 *
	 * @param array<string, mixed> $row     Payment row.
	 * @param Payment_Result       $result  Provider result.
	 * @param array<string, mixed> $context Log context.
	 * @return array<string, mixed>|null
	 */
	private static function activate( array $row, Payment_Result $result, array $context ): ?array {
		$id = (int) $row['id'];

		/*
		 * The amount is compared, never accepted.
		 *
		 * The server decided this figure when the intent was created, from the
		 * plan, with no input from the browser. If what actually got charged
		 * differs by a single minor unit, something between here and Moyasar
		 * changed the price - and the right response is to stop and tell
		 * somebody, not to reconcile the difference silently in the customer's
		 * favour or ours.
		 */
		if ( $result->amount !== (int) $row['amount'] || $result->currency !== strtoupper( (string) $row['currency'] ) ) {
			Log::critical(
				'A payment succeeded for a different amount or currency than STG asked for. No plan has been granted.',
				array_merge(
					$context,
					array(
						'expected_amount'   => (int) $row['amount'],
						'charged_amount'    => $result->amount,
						'expected_currency' => (string) $row['currency'],
						'charged_currency'  => $result->currency,
					)
				)
			);

			/*
			 * Keep the provider's id with the row. Staff are told to "check
			 * the charge in the Moyasar dashboard", and a flagged row with no
			 * id gave them nothing to look it up by (seen in a sandbox run,
			 * 2026-10-11). Not when another row already holds it, which the
			 * unique index forbids.
			 */
			$extra = array(
				'flag_reason'     => 'amount_mismatch',
				'provider_status' => $result->status,
			);

			if ( ! Repository::find_by_provider_id( $result->id, (string) $row['mode'] ) ) {
				$extra['provider_payment_id'] = $result->id;
			}

			if ( Repository::transition( $id, (string) $row['status'], Status::FLAGGED, $extra ) ) {
				self::announce( $id, 'amount_mismatch' );
			}

			return Repository::find( $id );
		}

		/*
		 * Is this Moyasar payment already bound to a different intent?
		 *
		 * The hosted form creates a NEW payment on every submission, so a
		 * customer who submits twice and succeeds twice has genuinely been
		 * charged twice. The unique index would refuse the second binding as a
		 * database error; checking first turns that into a flagged row a human
		 * can act on.
		 */
		$existing = Repository::find_by_provider_id( $result->id, (string) $row['mode'] );

		if ( $existing && (int) $existing['id'] !== $id ) {
			Log::critical(
				'This provider payment is already recorded against a different STG payment. A customer may have been charged twice.',
				array_merge( $context, array( 'bound_to' => (string) $existing['public_ref'] ) )
			);

			self::flag( $row, 'duplicate_provider_payment' );

			return Repository::find( $id );
		}

		// The mutex. Exactly one caller leaves this branch as the winner.
		if ( ! Repository::claim_paid( $id, (string) $row['mode'], $result->id, $result->status, $result->source_type ) ) {
			Log::debug( 'Another process settled this payment first.', $context );

			return Repository::find( $id );
		}

		Log::info( 'Payment verified and claimed.', $context );

		/*
		 * Carry over any time already paid for.
		 *
		 * A user renewing three days early should get their new month on top of
		 * those three days, not instead of them. `Service::start()` takes the
		 * timestamp the new period runs from; passing the current period's end
		 * is what stops a renewal quietly shortening a subscription.
		 */
		$carry_from = null;
		$live       = Subscription_Repository::live_for_user( (int) $row['user_id'] );

		if ( $live && ! empty( $live['current_period_end'] ) ) {
			$ends = strtotime( (string) $live['current_period_end'] . ' UTC' );

			if ( $ends && $ends > time() ) {
				$carry_from = $ends;
			}
		}

		$subscription = Subscription_Service::start(
			(int) $row['user_id'],
			(string) $row['plan_slug'],
			// 'moyasar_test' in test, 'moyasar_fake' under the harness. A
			// permanent, queryable marker of which world paid for this plan.
			Mode::gateway_id( (string) $row['mode'] ),
			$result->id,
			max( 1, (int) $row['period_days'] ),
			$carry_from
		);

		if ( is_wp_error( $subscription ) ) {
			/*
			 * Money moved and the plan did not start.
			 *
			 * The row deliberately stays `paid` rather than being rolled back.
			 * Reverting it would lose the only record that a charge succeeded,
			 * and the next channel to arrive would try to charge-activate all
			 * over again. Flag it, shout, and let a person sort it out.
			 */
			Log::critical(
				'A payment succeeded but the subscription could not be started. This needs a person.',
				array_merge( $context, array( 'error' => $subscription->get_error_code() ) )
			);

			Repository::transition( (int) $row['id'], Status::PAID, Status::PAID, array( 'flag_reason' => 'activation_failed' ) );
			self::announce( $id, 'activation_failed' );

			return Repository::find( $id );
		}

		if ( ! empty( $subscription['id'] ) ) {
			Repository::bind_subscription( $id, (int) $subscription['id'] );
		}

		/**
		 * Fires once, after a payment has been verified and the plan granted.
		 *
		 * The receipt email hangs off this rather than off the webhook handler,
		 * so a slow mail server cannot make Moyasar think delivery failed.
		 *
		 * @param int                  $payment_id   Internal payment id.
		 * @param int                  $user_id      User.
		 * @param array<string, mixed> $subscription The new subscription row.
		 */
		do_action( 'stg_moyasar_payment_settled', $id, (int) $row['user_id'], $subscription );

		return Repository::find( $id );
	}

	/**
	 * A payment that did not succeed, or succeeded into a state that grants nothing.
	 *
	 * @param array<string, mixed> $row     Payment row.
	 * @param Payment_Result       $result  Provider result.
	 * @param string               $mapped  Mapped STG status.
	 * @param array<string, mixed> $context Log context.
	 * @return array<string, mixed>|null
	 */
	private static function record_non_payment( array $row, Payment_Result $result, string $mapped, array $context ): ?array {
		$id = (int) $row['id'];

		if ( Status::FLAGGED === $mapped ) {
			// `authorized` and `verified`: states that look like success and
			// are not. See Status for why neither entitles anything.
			Log::warning(
				'The provider reported a state this integration does not use. No plan has been granted.',
				$context
			);

			self::flag( $row, Status::flag_reason_for( $result->status ) );

			return Repository::find( $id );
		}

		Repository::transition(
			$id,
			Status::PENDING,
			$mapped,
			array( 'provider_status' => $result->status )
		);

		if ( Status::FAILED === $mapped ) {
			Repository::note_failure( $id, $result->failure_code, $result->failure_message );
		}

		Log::info( 'Payment did not complete.', $context );

		return Repository::find( $id );
	}

	/**
	 * A payment already marked paid, reported again with a different outcome.
	 *
	 * The refund and void path. STG's records must tell the truth about money
	 * that has been returned, and a plan that was paid for with money now back
	 * in the customer's account should not keep running.
	 *
	 * **Only a FULL refund ends the subscription.** Moyasar supports partial
	 * refunds, and revoking somebody's plan because they were given back a
	 * tenth of what they paid would be worse than doing nothing. A partial
	 * refund is flagged for a person instead.
	 *
	 * @param array<string, mixed> $row     Payment row.
	 * @param Payment_Result       $result  Provider result.
	 * @param array<string, mixed> $context Log context.
	 * @return array<string, mixed>|null
	 */
	private static function maybe_reverse( array $row, Payment_Result $result, array $context ): ?array {
		$id     = (int) $row['id'];
		$mapped = Status::from_provider( $result->status );

		if ( ! in_array( $mapped, array( Status::REFUNDED, Status::VOIDED ), true ) ) {
			return $row;
		}

		if ( Status::REFUNDED === $mapped && ! $result->fully_refunded() ) {
			Log::warning( 'A payment was partly refunded. The plan has been left running for a person to decide.', $context );
			self::flag( $row, 'partial_refund' );

			return Repository::find( $id );
		}

		Repository::transition(
			$id,
			Status::PAID,
			$mapped,
			array(
				'provider_status' => $result->status,
				'refunded_at'     => current_time( 'mysql', true ),
			)
		);

		$subscription_id = (int) ( $row['subscription_id'] ?? 0 );

		if ( $subscription_id > 0 ) {
			$subscription = Subscription_Repository::find( $subscription_id );

			if ( $subscription && in_array( (string) $subscription['status'], Subscription_Repository::live_statuses(), true ) ) {
				Subscription_Repository::transition(
					$subscription_id,
					(string) $subscription['status'],
					Subscription_Repository::EXPIRED
				);

				Log::warning(
					'Money was returned, so the plan it paid for has been ended.',
					array_merge( $context, array( 'subscription' => $subscription_id ) )
				);
			}
		}

		return Repository::find( $id );
	}

	/**
	 * A successful payment for a checkout STG had already closed.
	 *
	 * Flagged, not activated - the rule of this class. The checkout failed or
	 * timed out for a reason, and granting a plan against a row in that state
	 * is a decision; staff can give the plan by hand (STG, Subscribers) or
	 * refund the charge at the provider.
	 *
	 * @param array<string, mixed> $row     Payment row.
	 * @param Payment_Result       $result  Provider result.
	 * @param array<string, mixed> $context Log context.
	 * @return array<string, mixed>|null
	 */
	private static function paid_after_close( array $row, Payment_Result $result, array $context ): ?array {
		$id = (int) $row['id'];

		Log::critical(
			'A payment succeeded for a checkout that had already failed or expired. The customer has been charged and no plan was granted. This needs a person.',
			array_merge( $context, array( 'was' => (string) $row['status'] ) )
		);

		$extra = array(
			'flag_reason'     => 'paid_after_close',
			'provider_status' => $result->status,
		);

		// Keep the provider's id with the row so staff can find the charge -
		// unless another row already holds it, which the unique index forbids.
		if ( ! Repository::find_by_provider_id( $result->id, (string) $row['mode'] ) ) {
			$extra['provider_payment_id'] = $result->id;
		}

		if ( Repository::transition( $id, (string) $row['status'], Status::FLAGGED, $extra ) ) {
			self::announce( $id, 'paid_after_close' );
		}

		return Repository::find( $id );
	}

	/**
	 * A report about a different payment from the one that paid for this row.
	 *
	 * The row, and the plan it bought, are left exactly as they are. If the
	 * other payment succeeded the customer has been charged twice, and that
	 * is recorded as its own flagged row so somebody refunds it.
	 *
	 * @param array<string, mixed> $row     The paid row.
	 * @param Payment_Result       $result  Provider result for the other payment.
	 * @param array<string, mixed> $context Log context.
	 * @return array<string, mixed>|null
	 */
	private static function second_payment( array $row, Payment_Result $result, array $context ): ?array {
		$mode     = (string) $row['mode'];
		$recorded = Repository::find_by_provider_id( $result->id, $mode );

		if ( ! Status::entitles( $result->status ) ) {
			// Failed, or since refunded. Keep the duplicate's own record
			// truthful; the plan is not its business.
			if ( $recorded && (int) $recorded['id'] !== (int) $row['id'] ) {
				Repository::transition( (int) $recorded['id'], (string) $recorded['status'], (string) $recorded['status'], array( 'provider_status' => $result->status ) );
			}

			Log::debug( 'A report about another payment for a checkout that is already paid. The plan is untouched.', $context );

			return $row;
		}

		if ( $recorded ) {
			return $row;
		}

		Log::critical(
			'A second successful payment arrived for a checkout that is already paid. The customer has probably been charged twice.',
			array_merge( $context, array( 'paid_by' => (string) $row['provider_payment_id'] ) )
		);

		$orphan = Repository::insert_orphan(
			array(
				'user_id'             => (int) $row['user_id'],
				'plan_slug'           => (string) $row['plan_slug'],
				'provider_payment_id' => $result->id,
				'provider_status'     => $result->status,
				'amount'              => $result->amount,
				'currency'            => $result->currency,
				'mode'                => $mode,
				'flag_reason'         => 'duplicate_payment',
			)
		);

		if ( $orphan > 0 ) {
			self::announce( $orphan, 'duplicate_payment' );
		}

		return $row;
	}

	/**
	 * A paid payment that belongs to no intent we know about.
	 *
	 * Recorded rather than discarded. Money arriving with nothing to attach it
	 * to is precisely the case that must not vanish, because the customer has
	 * been charged and nobody would otherwise find out.
	 *
	 * @param Payment_Result       $result  Provider result.
	 * @param array<string, mixed> $context Log context.
	 * @return array<string, mixed>|null
	 */
	private static function unattributable( Payment_Result $result, array $context ): ?array {
		if ( ! Status::entitles( $result->status ) ) {
			// An unattributable failure is noise, not money. Nothing to record.
			Log::debug( 'Ignoring an unattributable payment that did not succeed.', $context );

			return null;
		}

		$existing = Repository::find_by_provider_id( $result->id, (string) $context['mode'] );

		if ( $existing ) {
			return $existing;
		}

		Log::critical(
			'A successful payment could not be matched to any STG checkout. Somebody may have been charged for nothing.',
			$context
		);

		$id = Repository::insert_orphan(
			array(
				'provider_payment_id' => $result->id,
				'provider_status'     => $result->status,
				'amount'              => $result->amount,
				'currency'            => $result->currency,
				'mode'                => (string) $context['mode'],
				'flag_reason'         => 'unattributable',
			)
		);

		if ( $id > 0 ) {
			self::announce( $id, 'unattributable' );
		}

		return $id > 0 ? Repository::find( $id ) : null;
	}

	/**
	 * Mark a row as needing a person, preserving whatever status it holds.
	 *
	 * @param array<string, mixed> $row    Payment row.
	 * @param string               $reason Machine-readable reason.
	 * @return void
	 */
	private static function flag( array $row, string $reason ): void {
		$flagged = Repository::transition(
			(int) $row['id'],
			(string) $row['status'],
			Status::FLAGGED,
			array( 'flag_reason' => $reason )
		);

		if ( $flagged ) {
			self::announce( (int) $row['id'], $reason );
		}
	}

	/**
	 * Say that a payment now needs a person.
	 *
	 * The customer is told on screen that "our team has been notified". This
	 * is the notifying: Mailer hangs the staff email off it. Called once per
	 * flag, from the branch that changed the row.
	 *
	 * @param int    $payment_id Internal payment id.
	 * @param string $reason     Flag reason.
	 * @return void
	 */
	private static function announce( int $payment_id, string $reason ): void {
		/**
		 * Fires when a payment is stopped for a person to look at.
		 *
		 * @param int    $payment_id Internal payment id.
		 * @param string $reason     Machine-readable reason.
		 */
		do_action( 'stg_moyasar_payment_flagged', $payment_id, $reason );
	}
}
