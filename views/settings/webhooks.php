<?php
/**
 * Settings → Webhooks.
 *
 * @package STG\Moyasar
 *
 * @var string                           $endpoint
 * @var array<int, array<string, mixed>> $fields
 * @var array<int, string>               $events
 * @var array{state:string,label:string,detail:string} $status
 * @var bool                             $https
 * @var string                           $post_url
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="stg-pay-section">
	<h2><?php esc_html_e( 'Endpoint', 'stg-moyasar-payments' ); ?></h2>
	<p class="stg-pay-section__intro">
		<?php esc_html_e( 'In your Moyasar dashboard, add a webhook pointing at this address. Add one for test and one for live — they are separate endpoints with separate secrets.', 'stg-moyasar-payments' ); ?>
	</p>

	<div class="stg-pay-endpoint">
		<label class="screen-reader-text" for="stg-webhook-url"><?php esc_html_e( 'Webhook endpoint', 'stg-moyasar-payments' ); ?></label>
		<input type="text" id="stg-webhook-url" value="<?php echo esc_attr( $endpoint ); ?>" readonly onfocus="this.select()">
	</div>

	<?php if ( ! $https ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php esc_html_e( 'This site is not served over HTTPS, and Moyasar will not deliver webhooks to a plain HTTP address. Payments still settle through the customer’s browser and the hourly reconciliation sweep — confirmation is just slower.', 'stg-moyasar-payments' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Events to select', 'stg-moyasar-payments' ); ?></h2>
	<p class="stg-pay-section__intro">
		<?php esc_html_e( 'Tick these in the Moyasar dashboard. Any event not listed here is acknowledged and ignored, so selecting extras does no harm.', 'stg-moyasar-payments' ); ?>
	</p>
	<p>
		<?php foreach ( $events as $stg_event ) : ?>
			<code style="margin-inline-end:.5em"><?php echo esc_html( $stg_event ); ?></code>
		<?php endforeach; ?>
	</p>
	<p class="description">
		<?php esc_html_e( 'Moyasar’s own documentation spells the failure event two different ways. Both are handled, so tick whichever one your dashboard shows.', 'stg-moyasar-payments' ); ?>
	</p>
</div>

<div class="stg-pay-section">
	<h2><?php esc_html_e( 'Delivery', 'stg-moyasar-payments' ); ?></h2>
	<dl class="stg-pay-dl">
		<dt><?php esc_html_e( 'Status', 'stg-moyasar-payments' ); ?></dt>
		<dd><?php echo esc_html( $status['label'] ); ?> — <?php echo esc_html( $status['detail'] ); ?></dd>
	</dl>
	<p class="description">
		<?php esc_html_e( 'Webhooks speed confirmation up; they are not required for a payment to complete. Whatever a webhook reports, STG asks Moyasar directly what really happened before changing anything.', 'stg-moyasar-payments' ); ?>
	</p>
</div>

<form method="post" action="<?php echo esc_url( $post_url ); ?>">
	<?php wp_nonce_field( 'stg_moyasar_save' ); ?>
	<input type="hidden" name="action" value="stg_moyasar_save">
	<input type="hidden" name="tab" value="webhooks">

	<div class="stg-pay-section">
		<h2><?php esc_html_e( 'Secrets', 'stg-moyasar-payments' ); ?></h2>
		<p class="stg-pay-section__intro">
			<?php esc_html_e( 'The secret token you chose when creating each webhook in the Moyasar dashboard. Without it, this endpoint refuses every request.', 'stg-moyasar-payments' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tbody>
				<?php foreach ( $fields as $cred ) : ?>
					<?php require STG_MOYASAR_DIR . 'views/partials/credential-field.php'; ?>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<?php submit_button( __( 'Save secrets', 'stg-moyasar-payments' ) ); ?>
</form>

<?php require STG_MOYASAR_DIR . 'views/partials/remove-dialog.php'; ?>
