<?php
/**
 * The emails the checkout screens promise.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar\Payments;

use STG\Core\Contact\Service as Contact;
use STG\Core\Notifications\Mail_Queue;
use STG\Core\Subscriptions\Plans;
use STG\Core\Support\Locale;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two messages, both already promised on screen and neither ever sent.
 *
 * The return page tells a customer "We have emailed you a confirmation", and,
 * when a payment is stopped for review, "our team has been notified". Until
 * this class nothing listened for either event: the customer had no receipt,
 * and a payment that needed a person waited for somebody to open the
 * Transactions screen by chance.
 *
 * Both go through Core's mail queue, never `wp_mail()`: settlement runs inside
 * the webhook request, and a mail server that takes thirty seconds to refuse
 * a connection must not make Moyasar think delivery of the webhook failed.
 */
final class Mailer {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'stg_moyasar_payment_settled', array( $this, 'confirm' ), 10, 3 );
		add_action( 'stg_moyasar_payment_flagged', array( $this, 'alert_staff' ), 10, 2 );
	}

	/**
	 * Tell the member their payment went through and what it bought.
	 *
	 * @param int                  $payment_id   Internal payment id.
	 * @param int                  $user_id      Member.
	 * @param array<string, mixed> $subscription The subscription the payment started.
	 * @return void
	 */
	public function confirm( $payment_id, $user_id, $subscription ): void {
		$row  = Repository::find( (int) $payment_id );
		$user = get_userdata( (int) $user_id );

		if ( ! $row || ! $user instanceof WP_User ) {
			return;
		}

		$until = is_array( $subscription ) && ! empty( $subscription['current_period_end'] )
			? (int) strtotime( (string) $subscription['current_period_end'] . ' UTC' )
			: 0;

		// In the member's language, whoever's request happened to settle it -
		// usually Moyasar's webhook, which has no language at all.
		list( $subject, $body ) = Locale::with_user(
			(int) $user->ID,
			static function () use ( $row, $user, $until ): array {
				$plan = Plans::find( (string) $row['plan_slug'] );
				$name = (string) ( $plan['name'] ?? $row['plan_slug'] );

				/* translators: %s: plan name */
				$subject = sprintf( __( 'Your %s plan is active', 'stg-moyasar-payments' ), $name );

				$lines = array(
					sprintf(
						/* translators: %s: member's first name */
						__( 'Hello %s,', 'stg-moyasar-payments' ),
						$user->first_name ? $user->first_name : __( 'there', 'stg-moyasar-payments' )
					),
					'',
					sprintf(
						/* translators: %s: plan name */
						__( 'Your payment went through and your %s plan is active.', 'stg-moyasar-payments' ),
						$name
					),
					'',
					sprintf(
						/* translators: 1: amount, 2: currency code */
						__( 'Amount paid: %1$s %2$s', 'stg-moyasar-payments' ),
						number_format_i18n( (int) $row['amount'] / 100, 2 ),
						strtoupper( (string) $row['currency'] )
					),
				);

				if ( $until > 0 ) {
					$lines[] = sprintf(
						/* translators: %s: date */
						__( 'Active until: %s', 'stg-moyasar-payments' ),
						wp_date( (string) get_option( 'date_format' ), $until )
					);
				}

				$lines[] = sprintf(
					/* translators: %s: short payment reference */
					__( 'Reference: %s', 'stg-moyasar-payments' ),
					substr( (string) $row['public_ref'], 0, 12 )
				);
				$lines[] = '';
				$lines[] = __( 'This was a one-off payment for one billing period. It does not renew by itself; you can buy another period from your account when this one ends.', 'stg-moyasar-payments' );
				$lines[] = '';
				$lines[] = __( 'Your plan and payment history:', 'stg-moyasar-payments' );
				$lines[] = home_url( '/account/billing/' );

				return array( $subject, implode( "\n", $lines ) );
			}
		);

		Mail_Queue::enqueue( (string) $user->user_email, (string) $subject, (string) $body, (int) $user->ID );
	}

	/**
	 * Tell staff a payment needs a person.
	 *
	 * Goes where contact-form messages go (STG, Settings, Contact), which is
	 * the one address on the site somebody is known to read. It names the
	 * payment by reference and links to it; the customer's details stay on the
	 * admin screen, behind a login, rather than in a mailbox.
	 *
	 * @param int    $payment_id Internal payment id.
	 * @param string $reason     Flag reason.
	 * @return void
	 */
	public function alert_staff( $payment_id, $reason ): void {
		$row = Repository::find( (int) $payment_id );
		$to  = Contact::recipient();

		if ( ! $row || ! is_email( $to ) ) {
			return;
		}

		$reference = (string) $row['public_ref'];
		$why       = Status::flag_explanation( (string) $reason );

		$subject = sprintf(
			/* translators: %s: short payment reference */
			__( 'A payment needs a person: %s', 'stg-moyasar-payments' ),
			substr( $reference, 0, 12 )
		);

		$lines = array(
			__( 'A payment on the site was stopped for review. Nothing has been refunded or reversed.', 'stg-moyasar-payments' ),
			'',
			'' !== $why ? $why : (string) $reason,
			'',
			sprintf(
				/* translators: 1: amount, 2: currency code */
				__( 'Amount: %1$s %2$s', 'stg-moyasar-payments' ),
				number_format_i18n( (int) $row['amount'] / 100, 2 ),
				strtoupper( (string) $row['currency'] )
			),
			'',
			__( 'Open the payment:', 'stg-moyasar-payments' ),
			add_query_arg(
				array(
					'page'    => 'stg-payments-transactions',
					'payment' => $reference,
				),
				admin_url( 'admin.php' )
			),
		);

		Mail_Queue::enqueue( $to, $subject, implode( "\n", $lines ) );
	}
}
