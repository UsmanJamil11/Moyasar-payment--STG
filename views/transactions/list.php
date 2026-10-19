<?php
/**
 * Transactions list.
 *
 * Core's `.wp-list-table` markup, written directly rather than through
 * `WP_List_Table` — see Screen for why. The result is the same component every
 * other WordPress list uses, including its responsive column collapse.
 *
 * @package STG\Moyasar
 *
 * @var array<int, array<string, mixed>> $rows
 * @var int                              $total
 * @var int                              $page
 * @var int                              $per_page
 * @var array<string, string>            $filters
 * @var array<string, string>            $statuses
 * @var array<string, string>            $modes
 * @var string                           $base_url
 * @var callable                         $detail_url
 * @var bool                             $filtered
 */

use STG\Moyasar\Admin\View;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<form method="get" class="stg-pay-filters">
	<input type="hidden" name="page" value="stg-payments-transactions">

	<label class="screen-reader-text" for="stg-filter-status"><?php esc_html_e( 'Filter by status', 'stg-moyasar-payments' ); ?></label>
	<select name="status" id="stg-filter-status">
		<option value=""><?php esc_html_e( 'Any status', 'stg-moyasar-payments' ); ?></option>
		<?php foreach ( $statuses as $stg_key => $stg_label ) : ?>
			<option value="<?php echo esc_attr( $stg_key ); ?>" <?php selected( $filters['status'], $stg_key ); ?>>
				<?php echo esc_html( $stg_label ); ?>
			</option>
		<?php endforeach; ?>
	</select>

	<label class="screen-reader-text" for="stg-filter-mode"><?php esc_html_e( 'Filter by environment', 'stg-moyasar-payments' ); ?></label>
	<select name="mode" id="stg-filter-mode">
		<option value=""><?php esc_html_e( 'Any environment', 'stg-moyasar-payments' ); ?></option>
		<?php foreach ( $modes as $stg_key => $stg_label ) : ?>
			<option value="<?php echo esc_attr( $stg_key ); ?>" <?php selected( $filters['mode'], $stg_key ); ?>>
				<?php echo esc_html( $stg_label ); ?>
			</option>
		<?php endforeach; ?>
	</select>

	<label class="screen-reader-text" for="stg-filter-search"><?php esc_html_e( 'Search', 'stg-moyasar-payments' ); ?></label>
	<input type="search" id="stg-filter-search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>"
		placeholder="<?php esc_attr_e( 'Reference or Moyasar id', 'stg-moyasar-payments' ); ?>">

	<?php submit_button( __( 'Filter', 'stg-moyasar-payments' ), 'secondary', '', false ); ?>

	<?php if ( $filtered ) : ?>
		<a class="button-link" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Clear', 'stg-moyasar-payments' ); ?></a>
	<?php endif; ?>
</form>

<?php if ( ! $rows ) : ?>

	<div class="stg-pay-empty">
		<?php if ( $filtered ) : ?>
			<h2><?php esc_html_e( 'No payments match those filters', 'stg-moyasar-payments' ); ?></h2>
			<p><?php esc_html_e( 'Try widening the search, or clear the filters to see everything.', 'stg-moyasar-payments' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Clear filters', 'stg-moyasar-payments' ); ?></a></p>
		<?php else : ?>
			<h2><?php esc_html_e( 'No payments yet', 'stg-moyasar-payments' ); ?></h2>
			<p><?php esc_html_e( 'Every checkout a customer starts appears here, whether it succeeds or not — including the ones that need a second look.', 'stg-moyasar-payments' ); ?></p>
		<?php endif; ?>
	</div>

<?php else : ?>

	<table class="wp-list-table widefat fixed striped stg-pay-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Created', 'stg-moyasar-payments' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Reference', 'stg-moyasar-payments' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Customer', 'stg-moyasar-payments' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Plan', 'stg-moyasar-payments' ); ?></th>
				<th scope="col" class="column-amount"><?php esc_html_e( 'Amount', 'stg-moyasar-payments' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Environment', 'stg-moyasar-payments' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'stg-moyasar-payments' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Reported by', 'stg-moyasar-payments' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $stg_row ) : ?>
				<?php
				$stg_user = get_userdata( (int) $stg_row['user_id'] );
				$stg_seen = array();

				if ( ! empty( $stg_row['callback_seen_at'] ) ) {
					$stg_seen[] = __( 'browser', 'stg-moyasar-payments' );
				}

				if ( ! empty( $stg_row['webhook_seen_at'] ) ) {
					$stg_seen[] = __( 'webhook', 'stg-moyasar-payments' );
				}
				?>
				<tr>
					<td>
						<?php
						echo esc_html(
							date_i18n(
								(string) get_option( 'date_format' ) . ' H:i',
								(int) strtotime( (string) $stg_row['created_at'] . ' UTC' )
							)
						);
						?>
					</td>
					<td>
						<strong>
							<a href="<?php echo esc_url( $detail_url( (string) $stg_row['public_ref'] ) ); ?>">
								<code><?php echo esc_html( substr( (string) $stg_row['public_ref'], 0, 12 ) ); ?></code>
							</a>
						</strong>
					</td>
					<td>
						<?php if ( $stg_user ) : ?>
							<a href="<?php echo esc_url( (string) get_edit_user_link( (int) $stg_row['user_id'] ) ); ?>">
								<?php echo esc_html( $stg_user->display_name ); ?>
							</a>
						<?php else : ?>
							<span class="stg-pay-table__muted"><?php esc_html_e( 'Unknown', 'stg-moyasar-payments' ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( (string) $stg_row['plan_slug'] ); ?></td>
					<td class="column-amount">
						<?php echo View::money( (int) $stg_row['amount'], (string) $stg_row['currency'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Core's Money. ?>
					</td>
					<td><?php echo wp_kses_post( View::mode_badge( (string) $stg_row['mode'] ) ); ?></td>
					<td>
						<?php echo wp_kses_post( View::status_badge( (string) $stg_row['status'] ) ); ?>
						<?php if ( ! empty( $stg_row['flag_reason'] ) ) : ?>
							<span class="stg-pay-table__flag"><?php echo esc_html( (string) $stg_row['flag_reason'] ); ?></span>
							<?php if ( ! empty( $stg_row['reviewed_at'] ) ) : ?>
								<span class="stg-pay-table__muted"><?php esc_html_e( 'Dealt with', 'stg-moyasar-payments' ); ?></span>
							<?php endif; ?>
						<?php endif; ?>
					</td>
					<td class="stg-pay-table__muted">
						<?php echo esc_html( $stg_seen ? implode( ', ', $stg_seen ) : '—' ); ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php
	$stg_pages = (int) ceil( $total / max( 1, $per_page ) );

	if ( $stg_pages > 1 ) :
		?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php
					printf(
						/* translators: %s: number of payments */
						esc_html( _n( '%s payment', '%s payments', $total, 'stg-moyasar-payments' ) ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</span>
				<?php
				echo wp_kses_post(
					(string) paginate_links(
						array(
							'base'     => add_query_arg( 'paged', '%#%' ),
							'format'   => '',
							'total'    => $stg_pages,
							'current'  => $page,
							'type'     => 'plain',
							'add_args' => array_filter( $filters ),
						)
					)
				);
				?>
			</div>
		</div>
	<?php endif; ?>

<?php endif; ?>
