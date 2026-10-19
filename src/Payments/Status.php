<?php
/**
 * Payment states, and how Moyasar's map onto them.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar\Payments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The STG payment vocabulary and the provider mapping.
 *
 * Seven states, deliberately fewer than Moyasar has. There is no `processing`:
 * a 3-D Secure challenge in flight leaves the row `pending`, so the open-intent
 * constraint still covers it and a user who retries reuses the same intent
 * rather than creating a second one. Fewer states means fewer transitions,
 * which means fewer ways to be wrong about money.
 *
 * **The mapping is not one-to-one and the differences are the point.** Three
 * Moyasar statuses look like success and are not:
 *
 * - `authorized` means funds are held, not taken. It is only reachable if
 *   `manual: true` was sent, which this integration never sends, so receiving
 *   it means something is misconfigured. An authorization can expire
 *   uncaptured; activating on one gives a plan away for nothing.
 * - `verified` is a zero-amount card check produced by `save_card`, which this
 *   integration also never sends. It is not a purchase.
 * - `initiated` means the challenge has not been completed yet. It is the
 *   status a payment has while the customer is still typing.
 *
 * Only `paid` - and `captured`, which is `paid` after a manual capture we never
 * ask for - grants anything.
 */
final class Status {

	/** An intent exists; nobody has paid yet. */
	public const PENDING = 'pending';

	/** Money moved and the invariants held. The only status that entitles. */
	public const PAID = 'paid';

	/** The provider declined it. */
	public const FAILED = 'failed';

	/** Nobody completed it before the intent's time ran out. */
	public const EXPIRED = 'expired';

	/** Money was returned. */
	public const REFUNDED = 'refunded';

	/** The charge was reversed before it settled. */
	public const VOIDED = 'voided';

	/**
	 * Money moved but an invariant broke. A person must look.
	 *
	 * This state is the one that is easy to leave out and expensive to lack.
	 * Moyasar's hosted form creates a NEW payment on every submission, so a
	 * customer who submits twice and succeeds twice produces two paid payments
	 * against one intent. Without somewhere to put the second one it is
	 * silently discarded and the customer has been charged twice with no record
	 * that anybody could find. With it, there is a row, a critical log line and
	 * an admin screen that says "needs attention".
	 *
	 * Nothing is ever automatically refunded or reversed from here. Returning
	 * money is a decision a person makes.
	 */
	public const FLAGGED = 'flagged';

	/**
	 * Every status.
	 *
	 * DUPLICATED in the database, inside the `open_flag` generated column's
	 * CASE expression. If `PENDING` ever stops being the only open state, the
	 * migration MUST change too - the same warning Migration_0012 carries about
	 * `live_statuses()`, for the same reason.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::PENDING,
			self::PAID,
			self::FAILED,
			self::EXPIRED,
			self::REFUNDED,
			self::VOIDED,
			self::FLAGGED,
		);
	}

	/**
	 * Statuses from which nothing further will happen on its own.
	 *
	 * @return string[]
	 */
	public static function terminal(): array {
		return array( self::PAID, self::FAILED, self::EXPIRED, self::REFUNDED, self::VOIDED, self::FLAGGED );
	}

	/**
	 * Human label.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function label( string $status ): string {
		$labels = array(
			self::PENDING  => __( 'Awaiting payment', 'stg-moyasar-payments' ),
			self::PAID     => __( 'Paid', 'stg-moyasar-payments' ),
			self::FAILED   => __( 'Declined', 'stg-moyasar-payments' ),
			self::EXPIRED  => __( 'Expired', 'stg-moyasar-payments' ),
			self::REFUNDED => __( 'Refunded', 'stg-moyasar-payments' ),
			self::VOIDED   => __( 'Voided', 'stg-moyasar-payments' ),
			self::FLAGGED  => __( 'Needs attention', 'stg-moyasar-payments' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Translate a Moyasar payment status into an STG one.
	 *
	 * Returns an empty string for a status this integration does not recognise,
	 * which the caller must treat as "leave the row alone and flag it" rather
	 * than as any kind of success.
	 *
	 * @param string $provider_status Moyasar's `status` field.
	 * @return string
	 */
	public static function from_provider( string $provider_status ): string {
		switch ( strtolower( trim( $provider_status ) ) ) {
			case 'paid':
			case 'captured':
				return self::PAID;

			case 'failed':
			// The provider's own "nobody finished this payment in time". No
			// money moved, so it is the same answer as a decline.
			case 'expired':
				return self::FAILED;

			case 'refunded':
				return self::REFUNDED;

			case 'voided':
				return self::VOIDED;

			case 'initiated':
				return self::PENDING;

			// Neither of these is a purchase; see the class comment.
			case 'authorized':
			case 'verified':
				return self::FLAGGED;

			default:
				return '';
		}
	}

	/**
	 * Whether a provider status should grant or extend a subscription.
	 *
	 * The single question the whole integration turns on, answered in one place
	 * so no caller can approximate it.
	 *
	 * @param string $provider_status Moyasar's `status` field.
	 * @return bool
	 */
	public static function entitles( string $provider_status ): bool {
		return self::PAID === self::from_provider( $provider_status );
	}

	/**
	 * Why a provider status was flagged, for the admin screen.
	 *
	 * @param string $provider_status Moyasar's `status` field.
	 * @return string
	 */
	public static function flag_reason_for( string $provider_status ): string {
		switch ( strtolower( trim( $provider_status ) ) ) {
			case 'authorized':
				return 'unexpected_authorization';
			case 'verified':
				return 'unexpected_verification';
			default:
				return 'unknown_provider_status';
		}
	}

	/**
	 * What a flag means and what to do about it, for the admin screen.
	 *
	 * The detail screen showed the bare code (`paid_after_close`) under a
	 * notice saying a person was needed, and left that person to work out
	 * what for. Empty for a reason this list does not know.
	 *
	 * @param string $reason The row's `flag_reason`.
	 * @return string
	 */
	public static function flag_explanation( string $reason ): string {
		$known = array(
			'paid_after_close'           => __( 'The customer was charged after this checkout had already failed or expired, so no plan was granted. Give them the plan in STG, Subscribers, or refund the charge in the Moyasar dashboard.', 'stg-moyasar-payments' ),
			'duplicate_payment'          => __( 'A second successful charge for a checkout that was already paid. The plan is in place from the first charge; refund this one in the Moyasar dashboard.', 'stg-moyasar-payments' ),
			'duplicate_provider_payment' => __( 'Moyasar reported a payment that is already recorded against another checkout. The customer may have been charged twice; compare the two in the Moyasar dashboard.', 'stg-moyasar-payments' ),
			'amount_mismatch'            => __( 'The amount or currency charged is not what this site asked for, so no plan was granted. Check the charge in the Moyasar dashboard before doing anything else.', 'stg-moyasar-payments' ),
			'unattributable'             => __( 'A successful charge that matches no checkout on this site. Find the customer from the charge in the Moyasar dashboard, then give the plan in STG, Subscribers, or refund it.', 'stg-moyasar-payments' ),
			'partial_refund'             => __( 'Part of the money was returned. The plan was left running; end it in STG, Subscribers, if it should stop.', 'stg-moyasar-payments' ),
			'activation_failed'          => __( 'The charge succeeded but the plan could not be started. Give the plan in STG, Subscribers.', 'stg-moyasar-payments' ),
			'unexpected_authorization'   => __( 'Moyasar holds an authorisation that was never captured, so no money has moved. Capture or void it in the Moyasar dashboard.', 'stg-moyasar-payments' ),
			'unexpected_verification'    => __( 'Moyasar verified the card without charging it. No money has moved and no plan was granted.', 'stg-moyasar-payments' ),
			'unknown_provider_status'    => __( 'Moyasar returned a status this site does not recognise. Check the charge in the Moyasar dashboard.', 'stg-moyasar-payments' ),
		);

		return $known[ $reason ] ?? '';
	}

	/**
	 * Every webhook event this integration knows about, and whether to act.
	 *
	 * **The event name never decides the outcome.** Every actionable event does
	 * exactly the same thing: go and ask the API what the payment's status
	 * really is. That collapses ten events into one code path, and it means a
	 * forged or replayed webhook can at most ask STG to re-check a payment it
	 * already knows about.
	 *
	 * It also makes the documentation's spelling problem a non-event. Moyasar's
	 * API reference lists `payment_failed`; the dashboard's webhook setup guide
	 * spells the same event `payment_faild`. There is no way to tell from the
	 * documentation which one actually arrives, so both are listed and both do
	 * the same thing. This is a deliberate tolerance, not a transcription error:
	 * do not "correct" either spelling away.
	 *
	 * @return array<string, bool> Event name => whether it triggers verification.
	 */
	public static function webhook_events(): array {
		return array(
			'payment_paid'            => true,
			'payment_failed'          => true,
			// Not a typo on our part. See above.
			'payment_faild'           => true,
			'payment_captured'        => true,
			'payment_refunded'        => true,
			'payment_voided'          => true,
			'payment_authorized'      => true,
			'payment_abandoned'       => true,
			'payment_verified'        => true,
			// Listed by GET /webhooks/available_events (checked 2026-10-11).
			'payment_canceled'        => true,
			'payment_expired'         => true,

			/*
			 * Steps in a standalone 3-D Secure flow, which is enabled only for
			 * selected merchants and which STG does not use. Acknowledged so
			 * Moyasar stops retrying, then ignored.
			 */
			'card_auth_authenticated' => false,
			'card_auth_failed'        => false,
		);
	}
}
