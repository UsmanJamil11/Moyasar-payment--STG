<?php
/**
 * What the customer sees after paying.
 *
 * The verification already happened, server-side, in
 * `Frontend\Screens::settle_return()`. This file renders the result of that and
 * makes no decision of its own - in particular it never reads the `status`
 * query parameter Moyasar appends, because a value in a URL is a claim, not a
 * fact about money.
 *
 * Six states, and the distinctions between them are the point: a customer who
 * was declined, a customer who changed their mind, and a customer whose payment
 * is simply still confirming must not all be shown the same thing.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

use STG\Core\Subscriptions\Plans;
use STG\Moyasar\Mode;
use STG\Moyasar\Payments\Intents;
use STG\Moyasar\Payments\Repository;
use STG\Moyasar\Payments\Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$stg_reference = (string) get_query_var( \STG\Core\Modules\Routing::SLUG_VAR, '' );
$stg_row       = Repository::find_by_reference( $stg_reference );

if ( ! $stg_row ) {
	return;
}

$stg_status = (string) $stg_row['status'];
$stg_plan   = Plans::find( (string) $stg_row['plan_slug'] );
$stg_name   = $stg_plan ? (string) $stg_plan['name'] : (string) $stg_row['plan_slug'];

/*
 * Did the customer arrive without a payment id?
 *
 * That is what backing out of the provider's page looks like. Nothing was
 * charged, the intent is still open, and telling them their payment "failed"
 * would be both wrong and alarming.
 */
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only to distinguish "came back without paying" from "was declined"; no state depends on it.
$stg_abandoned = Status::PENDING === $stg_status && empty( $_GET['id'] );

$stg_wrapper_class = 'stg-checkout stg-checkout--result stg-shell--narrow';

require STG_MOYASAR_DIR . 'templates/parts/open.php';
?>

		<?php if ( ! Mode::is_live( (string) $stg_row['mode'] ) ) : ?>
			<p class="stg-notice stg-notice--warning" role="status">
				<strong><?php esc_html_e( 'Test mode', 'stg-moyasar-payments' ); ?></strong>
				<?php esc_html_e( 'This was a test payment. No money was taken.', 'stg-moyasar-payments' ); ?>
			</p>
		<?php endif; ?>

		<?php if ( Status::PAID === $stg_status ) : ?>

			<div class="stg-result stg-result--success">
				<h1><?php esc_html_e( 'You are subscribed', 'stg-moyasar-payments' ); ?></h1>
				<p>
					<?php
					printf(
						/* translators: %s: plan name */
						esc_html__( 'Your payment went through and your %s plan is active.', 'stg-moyasar-payments' ),
						'<strong>' . esc_html( $stg_name ) . '</strong>'
					);
					?>
				</p>
				<p>
					<?php esc_html_e( 'We have emailed you a confirmation. You can see your plan and renewal date in your account settings at any time.', 'stg-moyasar-payments' ); ?>
				</p>
				<p class="stg-result__actions">
					<a class="stg-btn stg-btn--primary" href="<?php echo esc_url( home_url( '/dashboard/' ) ); ?>">
						<?php esc_html_e( 'Go to your dashboard', 'stg-moyasar-payments' ); ?>
					</a>
				</p>
			</div>

		<?php elseif ( $stg_abandoned ) : ?>

			<div class="stg-result stg-result--neutral">
				<h1><?php esc_html_e( 'Payment not completed', 'stg-moyasar-payments' ); ?></h1>
				<p><?php esc_html_e( 'You have not been charged. Your plan has not changed.', 'stg-moyasar-payments' ); ?></p>
				<p class="stg-result__actions">
					<a class="stg-btn stg-btn--primary" href="<?php echo esc_url( Intents::checkout_url( $stg_reference ) ); ?>">
						<?php esc_html_e( 'Try again', 'stg-moyasar-payments' ); ?>
					</a>
					<a class="stg-btn stg-btn--ghost" href="<?php echo esc_url( home_url( '/pricing/' ) ); ?>">
						<?php esc_html_e( 'Choose a different plan', 'stg-moyasar-payments' ); ?>
					</a>
				</p>
			</div>

		<?php elseif ( Status::PENDING === $stg_status ) : ?>

			<?php
			/*
			 * Confirming, not failed.
			 *
			 * A 3-D Secure challenge that is still in flight, or a provider we
			 * could not reach in time, both land here. Telling somebody their
			 * payment failed when it is about to succeed produces a second
			 * payment attempt, which produces a double charge. The page polls
			 * and says nothing it cannot support.
			 */
			?>
			<div class="stg-result stg-result--pending"
				data-stg-poll="<?php echo esc_attr( $stg_reference ); ?>"
				data-stg-endpoint="<?php echo esc_url( rest_url( 'stg/v1/me/payments/' . $stg_reference ) ); ?>"
				data-stg-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
				<h1><?php esc_html_e( 'Confirming your payment', 'stg-moyasar-payments' ); ?></h1>
				<p role="status" aria-live="polite">
					<?php esc_html_e( 'This usually takes a few seconds. Please do not close this page or pay again.', 'stg-moyasar-payments' ); ?>
				</p>
				<p class="stg-result__slow" hidden>
					<?php esc_html_e( 'This is taking longer than usual. You can safely leave this page — we will email you as soon as it is confirmed, and you will not be charged twice.', 'stg-moyasar-payments' ); ?>
				</p>
			</div>

		<?php elseif ( Status::FAILED === $stg_status ) : ?>

			<div class="stg-result stg-result--error">
				<h1><?php esc_html_e( 'That payment was declined', 'stg-moyasar-payments' ); ?></h1>
				<?php
				/*
				 * The provider's own message is deliberately not printed.
				 *
				 * It is English-only, it is written for an integrator rather
				 * than a customer, and it sometimes names internals. It is kept
				 * on the record for support to read; the customer gets a
				 * translated sentence.
				 */
				?>
				<p><?php esc_html_e( 'Your bank did not approve the payment. You have not been charged.', 'stg-moyasar-payments' ); ?></p>
				<p><?php esc_html_e( 'This is usually a card limit, an expired card, or a block on online payments. Trying a different card often works.', 'stg-moyasar-payments' ); ?></p>
				<p class="stg-result__actions">
					<a class="stg-btn stg-btn--primary" href="<?php echo esc_url( home_url( '/pricing/' ) ); ?>">
						<?php esc_html_e( 'Try again', 'stg-moyasar-payments' ); ?>
					</a>
				</p>
			</div>

		<?php elseif ( Status::EXPIRED === $stg_status ) : ?>

			<div class="stg-result stg-result--neutral">
				<h1><?php esc_html_e( 'This checkout has expired', 'stg-moyasar-payments' ); ?></h1>
				<p><?php esc_html_e( 'You have not been charged. Start again and it will only take a moment.', 'stg-moyasar-payments' ); ?></p>
				<p class="stg-result__actions">
					<a class="stg-btn stg-btn--primary" href="<?php echo esc_url( home_url( '/pricing/' ) ); ?>">
						<?php esc_html_e( 'Choose a plan', 'stg-moyasar-payments' ); ?>
					</a>
				</p>
			</div>

		<?php else : ?>

			<?php
			/*
			 * Flagged, refunded or voided.
			 *
			 * The internal reason is never shown. "Your payment is being
			 * checked" is true for all of them, does not accuse the customer of
			 * anything, and does not leak why an invariant broke.
			 */
			?>
			<div class="stg-result stg-result--neutral">
				<h1><?php esc_html_e( 'We are checking this payment', 'stg-moyasar-payments' ); ?></h1>
				<p><?php esc_html_e( 'Something about this payment needs a person to look at it, and our team has been notified. You do not need to do anything, and please do not pay again.', 'stg-moyasar-payments' ); ?></p>
				<p><?php esc_html_e( 'We will be in touch shortly. If you would like to reach us first, please get in contact and quote the reference below.', 'stg-moyasar-payments' ); ?></p>
				<p class="stg-result__reference">
					<?php esc_html_e( 'Reference:', 'stg-moyasar-payments' ); ?>
					<code><?php echo esc_html( substr( $stg_reference, 0, 12 ) ); ?></code>
				</p>
			</div>

		<?php endif; ?>

<?php
require STG_MOYASAR_DIR . 'templates/parts/close.php';
