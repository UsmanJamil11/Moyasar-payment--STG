<?php
/**
 * Overview — the screen that answers "is this working?".
 *
 * Operational rather than decorative. No charts, no revenue graph, no
 * sparklines: STG sells three plans to a small number of people, and a
 * dashboard that invents analytics for that is a dashboard nobody opens twice.
 * What is here is what somebody actually needs at 9am — the gateway's state,
 * anything demanding attention, and the last few payments.
 *
 * @package STG\Moyasar
 *
 * @var array<int, array{label:string, value:string, meta:string, state:string, badge?:string}> $cards
 * @var array<int, array{level:string, text:string, action:string, url:string}>                 $warnings
 * @var array<string, int>                    $counts
 * @var array<int, array<string, mixed>>      $recent
 * @var string                                $transactions_url
 * @var string                                $settings_url
 * @var bool                                  $configured
 */

use STG\Moyasar\Admin\View;
use STG\Moyasar\Payments\Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<?php require __DIR__ . '/partials/cards.php'; ?>

<?php if ( $warnings ) : ?>
	<?php foreach ( $warnings as $warning ) : ?>
		<div class="notice notice-<?php echo esc_attr( $warning['level'] ); ?> inline">
			<p>
				<?php echo esc_html( $warning['text'] ); ?>
				<?php if ( '' !== $warning['action'] ) : ?>
					<a href="<?php echo esc_url( $warning['url'] ); ?>"><?php echo esc_html( $warning['action'] ); ?></a>
				<?php endif; ?>
			</p>
		</div>
	<?php endforeach; ?>
<?php endif; ?>

<?php if ( ! $configured ) : ?>

	<div class="stg-pay-empty">
		<h2><?php esc_html_e( 'Moyasar is not connected yet', 'stg-moyasar-payments' ); ?></h2>
		<p>
			<?php esc_html_e( 'Add your API keys to start taking subscription payments. Begin with your test keys — live mode stays locked until everything checks out.', 'stg-moyasar-payments' ); ?>
		</p>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( $settings_url ); ?>">
				<?php esc_html_e( 'Add API keys', 'stg-moyasar-payments' ); ?>
			</a>
		</p>
	</div>

<?php else : ?>

	<div class="stg-pay-columns">

		<div class="stg-pay-section">
			<h2><?php esc_html_e( 'Payments so far', 'stg-moyasar-payments' ); ?></h2>

			<dl class="stg-pay-dl">
				<?php foreach ( array( Status::PAID, Status::PENDING, Status::FAILED, Status::FLAGGED, Status::REFUNDED ) as $stg_status ) : ?>
					<dt><?php echo wp_kses_post( View::status_badge( $stg_status ) ); ?></dt>
					<dd><?php echo esc_html( number_format_i18n( (int) ( $counts[ $stg_status ] ?? 0 ) ) ); ?></dd>
				<?php endforeach; ?>
			</dl>
		</div>

		<div class="stg-pay-section">
			<h2><?php esc_html_e( 'Recent payments', 'stg-moyasar-payments' ); ?></h2>

			<?php if ( ! $recent ) : ?>
				<p class="stg-pay-section__intro">
					<?php esc_html_e( 'Nothing yet. Payments appear here as soon as the first customer checks out.', 'stg-moyasar-payments' ); ?>
				</p>
			<?php else : ?>
				<table class="wp-list-table widefat striped stg-pay-table">
					<caption class="screen-reader-text"><?php esc_html_e( 'The five most recent payments', 'stg-moyasar-payments' ); ?></caption>
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'When', 'stg-moyasar-payments' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Plan', 'stg-moyasar-payments' ); ?></th>
							<th scope="col" class="column-amount"><?php esc_html_e( 'Amount', 'stg-moyasar-payments' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'stg-moyasar-payments' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent as $stg_row ) : ?>
							<tr>
								<td>
									<?php
									echo esc_html(
										human_time_diff( (int) strtotime( (string) $stg_row['created_at'] . ' UTC' ) )
									);
									?>
									<span class="stg-pay-table__muted"><?php esc_html_e( 'ago', 'stg-moyasar-payments' ); ?></span>
								</td>
								<td><?php echo esc_html( (string) $stg_row['plan_slug'] ); ?></td>
								<td class="column-amount">
									<?php echo View::money( (int) $stg_row['amount'], (string) $stg_row['currency'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Core's Money. ?>
								</td>
								<td><?php echo wp_kses_post( View::status_badge( (string) $stg_row['status'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p>
					<a href="<?php echo esc_url( $transactions_url ); ?>">
						<?php esc_html_e( 'View all transactions', 'stg-moyasar-payments' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>

	</div>

<?php endif; ?>
