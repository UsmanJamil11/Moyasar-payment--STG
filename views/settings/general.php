<?php
/**
 * Settings → General.
 *
 * Row layout rather than core's `.form-table`. On a screen that is mostly
 * on/off switches, `.form-table` puts the label in a narrow fixed column and
 * the control in a wide one, which wastes the right half of the page and
 * strands the explanatory text in the middle. Here the label and its
 * explanation sit together and the control is aligned right, which is also
 * what every mature settings screen in this category does.
 *
 * @package STG\Moyasar
 *
 * @var bool                                 $enabled
 * @var string                               $mode
 * @var bool                                 $mode_locked
 * @var string                               $mode_locked_reason
 * @var bool                                 $ready_for_live
 * @var array<int, array{ok:bool,label:string,hint:string}> $preflight
 * @var array<int, array<string, mixed>>     $plans
 * @var array<string, array{label:string,note:string}> $methods
 * @var array<int, string>                   $methods_on
 * @var array<string, string>                $networks
 * @var array<int, string>                   $networks_on
 * @var array<int, string>                   $currencies
 * @var array<int, string>                   $currencies_on
 * @var string                               $post_url
 */

use STG\Moyasar\Admin\View;
use STG\Moyasar\Mode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<form method="post" action="<?php echo esc_url( $post_url ); ?>">
	<?php wp_nonce_field( 'stg_moyasar_save' ); ?>
	<input type="hidden" name="action" value="stg_moyasar_save">
	<input type="hidden" name="tab" value="general">

	<div class="stg-pay-section">
		<h2><?php esc_html_e( 'Gateway', 'stg-moyasar-payments' ); ?></h2>

		<div class="stg-pay-rows">
			<?php
			View::render(
				'partials/switch-row',
				array(
					'id'      => 'stg-enabled',
					'name'    => 'enabled',
					'value'   => '1',
					'checked' => $enabled,
					'title'   => __( 'Accept payments', 'stg-moyasar-payments' ),
					'desc'    => __( 'Offer online checkout to customers. With this off, the pricing page invites them to contact you instead. Turning it on does nothing until the keys for the current environment are present.', 'stg-moyasar-payments' ),
				)
			);
			?>
		</div>
	</div>

	<div class="stg-pay-section">
		<h2><?php esc_html_e( 'Plan prices', 'stg-moyasar-payments' ); ?></h2>
		<p class="stg-pay-section__intro" id="stg-prices-help">
			<?php esc_html_e( 'Enter the amount customers pay, for example 99.00. Leave a plan empty to keep it unpriced — it then appears on the pricing page as an invitation to get in touch rather than as something that can be bought. An invented number on a public page is worse than no number.', 'stg-moyasar-payments' ); ?>
		</p>

		<div class="stg-pay-rows">
			<?php foreach ( $plans as $stg_plan ) : ?>
				<?php $stg_id = 'stg-price-' . sanitize_key( (string) $stg_plan['slug'] ); ?>
				<div class="stg-pay-row">
					<div class="stg-pay-row__text">
						<label class="stg-pay-row__title" for="<?php echo esc_attr( $stg_id ); ?>">
							<?php echo esc_html( (string) $stg_plan['name'] ); ?>
						</label>
						<p class="stg-pay-row__desc"><?php echo esc_html( (string) $stg_plan['description'] ); ?></p>
					</div>

					<div class="stg-pay-row__control">
						<span class="stg-pay-money">
							<?php
							/*
							 * The how-to-fill-this-in text is on the section, not
							 * repeated under every plan. It was identical in each
							 * row, which is noise the reader has to re-scan to
							 * confirm it says nothing new. `aria-describedby`
							 * points every field at the single copy, so a screen
							 * reader still hears it per field.
							 */
							?>
							<input type="text"
								inputmode="decimal"
								id="<?php echo esc_attr( $stg_id ); ?>"
								name="prices[<?php echo esc_attr( (string) $stg_plan['slug'] ); ?>]"
								value="<?php echo esc_attr( null === $stg_plan['price'] ? '' : number_format( (int) $stg_plan['price'] / 100, 2, '.', '' ) ); ?>"
								placeholder="<?php esc_attr_e( 'Not priced', 'stg-moyasar-payments' ); ?>"
								aria-describedby="stg-prices-help">
							<span class="stg-pay-money__currency"><?php echo esc_html( (string) $stg_plan['currency'] ); ?></span>
							<span class="stg-pay-money__interval">
								<?php
								echo esc_html(
									'year' === $stg_plan['interval']
										? __( 'per year', 'stg-moyasar-payments' )
										: __( 'per month', 'stg-moyasar-payments' )
								);
								?>
							</span>
						</span>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="stg-pay-section">
		<h2><?php esc_html_e( 'Payment methods', 'stg-moyasar-payments' ); ?></h2>
		<p class="stg-pay-section__intro">
			<?php esc_html_e( 'Anything other than cards must be enabled on your Moyasar account first. Switching one on here before that renders a button that cannot take a payment.', 'stg-moyasar-payments' ); ?>
		</p>

		<div class="stg-pay-rows">
			<?php foreach ( $methods as $stg_key => $stg_method ) : ?>
				<?php
				View::render(
					'partials/switch-row',
					array(
						'id'      => 'stg-method-' . sanitize_key( (string) $stg_key ),
						'name'    => 'methods[]',
						'value'   => (string) $stg_key,
						'checked' => in_array( $stg_key, $methods_on, true ),
						'title'   => $stg_method['label'],
						'desc'    => $stg_method['note'],
					)
				);
				?>
			<?php endforeach; ?>

			<div class="stg-pay-row">
				<div class="stg-pay-row__text">
					<span class="stg-pay-row__title"><?php esc_html_e( 'Card networks', 'stg-moyasar-payments' ); ?></span>
					<p class="stg-pay-row__desc">
						<?php esc_html_e( 'mada is the Saudi domestic scheme and most cards in the market carry it. Leaving it off will turn away a large share of customers.', 'stg-moyasar-payments' ); ?>
					</p>
				</div>

				<div class="stg-pay-row__control">
					<fieldset class="stg-pay-chips">
						<legend class="screen-reader-text"><?php esc_html_e( 'Accepted card networks', 'stg-moyasar-payments' ); ?></legend>
						<?php foreach ( $networks as $stg_net => $stg_label ) : ?>
							<label class="stg-pay-chip">
								<input type="checkbox" name="supported_networks[]" value="<?php echo esc_attr( $stg_net ); ?>"
									<?php checked( in_array( $stg_net, $networks_on, true ) ); ?>>
								<?php echo esc_html( $stg_label ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
				</div>
			</div>

			<?php foreach ( $currencies as $stg_code ) : ?>
				<?php
				View::render(
					'partials/switch-row',
					array(
						'id'      => 'stg-currency-' . strtolower( $stg_code ),
						'name'    => 'currencies[]',
						'value'   => $stg_code,
						'checked' => in_array( $stg_code, $currencies_on, true ),
						/* translators: 1: currency name, 2: ISO code */
						'title'   => sprintf( __( 'Take payments in %1$s (%2$s)', 'stg-moyasar-payments' ), \STG\Core\Support\Money::name( $stg_code ), $stg_code ),
						'desc'    => __( 'Saudi riyals always work. Another currency works only after Moyasar has enabled it on your merchant account (cards only), and Moyasar pays it out to your bank in riyals at its own rate. Switch this on once Moyasar has confirmed it. While it is off, a plan priced in this currency shows its price but cannot be bought online.', 'stg-moyasar-payments' ),
					)
				);
				?>
			<?php endforeach; ?>
		</div>
	</div>

	<?php submit_button( __( 'Save changes', 'stg-moyasar-payments' ) ); ?>
</form>

<div class="stg-pay-section">
	<h2><?php esc_html_e( 'Environment', 'stg-moyasar-payments' ); ?></h2>

	<?php if ( $mode_locked ) : ?>

		<p class="stg-pay-section__intro"><?php echo esc_html( $mode_locked_reason ); ?></p>

	<?php elseif ( Mode::is_live( $mode ) ) : ?>

		<p class="stg-pay-section__intro">
			<?php esc_html_e( 'This site is taking real payments. Switching back to test mode stops that immediately; payments already taken are unaffected.', 'stg-moyasar-payments' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( $post_url ); ?>">
			<?php wp_nonce_field( 'stg_moyasar_mode' ); ?>
			<input type="hidden" name="action" value="stg_moyasar_mode">
			<input type="hidden" name="mode" value="<?php echo esc_attr( Mode::TEST ); ?>">
			<?php submit_button( __( 'Switch back to test mode', 'stg-moyasar-payments' ), 'secondary', 'submit', false ); ?>
		</form>

	<?php else : ?>

		<p class="stg-pay-section__intro">
			<?php esc_html_e( 'Every check below must pass before live mode will start. These are not advisory: live mode refuses to switch on until each one is true.', 'stg-moyasar-payments' ); ?>
		</p>

		<ul class="stg-pay-checklist">
			<?php foreach ( $preflight as $stg_check ) : ?>
				<li>
					<?php echo wp_kses_post( View::check( (bool) $stg_check['ok'] ) ); ?>
					<span>
						<?php echo esc_html( $stg_check['label'] ); ?>
						<?php if ( ! $stg_check['ok'] ) : ?>
							<span class="stg-pay-card__meta"><?php echo esc_html( $stg_check['hint'] ); ?></span>
						<?php endif; ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( $ready_for_live ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<strong><?php esc_html_e( 'Switching to live mode means real customer cards will be charged.', 'stg-moyasar-payments' ); ?></strong>
					<?php esc_html_e( 'Test a full payment with a Moyasar test card first if you have not already.', 'stg-moyasar-payments' ); ?>
				</p>
			</div>

			<form method="post" action="<?php echo esc_url( $post_url ); ?>">
				<?php wp_nonce_field( 'stg_moyasar_mode' ); ?>
				<input type="hidden" name="action" value="stg_moyasar_mode">
				<input type="hidden" name="mode" value="<?php echo esc_attr( Mode::LIVE ); ?>">

				<p>
					<label for="stg-confirm-live">
						<strong><?php esc_html_e( 'Type LIVE to confirm', 'stg-moyasar-payments' ); ?></strong>
					</label><br>
					<input type="text" id="stg-confirm-live" name="confirm" class="regular-text" autocomplete="off">
				</p>

				<?php submit_button( __( 'Switch to live mode', 'stg-moyasar-payments' ), 'primary', 'submit', false ); ?>
			</form>
		<?php else : ?>
			<p class="description">
				<?php esc_html_e( 'Live mode is unavailable until every check above passes.', 'stg-moyasar-payments' ); ?>
			</p>
		<?php endif; ?>

	<?php endif; ?>
</div>
