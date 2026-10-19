<?php
/**
 * The payment screen.
 *
 * Presentation only. Every decision that matters - whether this intent exists,
 * belongs to this user, is still open and is still in time - was made in
 * `Frontend\Screens::guard()` before this file was reached, and the amount
 * shown here is read from the stored intent rather than recalculated.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

use STG\Core\Subscriptions\Plans;
use STG\Moyasar\Mode;
use STG\Moyasar\Payments\Repository;
use STG\Moyasar\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$stg_reference = get_query_var( \STG\Core\Modules\Routing::SLUG_VAR, '' );
$stg_row       = Repository::find_by_reference( (string) $stg_reference );

if ( ! $stg_row ) {
	return;
}

$stg_plan     = Plans::find( (string) $stg_row['plan_slug'] );
$stg_mode     = (string) $stg_row['mode'];
$stg_is_test  = ! Mode::is_live( $stg_mode );
$stg_amount   = (int) $stg_row['amount'];
$stg_currency = (string) $stg_row['currency'];

$stg_wrapper_class = 'stg-checkout stg-shell--narrow';

require STG_MOYASAR_DIR . 'templates/parts/open.php';
?>

		<?php if ( $stg_is_test ) : ?>
			<?php
			/*
			 * The test banner is not decoration.
			 *
			 * A sandbox checkout that looks identical to a live one is exactly
			 * how a real customer ends up believing they have subscribed when
			 * no money moved. It says so, in their language, where they will
			 * see it.
			 */
			?>
			<p class="stg-notice stg-notice--warning" role="status">
				<strong><?php esc_html_e( 'Test mode', 'stg-moyasar-payments' ); ?></strong>
				<?php esc_html_e( 'This is a test payment. No money will be taken and no real card will be charged.', 'stg-moyasar-payments' ); ?>
			</p>
		<?php endif; ?>

		<h1 class="stg-checkout__title"><?php esc_html_e( 'Confirm your subscription', 'stg-moyasar-payments' ); ?></h1>

		<div class="stg-checkout__summary">
			<h2 class="stg-checkout__summary-title"><?php esc_html_e( 'What you are paying for', 'stg-moyasar-payments' ); ?></h2>

			<dl class="stg-checkout__lines">
				<div class="stg-checkout__line">
					<dt><?php esc_html_e( 'Plan', 'stg-moyasar-payments' ); ?></dt>
					<dd><?php echo esc_html( $stg_plan ? (string) $stg_plan['name'] : (string) $stg_row['plan_slug'] ); ?></dd>
				</div>

				<div class="stg-checkout__line">
					<dt><?php esc_html_e( 'Billing period', 'stg-moyasar-payments' ); ?></dt>
					<dd>
						<?php
						echo esc_html(
							365 === (int) $stg_row['period_days']
								? __( 'One year', 'stg-moyasar-payments' )
								: __( 'One month', 'stg-moyasar-payments' )
						);
						?>
					</dd>
				</div>

				<div class="stg-checkout__line stg-checkout__line--total">
					<dt><?php esc_html_e( 'Total', 'stg-moyasar-payments' ); ?></dt>
					<dd>
						<?php
						/*
						 * Isolation (Money::html wraps the amount in `<bdi>`) matters here rather than being a nicety.
						 *
						 * "SAR 99" inside an Arabic, right-to-left paragraph is
						 * a mixed-direction run, and without isolation the
						 * currency code and the digits can be reordered on
						 * screen into something that is not the price. The rest
						 * of STG already does this; the checkout is the last
						 * place to get it wrong.
						 */
						?>
						<?php echo \STG\Core\Support\Money::html( $stg_amount, $stg_currency ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Money. ?>
					</dd>
				</div>
			</dl>

			<?php
			/*
			 * Say plainly that this does not renew itself.
			 *
			 * Moyasar has no recurring-billing product, so a plan bought here
			 * runs for its period and stops. "Subscription" leads people to
			 * assume otherwise, and the honest moment to correct that is before
			 * they pay, not in a support email afterwards.
			 */
			?>
			<p class="stg-checkout__note">
				<?php esc_html_e( 'This is a one-off payment for a single billing period. It does not renew automatically — we will remind you before it ends.', 'stg-moyasar-payments' ); ?>
			</p>
		</div>

		<?php if ( Settings::is_operational() ) : ?>

			<div class="stg-checkout__form">
				<h2 class="stg-checkout__form-title"><?php esc_html_e( 'Payment details', 'stg-moyasar-payments' ); ?></h2>

				<?php
				/*
				 * Moyasar's script mounts its own form into this element, and
				 * the card details it collects are posted from the browser
				 * straight to Moyasar. They never reach this server, which is
				 * the entire reason STG stays out of PCI SAQ-D scope.
				 */
				?>
				<?php
				/*
				 * Two elements, and the split matters. The outer one is ours:
				 * the stylesheet, the busy state and the script all find it by
				 * its id. The inner one is Moyasar's, which renames whatever
				 * element it is given - handing it ours meant its own next
				 * lookup found nothing and no form was ever drawn.
				 */
				?>
				<div id="stg-moyasar-form">
					<div class="mysr-form"></div>
				</div>

				<p class="stg-checkout__fallback" id="stg-moyasar-fallback" hidden>
					<?php esc_html_e( 'The payment form could not be loaded. Please refresh the page, or contact us if it keeps happening.', 'stg-moyasar-payments' ); ?>
				</p>

				<p class="stg-checkout__secure">
					<?php esc_html_e( 'Card details are handled by Moyasar, our payment provider. STG never sees or stores your card number.', 'stg-moyasar-payments' ); ?>
				</p>
			</div>

		<?php else : ?>

			<p class="stg-notice stg-notice--error" role="alert">
				<?php esc_html_e( 'Online payment is not available at the moment. Please contact us and we will arrange your plan.', 'stg-moyasar-payments' ); ?>
			</p>
			<?php // The way to do that, with the plan already chosen (D-079). ?>
			<a class="stg-btn stg-btn--primary stg-btn--block" href="<?php echo esc_url( add_query_arg( array( 'topic' => 'plans', 'plan' => (string) $stg_row['plan_slug'] ), home_url( '/contact/' ) ) ); ?>">
				<?php esc_html_e( 'Get in touch', 'stg-moyasar-payments' ); ?>
			</a>

		<?php endif; ?>

		<p class="stg-checkout__back">
			<a href="<?php echo esc_url( home_url( '/pricing/' ) ); ?>">
				<?php esc_html_e( 'Choose a different plan', 'stg-moyasar-payments' ); ?>
			</a>
		</p>

<?php
require STG_MOYASAR_DIR . 'templates/parts/close.php';
