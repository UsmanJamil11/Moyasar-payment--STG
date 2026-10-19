<?php
/**
 * One credential, in whichever of its three states it is in.
 *
 * - **Not configured**: a box to type it into.
 * - **Configured**: no box. A read-only masked line, a status, and a Remove
 *   key action. Replacing a key means removing it first, on purpose.
 * - **Managed by the server**: the same masked line, the name of the
 *   wp-config constant holding it, and no Remove - this screen cannot change
 *   that value, so it does not offer to.
 *
 * The saved value is NEVER sent to the browser, in any of them. The masked
 * line is fixed text: a prefix that says what kind of key the field is for,
 * then the same sixteen dots whatever is saved. It is not an input, so it
 * cannot be mistaken for one holding a value, submitted, or auto-filled.
 *
 * @package STG\Moyasar
 *
 * @var array<string, mixed> $cred Field data from Settings_Screen::field().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$stg_id     = 'stg-' . $cred['field'];
$stg_source = (string) $cred['source'];
$stg_public = ! $cred['secret'];
?>
<tr class="stg-pay-key-row">
	<th scope="row">
		<?php if ( 'none' === $stg_source ) : ?>
			<label for="<?php echo esc_attr( $stg_id ); ?>"><?php echo esc_html( $cred['label'] ); ?></label>
		<?php else : ?>
			<span id="<?php echo esc_attr( $stg_id ); ?>-label"><?php echo esc_html( $cred['label'] ); ?></span>
		<?php endif; ?>
	</th>
	<td>
		<?php if ( 'none' === $stg_source ) : ?>

			<div class="stg-pay-key stg-pay-key--empty">
				<input type="<?php echo $stg_public ? 'text' : 'password'; ?>"
					id="<?php echo esc_attr( $stg_id ); ?>"
					name="<?php echo esc_attr( $cred['field'] ); ?>"
					value=""
					class="regular-text code stg-pay-key__input"
					autocomplete="<?php echo $stg_public ? 'off' : 'new-password'; ?>"
					autocapitalize="off"
					autocorrect="off"
					data-lpignore="true"
					data-1p-ignore="true"
					data-bwignore="true"
					spellcheck="false"
					placeholder="<?php echo esc_attr( '' !== $cred['prefix'] ? $cred['prefix'] . '…' : '' ); ?>"
					aria-describedby="<?php echo esc_attr( $stg_id ); ?>-desc">
				<span class="stg-pay-status stg-pay-status--none">
					<?php esc_html_e( 'Not configured', 'stg-moyasar-payments' ); ?>
				</span>
			</div>
			<p class="description" id="<?php echo esc_attr( $stg_id ); ?>-desc">
				<?php
				if ( '' !== $cred['prefix'] ) {
					printf(
						/* translators: %s: key prefix such as pk_test_ */
						esc_html__( 'Paste the key from your Moyasar dashboard. It starts with %s', 'stg-moyasar-payments' ),
						'<code>' . esc_html( $cred['prefix'] ) . '</code>'
					);
				} else {
					esc_html_e( 'Paste the secret token you chose for this webhook in your Moyasar dashboard.', 'stg-moyasar-payments' );
				}
				?>
			</p>

		<?php else : ?>

			<div class="stg-pay-key stg-pay-key--set" role="group" aria-labelledby="<?php echo esc_attr( $stg_id ); ?>-label">
				<span class="stg-pay-key__mask">
					<span class="dashicons dashicons-lock" aria-hidden="true"></span>
					<span class="screen-reader-text"><?php esc_html_e( 'Saved value, hidden:', 'stg-moyasar-payments' ); ?></span>
					<code><?php echo esc_html( $cred['mask'] ); ?></code>
				</span>

				<span class="stg-pay-status stg-pay-status--set">
					<span aria-hidden="true">&#10003;</span>
					<?php esc_html_e( 'Configured', 'stg-moyasar-payments' ); ?>
				</span>

				<?php if ( 'database' === $stg_source ) : ?>
					<?php
					/*
					 * A link, so it works with no script: it opens this tab with
					 * the confirmation showing. With the script it opens the same
					 * confirmation as a dialog instead. Nothing is removed by
					 * following it - removal is a POST from the confirmation.
					 */
					?>
					<a class="button stg-pay-key__remove"
						href="<?php echo esc_url( $cred['remove'] ); ?>"
						data-stg-remove="<?php echo esc_attr( $cred['field'] ); ?>"
						data-stg-remove-name="<?php echo esc_attr( $cred['name'] ); ?>"
						data-stg-remove-impact="<?php echo esc_attr( $cred['impact'] ); ?>">
						<?php esc_html_e( 'Remove key', 'stg-moyasar-payments' ); ?>
						<span class="screen-reader-text">: <?php echo esc_html( $cred['name'] ); ?></span>
					</a>
				<?php endif; ?>
			</div>

			<p class="description">
				<?php if ( 'constant' === $stg_source ) : ?>
					<?php
					printf(
						/* translators: %s: constant name */
						esc_html__( 'Managed by the server: set in wp-config.php as %s. It cannot be changed or removed here; edit that file to change it.', 'stg-moyasar-payments' ),
						'<code>' . esc_html( $cred['constant'] ) . '</code>'
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'The saved value is never shown. To replace it, remove it first.', 'stg-moyasar-payments' ); ?>
				<?php endif; ?>
			</p>

		<?php endif; ?>
	</td>
</tr>
