<?php
/**
 * The plan cards.
 *
 * Rendered by both `/pricing/` and the `[stg_pricing]` shortcode, from this
 * one file on purpose: a shortcode that drifted away from the page it mirrors
 * is how a site ends up quoting two different prices depending on which URL
 * the customer arrived through.
 *
 * Expects `$show_heading` (bool) from the caller; defaults to true.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

use STG\Core\Subscriptions\Plans;
use STG\Core\Subscriptions\Repository as Subscription_Repository;
use STG\Core\Subscriptions\Service as Subscription_Service;
use STG\Moyasar\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$stg_show_heading = isset( $show_heading ) ? (bool) $show_heading : true;
// Wording from the Elementor widget (empty = the standard, translated text).
$stg_heading_title = isset( $heading_title ) && '' !== $heading_title ? (string) $heading_title : __( 'Plans', 'stg-moyasar-payments' );
$stg_heading_intro = isset( $heading_intro ) && '' !== $heading_intro ? (string) $heading_intro : __( 'Everyone can post projects, browse work and send proposals for free. Paid plans buy more of it, not the right to take part.', 'stg-moyasar-payments' );
// A guest has no plan: "Your current plan" on the free card read as if they
// were already signed up (2026-10-01).
$stg_current      = is_user_logged_in() ? Subscription_Service::plan_slug_for( get_current_user_id() ) : '';
$stg_live         = is_user_logged_in() ? Subscription_Repository::live_for_user( get_current_user_id() ) : null;
$stg_can_buy      = Settings::is_operational();
// In the last days of a plan its holder may buy the next period.
$stg_can_renew    = $stg_live && Subscription_Service::can_renew( get_current_user_id() );
$stg_renew_opens  = $stg_live ? Subscription_Service::renewal_opens_at( $stg_live ) : 0;
?>

<div class="stg-pricing">

	<?php if ( $stg_show_heading ) : ?>
		<header class="stg-pricing__header">
			<h1><?php echo esc_html( $stg_heading_title ); ?></h1>
			<p class="stg-lede">
				<?php echo esc_html( $stg_heading_intro ); ?>
			</p>
		</header>
	<?php endif; ?>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presentational only; shows a message after a redirect.
	if ( ! empty( $_GET['expired'] ) ) :
		?>
		<p class="stg-notice stg-notice--warning" role="status">
			<?php esc_html_e( 'That checkout timed out before it was completed. You have not been charged — please choose a plan again.', 'stg-moyasar-payments' ); ?>
		</p>
	<?php endif; ?>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
	if ( ! empty( $_GET['unavailable'] ) ) :
		?>
		<p class="stg-notice stg-notice--error" role="alert">
			<?php esc_html_e( 'We could not start that payment. Please try again, or contact us if it keeps happening.', 'stg-moyasar-payments' ); ?>
		</p>
	<?php endif; ?>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
	if ( ! empty( $_GET['slow_down'] ) ) :
		?>
		<p class="stg-notice stg-notice--warning" role="status">
			<?php esc_html_e( 'That is a lot of checkouts in a short time. Please wait a moment and try again.', 'stg-moyasar-payments' ); ?>
		</p>
	<?php endif; ?>

	<div class="stg-pricing__grid">
		<?php foreach ( Plans::visible_to( get_current_user_id() ) as $stg_plan ) : ?>
			<?php
			$stg_is_current  = $stg_plan['slug'] === $stg_current;
			$stg_purchasable = Plans::is_purchasable( $stg_plan );
			$stg_is_free     = Plans::FREE === $stg_plan['slug'];
			$stg_grants      = (array) $stg_plan['entitlements'];
			?>
			<article class="stg-plan<?php echo $stg_is_current ? ' stg-plan--current' : ''; ?><?php echo ! empty( $stg_plan['recommended'] ) ? ' stg-plan--recommended' : ''; ?>">

				<?php if ( ! empty( $stg_plan['recommended'] ) ) : ?>
					<p class="stg-plan__badge"><?php esc_html_e( 'Recommended', 'stg-moyasar-payments' ); ?></p>
				<?php endif; ?>

				<h2 class="stg-plan__name"><?php echo esc_html( (string) $stg_plan['name'] ); ?></h2>

				<p class="stg-plan__price">
					<?php if ( $stg_is_free ) : ?>
						<?php // The plan is already called Free; its price says what it costs. ?>
						<span class="stg-plan__amount"><?php echo \STG\Core\Support\Money::html( 0, (string) $stg_plan['currency'], 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Money. ?></span>
					<?php elseif ( null === $stg_plan['price'] ) : ?>
						<?php
						/*
						 * An unpriced plan renders as an invitation to talk,
						 * never as "SAR 0". STG ships its paid plans without
						 * prices because the commercial decisions behind them
						 * are genuinely still open, and printing an invented
						 * number on a public page would be the same class of
						 * mistake as the deck's placeholder ratings that the
						 * phase gate already forbids.
						 */
						?>
						<span class="stg-plan__amount stg-plan__amount--tbc">
							<?php esc_html_e( 'Talk to us', 'stg-moyasar-payments' ); ?>
						</span>
					<?php else : ?>
						<span class="stg-plan__amount">
							<?php echo \STG\Core\Support\Money::html( (int) $stg_plan['price'], (string) $stg_plan['currency'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Money. ?>
						</span>
						<span class="stg-plan__interval">
							<?php
							echo esc_html(
								'year' === $stg_plan['interval']
									? __( 'per year', 'stg-moyasar-payments' )
									: __( 'per month', 'stg-moyasar-payments' )
							);
							?>
						</span>
					<?php endif; ?>
				</p>

				<p class="stg-plan__description"><?php echo esc_html( (string) $stg_plan['description'] ); ?></p>

				<ul class="stg-plan__features">
					<?php // Proposals are a professional's allowance; a clients' plan says nothing about them. ?>
					<li<?php echo 'client' === ( $stg_plan['audience'] ?? 'all' ) ? ' hidden' : ''; ?>>
						<?php
						$stg_proposals = (int) ( $stg_grants['proposals_per_month'] ?? 0 );

						echo esc_html(
							0 === $stg_proposals
								? __( 'Unlimited proposals each month', 'stg-moyasar-payments' )
								: sprintf(
									/* translators: %s: number of proposals */
									_n( '%s proposal each month', '%s proposals each month', $stg_proposals, 'stg-moyasar-payments' ),
									number_format_i18n( $stg_proposals )
								)
						);
						?>
					</li>
					<?php
					// Featured listings and team accounts are stored on a plan but
					// nothing delivers them yet, so the page does not promise them.
					?>
					<?php if ( ! empty( $stg_grants['priority_support'] ) ) : ?>
						<li><?php esc_html_e( 'Priority support', 'stg-moyasar-payments' ); ?></li>
					<?php endif; ?>
					<?php // The limits this plan sets (STG → Plans), as sentences. ?>
					<?php foreach ( Plans::limit_lines( $stg_plan ) as $stg_limit ) : ?>
						<li><?php echo esc_html( $stg_limit ); ?></li>
					<?php endforeach; ?>
					<?php // Extra lines from STG → Plans, in the visitor's language. ?>
					<?php foreach ( (array) ( $stg_plan['features'] ?? array() ) as $stg_feature ) : ?>
						<li><?php echo esc_html( (string) $stg_feature ); ?></li>
					<?php endforeach; ?>
				</ul>

				<div class="stg-plan__action">
					<?php if ( $stg_is_current ) : ?>
						<p class="stg-plan__current-label">
							<?php esc_html_e( 'Your current plan', 'stg-moyasar-payments' ); ?>
							<?php if ( $stg_live && ! empty( $stg_live['current_period_end'] ) ) : ?>
								<span class="stg-plan__renews">
									<?php
									printf(
										/* translators: %s: date */
										esc_html__( 'Runs until %s', 'stg-moyasar-payments' ),
										esc_html(
											date_i18n(
												(string) get_option( 'date_format' ),
												(int) strtotime( (string) $stg_live['current_period_end'] . ' UTC' )
											)
										)
									);
									?>
								</span>
							<?php endif; ?>
						</p>

						<?php if ( ! $stg_is_free && $stg_purchasable && $stg_can_buy && $stg_can_renew ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="stg-plan__form">
								<input type="hidden" name="action" value="stg_moyasar_checkout">
								<input type="hidden" name="plan" value="<?php echo esc_attr( (string) $stg_plan['slug'] ); ?>">
								<?php wp_nonce_field( 'stg_moyasar_checkout' ); ?>
								<button type="submit" class="stg-btn stg-btn--primary stg-btn--block">
									<?php
									printf(
										/* translators: %s: plan name */
										esc_html__( 'Renew %s', 'stg-moyasar-payments' ),
										esc_html( (string) $stg_plan['name'] )
									);
									?>
								</button>
							</form>
							<p class="stg-plan__renew-note"><?php esc_html_e( 'The new period starts when this one ends. You lose no days.', 'stg-moyasar-payments' ); ?></p>
						<?php elseif ( ! $stg_is_free && $stg_purchasable && $stg_can_buy && $stg_renew_opens > time() ) : ?>
							<p class="stg-plan__renew-note">
								<?php
								printf(
									/* translators: %s: date */
									esc_html__( 'You can renew from %s.', 'stg-moyasar-payments' ),
									esc_html( date_i18n( (string) get_option( 'date_format' ), $stg_renew_opens ) )
								);
								?>
							</p>
						<?php endif; ?>
					<?php elseif ( $stg_is_free ) : ?>
						<p class="stg-plan__current-label"><?php esc_html_e( 'Included with every account', 'stg-moyasar-payments' ); ?></p>
					<?php elseif ( ! $stg_purchasable || ! $stg_can_buy ) : ?>
						<a class="stg-btn stg-btn--ghost stg-btn--block" href="<?php echo esc_url( add_query_arg( array( 'topic' => 'plans', 'plan' => (string) $stg_plan['slug'] ), home_url( '/contact/' ) ) ); ?>">
							<?php esc_html_e( 'Get in touch', 'stg-moyasar-payments' ); ?>
						</a>
						<?php if ( $stg_can_buy && null !== $stg_plan['price'] && ! Plans::currency_payable( (string) $stg_plan['currency'] ) ) : ?>
							<p class="stg-plan__renew-note"><?php esc_html_e( 'This plan cannot be paid for online in its currency yet.', 'stg-moyasar-payments' ); ?></p>
						<?php endif; ?>
					<?php elseif ( ! is_user_logged_in() ) : ?>
						<a class="stg-btn stg-btn--primary stg-btn--block" href="<?php echo esc_url( home_url( '/account/register/' ) ); ?>">
							<?php esc_html_e( 'Create an account', 'stg-moyasar-payments' ); ?>
						</a>
					<?php else : ?>
						<?php
						/*
						 * A form post, not a link.
						 *
						 * Pressing this opens a payment intent, which is a
						 * state change - so it goes through POST with a nonce
						 * rather than sitting behind a GET that a crawler, a
						 * link prefetcher or a chat client's preview fetcher
						 * would happily trigger.
						 */
						?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="stg-plan__form">
							<input type="hidden" name="action" value="stg_moyasar_checkout">
							<input type="hidden" name="plan" value="<?php echo esc_attr( (string) $stg_plan['slug'] ); ?>">
							<?php wp_nonce_field( 'stg_moyasar_checkout' ); ?>
							<button type="submit" class="stg-btn stg-btn--primary stg-btn--block">
								<?php
								printf(
									/* translators: %s: plan name */
									esc_html__( 'Choose %s', 'stg-moyasar-payments' ),
									esc_html( (string) $stg_plan['name'] )
								);
								?>
							</button>
						</form>
					<?php endif; ?>
				</div>

			</article>
		<?php endforeach; ?>
	</div>

	<?php if ( ! $stg_can_buy ) : ?>
		<p class="stg-pricing__note">
			<?php esc_html_e( 'Online payment is not switched on yet. Get in touch and we will arrange a plan for you directly.', 'stg-moyasar-payments' ); ?>
		</p>
	<?php endif; ?>

</div>
