<?php
/**
 * Settings → Advanced.
 *
 * Diagnostics and the two knobs nobody should need to touch. Deliberately the
 * last tab: everything here is either a developer aid or a number with a
 * sensible default, and putting it first would suggest otherwise.
 *
 * @package STG\Moyasar
 *
 * @var bool                  $debug
 * @var int                   $timeout
 * @var int                   $ttl
 * @var array<string, string> $diagnostics
 * @var string                $post_url
 * @var bool                  $can_test
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="stg-pay-section">
	<h2><?php esc_html_e( 'Diagnostics', 'stg-moyasar-payments' ); ?></h2>
	<p class="stg-pay-section__intro">
		<?php esc_html_e( 'What this site knows about its own payment configuration. Nothing here reaches Moyasar — use the connection test below for that.', 'stg-moyasar-payments' ); ?>
	</p>

	<dl class="stg-pay-dl">
		<?php foreach ( $diagnostics as $stg_label => $stg_value ) : ?>
			<dt><?php echo esc_html( $stg_label ); ?></dt>
			<dd><?php echo esc_html( $stg_value ); ?></dd>
		<?php endforeach; ?>
	</dl>
</div>

<div class="stg-pay-section">
	<h2><?php esc_html_e( 'Connection test', 'stg-moyasar-payments' ); ?></h2>
	<p class="stg-pay-section__intro">
		<?php esc_html_e( 'Reads a single payment from your Moyasar account to confirm the current environment’s keys work. It only reads — nothing is created and no card is charged — so it is safe to press in live mode.', 'stg-moyasar-payments' ); ?>
	</p>

	<?php if ( $can_test ) : ?>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>">
			<?php wp_nonce_field( 'stg_moyasar_test' ); ?>
			<input type="hidden" name="action" value="stg_moyasar_test">
			<?php submit_button( __( 'Test the connection', 'stg-moyasar-payments' ), 'secondary', 'submit', false ); ?>
		</form>
	<?php else : ?>
		<p class="description">
			<?php esc_html_e( 'Add the keys for this environment first — there is nothing to test with yet.', 'stg-moyasar-payments' ); ?>
		</p>
	<?php endif; ?>
</div>

<form method="post" action="<?php echo esc_url( $post_url ); ?>">
	<?php wp_nonce_field( 'stg_moyasar_save' ); ?>
	<input type="hidden" name="action" value="stg_moyasar_save">
	<input type="hidden" name="tab" value="advanced">

	<div class="stg-pay-section">
		<h2><?php esc_html_e( 'Developer options', 'stg-moyasar-payments' ); ?></h2>

		<div class="stg-pay-rows">
			<?php
			STG\Moyasar\Admin\View::render(
				'partials/switch-row',
				array(
					'id'      => 'stg-debug',
					'name'    => 'debug',
					'value'   => '1',
					'checked' => $debug,
					'title'   => __( 'Extra logging', 'stg-moyasar-payments' ),
					'desc'    => __( 'Record request-level detail while integrating. Errors and payment events are always recorded; this only adds timing and request detail, and it never records a key or a card number.', 'stg-moyasar-payments' ),
				)
			);
			?>

			<div class="stg-pay-row">
				<div class="stg-pay-row__text">
					<label class="stg-pay-row__title" for="stg-timeout"><?php esc_html_e( 'API timeout', 'stg-moyasar-payments' ); ?></label>
					<p class="stg-pay-row__desc" id="stg-timeout-desc">
						<?php esc_html_e( 'How long to wait for Moyasar before giving up. Too high and a customer watches a spinner while a worker is held open; too low and a slow but healthy response is treated as a failure.', 'stg-moyasar-payments' ); ?>
					</p>
				</div>
				<div class="stg-pay-row__control">
					<span class="stg-pay-money">
						<input type="number" id="stg-timeout" name="api_timeout" value="<?php echo esc_attr( (string) $timeout ); ?>"
							min="5" max="30" step="1" aria-describedby="stg-timeout-desc">
						<span class="stg-pay-money__interval"><?php esc_html_e( 'seconds', 'stg-moyasar-payments' ); ?></span>
					</span>
				</div>
			</div>

			<div class="stg-pay-row">
				<div class="stg-pay-row__text">
					<label class="stg-pay-row__title" for="stg-ttl"><?php esc_html_e( 'Checkout expiry', 'stg-moyasar-payments' ); ?></label>
					<p class="stg-pay-row__desc" id="stg-ttl-desc">
						<?php esc_html_e( 'How long a started checkout stays valid. Long enough to read a verification SMS and be interrupted; short enough that a price change is not honoured for a day.', 'stg-moyasar-payments' ); ?>
					</p>
				</div>
				<div class="stg-pay-row__control">
					<span class="stg-pay-money">
						<input type="number" id="stg-ttl" name="intent_ttl_minutes" value="<?php echo esc_attr( (string) $ttl ); ?>"
							min="5" max="120" step="5" aria-describedby="stg-ttl-desc">
						<span class="stg-pay-money__interval"><?php esc_html_e( 'minutes', 'stg-moyasar-payments' ); ?></span>
					</span>
				</div>
			</div>
		</div>
	</div>

	<?php submit_button( __( 'Save changes', 'stg-moyasar-payments' ) ); ?>
</form>
