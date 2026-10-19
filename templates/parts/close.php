<?php
/**
 * Closing markup for a payment screen. The pair of `parts/open.php`.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
</div>
<?php
$stg_app_close = locate_template( array( 'templates/parts/app-close.php' ) );

if ( $stg_app_close ) {
	require $stg_app_close;
} else {
	?>
		</div>
	</main>
	<?php
	get_footer();
}
