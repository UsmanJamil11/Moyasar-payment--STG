<?php
/**
 * One payment, in full.
 *
 * What is deliberately absent: any card detail, because none is stored; the
 * webhook secret; and the raw provider payload. What IS shown that the
 * customer never sees: the provider's own failure text, the flag reason and
 * the internal reference — support needs all three and none of them is safe to
 * put in front of the person who paid.
 *
 * @package STG\Moyasar
 *
 * @var array<string, mixed>                   $row
 * @var \WP_User|false                         $user
 * @var array<string, mixed>|null              $subscription
 * @var array<string, mixed>|null              $plan
 * @var \WP_User|false                         $reviewer
 * @var array<int, array{label:string, when:string, state:string}> $timeline
 * @var string                                 $back_url
 * @var string                                 $post_url
 */

use STG\Moyasar\Admin\View;
use STG\Moyasar\Payments\Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Format a stored UTC datetime for display, or an em dash.
 *
 * @param mixed $value Datetime string or null.
 * @return string
 */
$stg_when = static function ( $value ): string {
	if ( empty( $value ) ) {
		return '—';
	}

	return date_i18n(
		(string) get_option( 'date_format' ) . ' H:i',
		(int) strtotime( (string) $value . ' UTC' )
	);
};
?>

<?php if ( Status::FLAGGED === (string) $row['status'] && ! empty( $row['reviewed_at'] ) ) : ?>
	<div class="notice notice-info inline">
		<p>
			<strong>
				<?php
				printf(
					/* translators: 1: staff member's name, 2: date and time */
					esc_html__( 'Dealt with by %1$s on %2$s.', 'stg-moyasar-payments' ),
					esc_html( $reviewer ? $reviewer->display_name : __( 'a former member of staff', 'stg-moyasar-payments' ) ),
					esc_html( $stg_when( $row['reviewed_at'] ) )
				);
				?>
			</strong>
			<?php echo esc_html( (string) ( $row['review_note'] ?? '' ) ); ?>
		</p>
	</div>
<?php elseif ( Status::FLAGGED === (string) $row['status'] ) : ?>
	<div class="notice notice-error inline">
		<p>
			<strong><?php esc_html_e( 'This payment needs a person.', 'stg-moyasar-payments' ); ?></strong>
			<?php esc_html_e( 'Money moved but an invariant did not hold, so no plan was granted automatically. Nothing has been refunded or reversed — that is a decision for you to make in the Moyasar dashboard.', 'stg-moyasar-payments' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( $post_url ); ?>">
			<?php wp_nonce_field( 'stg_moyasar_review_' . (int) $row['id'] ); ?>
			<input type="hidden" name="action" value="stg_moyasar_review">
			<input type="hidden" name="payment" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
			<p>
				<label for="stg-pay-review-note"><?php esc_html_e( 'What was done about it', 'stg-moyasar-payments' ); ?></label><br>
				<textarea id="stg-pay-review-note" name="review_note" rows="2" cols="60" maxlength="255" required></textarea>
			</p>
			<p class="description">
				<?php esc_html_e( 'For example: “Refunded in Moyasar” or “Plan given by hand in STG → Subscribers”. Saving this moves no money and grants nothing; it records that somebody has handled it.', 'stg-moyasar-payments' ); ?>
			</p>
			<p><?php submit_button( __( 'Mark as dealt with', 'stg-moyasar-payments' ), 'secondary', 'submit', false ); ?></p>
		</form>
	</div>
<?php endif; ?>

<div class="stg-pay-columns">

	<div>
		<div class="stg-pay-section">
			<h2><?php esc_html_e( 'Payment', 'stg-moyasar-payments' ); ?></h2>

			<dl class="stg-pay-dl">
				<dt><?php esc_html_e( 'Status', 'stg-moyasar-payments' ); ?></dt>
				<dd>
					<?php echo wp_kses_post( View::status_badge( (string) $row['status'] ) ); ?>
					<?php if ( ! empty( $row['flag_reason'] ) ) : ?>
						<br><code><?php echo esc_html( (string) $row['flag_reason'] ); ?></code>
						<?php if ( '' !== Status::flag_explanation( (string) $row['flag_reason'] ) ) : ?>
							<p class="description"><?php echo esc_html( Status::flag_explanation( (string) $row['flag_reason'] ) ); ?></p>
						<?php endif; ?>
					<?php endif; ?>
				</dd>

				<dt><?php esc_html_e( 'Amount', 'stg-moyasar-payments' ); ?></dt>
				<dd><?php echo View::money( (int) $row['amount'], (string) $row['currency'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Core's Money. ?></dd>

				<dt><?php esc_html_e( 'Environment', 'stg-moyasar-payments' ); ?></dt>
				<dd><?php echo wp_kses_post( View::mode_badge( (string) $row['mode'] ) ); ?></dd>

				<dt><?php esc_html_e( 'Billing period', 'stg-moyasar-payments' ); ?></dt>
				<dd>
					<?php
					printf(
						/* translators: %s: number of days */
						esc_html__( '%s days', 'stg-moyasar-payments' ),
						esc_html( number_format_i18n( (int) $row['period_days'] ) )
					);
					?>
				</dd>

				<dt><?php esc_html_e( 'Created', 'stg-moyasar-payments' ); ?></dt>
				<dd><?php echo esc_html( $stg_when( $row['created_at'] ) ); ?></dd>

				<dt><?php esc_html_e( 'Paid', 'stg-moyasar-payments' ); ?></dt>
				<dd><?php echo esc_html( $stg_when( $row['paid_at'] ) ); ?></dd>

				<?php if ( ! empty( $row['refunded_at'] ) ) : ?>
					<dt><?php esc_html_e( 'Refunded', 'stg-moyasar-payments' ); ?></dt>
					<dd><?php echo esc_html( $stg_when( $row['refunded_at'] ) ); ?></dd>
				<?php endif; ?>
			</dl>
		</div>

		<div class="stg-pay-section">
			<h2><?php esc_html_e( 'Customer', 'stg-moyasar-payments' ); ?></h2>

			<dl class="stg-pay-dl">
				<dt><?php esc_html_e( 'User', 'stg-moyasar-payments' ); ?></dt>
				<dd>
					<?php if ( $user ) : ?>
						<a href="<?php echo esc_url( (string) get_edit_user_link( $user->ID ) ); ?>">
							<?php echo esc_html( $user->display_name ); ?>
						</a>
					<?php else : ?>
						<?php esc_html_e( 'No longer exists', 'stg-moyasar-payments' ); ?>
					<?php endif; ?>
				</dd>

				<dt><?php esc_html_e( 'Plan', 'stg-moyasar-payments' ); ?></dt>
				<dd><?php echo esc_html( $plan ? (string) $plan['name'] : (string) $row['plan_slug'] ); ?></dd>

				<dt><?php esc_html_e( 'Subscription', 'stg-moyasar-payments' ); ?></dt>
				<dd>
					<?php if ( $subscription ) : ?>
						<?php
						printf(
							/* translators: 1: subscription status, 2: end date */
							esc_html__( '%1$s until %2$s', 'stg-moyasar-payments' ),
							esc_html( (string) $subscription['status'] ),
							esc_html( $stg_when( $subscription['current_period_end'] ) )
						);
						?>
					<?php else : ?>
						<span class="stg-pay-table__muted"><?php esc_html_e( 'None granted by this payment', 'stg-moyasar-payments' ); ?></span>
					<?php endif; ?>
				</dd>
			</dl>
		</div>
	</div>

	<div>
		<div class="stg-pay-section">
			<h2><?php esc_html_e( 'What happened', 'stg-moyasar-payments' ); ?></h2>
			<p class="stg-pay-section__intro">
				<?php esc_html_e( 'Reconstructed from the record’s own timestamps rather than a separate event log — the same facts, with nothing extra stored to produce them.', 'stg-moyasar-payments' ); ?>
			</p>

			<ol class="stg-pay-timeline">
				<?php foreach ( $timeline as $stg_step ) : ?>
					<li class="<?php echo esc_attr( 'done' === $stg_step['state'] ? 'is-done' : ( 'bad' === $stg_step['state'] ? 'is-bad' : '' ) ); ?>">
						<span class="stg-pay-timeline__what"><?php echo esc_html( $stg_step['label'] ); ?></span>
						<span class="stg-pay-timeline__when"><?php echo esc_html( $stg_step['when'] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>

		<div class="stg-pay-section">
			<h2><?php esc_html_e( 'Technical', 'stg-moyasar-payments' ); ?></h2>

			<dl class="stg-pay-dl">
				<dt><?php esc_html_e( 'STG reference', 'stg-moyasar-payments' ); ?></dt>
				<dd><code><?php echo esc_html( (string) $row['public_ref'] ); ?></code></dd>

				<dt><?php esc_html_e( 'Moyasar id', 'stg-moyasar-payments' ); ?></dt>
				<dd>
					<?php if ( ! empty( $row['provider_payment_id'] ) ) : ?>
						<code><?php echo esc_html( (string) $row['provider_payment_id'] ); ?></code>
					<?php else : ?>
						—
					<?php endif; ?>
				</dd>

				<dt><?php esc_html_e( 'Provider status', 'stg-moyasar-payments' ); ?></dt>
				<dd><?php echo esc_html( ! empty( $row['provider_status'] ) ? (string) $row['provider_status'] : '—' ); ?></dd>

				<dt><?php esc_html_e( 'Method', 'stg-moyasar-payments' ); ?></dt>
				<dd><?php echo esc_html( ! empty( $row['source_type'] ) ? (string) $row['source_type'] : '—' ); ?></dd>

				<?php if ( ! empty( $row['failure_message'] ) ) : ?>
					<dt><?php esc_html_e( 'Provider message', 'stg-moyasar-payments' ); ?></dt>
					<dd>
						<?php echo esc_html( (string) $row['failure_message'] ); ?>
						<span class="stg-pay-card__meta">
							<?php esc_html_e( 'Shown to you, never to the customer — it is English-only provider text.', 'stg-moyasar-payments' ); ?>
						</span>
					</dd>
				<?php endif; ?>
			</dl>

			<?php if ( ! empty( $row['provider_payment_id'] ) ) : ?>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>">
					<?php wp_nonce_field( 'stg_moyasar_recheck_' . (int) $row['id'] ); ?>
					<input type="hidden" name="action" value="stg_moyasar_recheck">
					<input type="hidden" name="payment" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
					<?php submit_button( __( 'Re-check with Moyasar', 'stg-moyasar-payments' ), 'secondary', 'submit', false ); ?>
					<p class="description">
						<?php esc_html_e( 'Asks Moyasar what this payment’s status really is and brings the record into line. It runs exactly the same code the webhook runs, so it cannot disagree with what happens automatically.', 'stg-moyasar-payments' ); ?>
					</p>
				</form>
			<?php elseif ( in_array( (string) $row['status'], array( Status::PENDING, Status::FAILED, Status::EXPIRED ), true ) ) : ?>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>">
					<?php wp_nonce_field( 'stg_moyasar_attach_' . (int) $row['id'] ); ?>
					<input type="hidden" name="action" value="stg_moyasar_attach">
					<input type="hidden" name="payment" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
					<p>
						<label for="stg-pay-attach-id"><?php esc_html_e( 'Moyasar payment id', 'stg-moyasar-payments' ); ?></label><br>
						<input type="text" id="stg-pay-attach-id" name="provider_payment_id" class="regular-text code" maxlength="64" autocomplete="off" required>
					</p>
					<?php submit_button( __( 'Check this payment', 'stg-moyasar-payments' ), 'secondary', 'submit', false ); ?>
					<p class="description">
						<?php esc_html_e( 'For a customer who paid but whose checkout never heard about it. Paste the payment id from the Moyasar dashboard: STG asks Moyasar about it and, if it is a real payment for this amount, settles this checkout exactly as the webhook would.', 'stg-moyasar-payments' ); ?>
					</p>
				</form>
			<?php endif; ?>
		</div>
	</div>

</div>

<p><a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to transactions', 'stg-moyasar-payments' ); ?></a></p>
