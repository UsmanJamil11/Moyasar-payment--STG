<?php
/**
 * Settings → API keys.
 *
 * @package STG\Moyasar
 *
 * @var array<int, array<string, mixed>> $test_fields
 * @var array<int, array<string, mixed>> $live_fields
 * @var array<string, mixed>|null        $removing
 * @var string                           $post_url
 * @var string                           $cancel_url
 * @var string                           $tab
 * @var string                           $mode
 * @var bool                             $enabled
 * @var bool                             $can_test
 */

use STG\Moyasar\Mode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Two questions the page answers in words rather than leaving to be worked
 * out from four small states: is this environment complete, and is there
 * anything left on this screen to type.
 */
$stg_complete = static function ( array $fields ): bool {
	foreach ( $fields as $stg_one ) {
		if ( 'none' === $stg_one['source'] ) {
			return false;
		}
	}

	return true;
};

$stg_groups = array(
	array(
		'title'  => __( 'Test keys', 'stg-moyasar-payments' ),
		'intro'  => __( 'Used while the environment is set to test. Nothing charged with these is real.', 'stg-moyasar-payments' ),
		'fields' => $test_fields,
		'active' => ! Mode::is_live( $mode ) && Mode::FAKE !== $mode,
		'badge'  => 'stg-pay-badge--mode-test',
	),
	array(
		'title'  => __( 'Live keys', 'stg-moyasar-payments' ),
		'intro'  => __( 'These can be entered before going live. They are not used until live mode is switched on, so adding them now is safe.', 'stg-moyasar-payments' ),
		'fields' => $live_fields,
		'active' => Mode::is_live( $mode ),
		'badge'  => 'stg-pay-badge--mode-live',
	),
);

$stg_open = ! $stg_complete( $test_fields ) || ! $stg_complete( $live_fields );
?>

<form method="post" action="<?php echo esc_url( $post_url ); ?>" data-stg-busy="<?php esc_attr_e( 'Saving…', 'stg-moyasar-payments' ); ?>">
	<?php wp_nonce_field( 'stg_moyasar_save' ); ?>
	<input type="hidden" name="action" value="stg_moyasar_save">
	<input type="hidden" name="tab" value="credentials">

	<div class="stg-pay-section">
		<h2><?php esc_html_e( 'Where to find these', 'stg-moyasar-payments' ); ?></h2>
		<p class="stg-pay-section__intro">
			<?php esc_html_e( 'In your Moyasar dashboard, under API keys. A key is shown to you only once, when it is created.', 'stg-moyasar-payments' ); ?>
		</p>
		<p class="stg-pay-section__intro">
			<strong><?php esc_html_e( 'Keys entered here are stored in this site’s database.', 'stg-moyasar-payments' ); ?></strong>
			<?php esc_html_e( 'For production it is safer to define them in wp-config.php instead, where they sit outside the database and outside any backup of it. If a constant is defined it takes precedence, and the matching field below becomes read-only.', 'stg-moyasar-payments' ); ?>
		</p>
	</div>

	<?php foreach ( $stg_groups as $stg_group ) : ?>
		<?php $stg_ready = $stg_complete( $stg_group['fields'] ); ?>
		<div class="stg-pay-section">
			<h2>
				<?php echo esc_html( $stg_group['title'] ); ?>
				<?php if ( $stg_group['active'] ) : ?>
					<span class="stg-pay-badge <?php echo esc_attr( $stg_group['badge'] ); ?>"><?php esc_html_e( 'In use', 'stg-moyasar-payments' ); ?></span>
				<?php endif; ?>
			</h2>
			<p class="stg-pay-section__intro"><?php echo esc_html( $stg_group['intro'] ); ?></p>

			<?php if ( $stg_group['active'] && Mode::is_live( $mode ) ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'These keys are in use right now. Changing them affects live payments immediately.', 'stg-moyasar-payments' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $stg_group['active'] && ! $stg_ready ) : ?>
				<?php
				/*
				 * The environment in use is missing a key. Checkout is already
				 * withheld by Settings::is_operational(); this says so where the
				 * person who can fix it is looking.
				 */
				?>
				<div class="notice notice-<?php echo $enabled ? 'error' : 'warning'; ?> inline stg-pay-incomplete" role="status">
					<p>
						<strong><?php esc_html_e( 'This environment is not fully configured.', 'stg-moyasar-payments' ); ?></strong>
						<?php esc_html_e( 'It needs both keys. Until both are saved, the checkout is not offered and no payment can start.', 'stg-moyasar-payments' ); ?>
					</p>
				</div>
			<?php elseif ( $stg_ready ) : ?>
				<p class="stg-pay-ready is-ready"><?php esc_html_e( 'Both keys are saved.', 'stg-moyasar-payments' ); ?></p>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tbody>
					<?php foreach ( $stg_group['fields'] as $cred ) : ?>
						<?php require STG_MOYASAR_DIR . 'views/partials/credential-field.php'; ?>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endforeach; ?>

	<?php if ( $stg_open ) : ?>
		<?php submit_button( __( 'Save keys', 'stg-moyasar-payments' ) ); ?>
	<?php endif; ?>
</form>

<?php require STG_MOYASAR_DIR . 'views/partials/remove-dialog.php'; ?>

<div class="stg-pay-section">
	<h2><?php esc_html_e( 'Connection test', 'stg-moyasar-payments' ); ?></h2>
	<p class="stg-pay-section__intro">
		<?php esc_html_e( 'Reads a single payment from your Moyasar account to confirm the current environment’s keys work. It only reads — nothing is created and no card is charged — so it is safe to press in live mode.', 'stg-moyasar-payments' ); ?>
	</p>

	<?php if ( $can_test ) : ?>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>" data-stg-busy="<?php esc_attr_e( 'Testing…', 'stg-moyasar-payments' ); ?>">
			<?php wp_nonce_field( 'stg_moyasar_test' ); ?>
			<input type="hidden" name="action" value="stg_moyasar_test">
			<input type="hidden" name="return" value="credentials">
			<?php submit_button( __( 'Test the connection', 'stg-moyasar-payments' ), 'secondary', 'submit', false ); ?>
		</form>
	<?php else : ?>
		<p class="description">
			<?php esc_html_e( 'Add the keys for this environment first — there is nothing to test with yet.', 'stg-moyasar-payments' ); ?>
		</p>
	<?php endif; ?>
</div>
