<?php
/**
 * The confirmation for removing a saved credential.
 *
 * One per tab. With the admin script it is a modal dialog filled in from the
 * button that opened it. Without it, the Remove link reloads the tab with
 * `?remove=<field>` and this renders already open, filled in by the server -
 * the same words and the same form either way.
 *
 * Removal itself is the POST below and nothing else. It carries its own nonce
 * and is checked again on the server: capability, field, where the value
 * really comes from.
 *
 * @package STG\Moyasar
 *
 * @var string                    $post_url   admin-post.php.
 * @var string                    $tab        Tab to return to.
 * @var string                    $cancel_url This tab, with no confirmation showing.
 * @var array<string, mixed>|null $removing   The credential a no-script link asked about.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<dialog id="stg-pay-remove" class="stg-pay-dialog" aria-labelledby="stg-pay-remove-title" aria-describedby="stg-pay-remove-impact"<?php echo $removing ? ' open' : ''; ?>>
	<form method="post" action="<?php echo esc_url( $post_url ); ?>" data-stg-busy="<?php esc_attr_e( 'Removing…', 'stg-moyasar-payments' ); ?>">
		<?php wp_nonce_field( 'stg_moyasar_forget', '_wpnonce', false ); ?>
		<input type="hidden" name="action" value="stg_moyasar_forget">
		<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
		<input type="hidden" name="confirm" value="1">
		<input type="hidden" name="field" value="<?php echo esc_attr( $removing ? $removing['field'] : '' ); ?>" data-stg-remove-field>

		<h2 id="stg-pay-remove-title" class="stg-pay-dialog__title">
			<span class="dashicons dashicons-warning" aria-hidden="true"></span>
			<?php esc_html_e( 'Remove this key?', 'stg-moyasar-payments' ); ?>
		</h2>

		<p class="stg-pay-dialog__what">
			<strong data-stg-remove-name><?php echo esc_html( $removing ? $removing['name'] : '' ); ?></strong>
		</p>

		<p id="stg-pay-remove-impact" class="stg-pay-dialog__impact" data-stg-remove-impact>
			<?php echo esc_html( $removing ? $removing['impact'] : '' ); ?>
		</p>

		<ul class="stg-pay-dialog__facts">
			<li><?php esc_html_e( 'Only this one value is removed. The other keys, the environment and the webhook settings stay as they are.', 'stg-moyasar-payments' ); ?></li>
			<li><?php esc_html_e( 'Payments already taken, plans and the transaction history are not changed.', 'stg-moyasar-payments' ); ?></li>
			<li><?php esc_html_e( 'The value is not kept anywhere. To use it again you will need it from your Moyasar dashboard.', 'stg-moyasar-payments' ); ?></li>
		</ul>

		<div class="stg-pay-dialog__actions">
			<a class="button" href="<?php echo esc_url( $cancel_url ); ?>" data-stg-remove-cancel><?php esc_html_e( 'Cancel', 'stg-moyasar-payments' ); ?></a>
			<button type="submit" class="button stg-pay-danger"><?php esc_html_e( 'Remove key', 'stg-moyasar-payments' ); ?></button>
		</div>
	</form>
</dialog>
