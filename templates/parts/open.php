<?php
/**
 * Opening markup for a payment screen.
 *
 * Uses the theme's own app shell when it has one - `templates/parts/app-open.php`
 * in hello-stg - so these screens carry the same signed-in navigation and
 * language switcher as every other app screen. Without it a customer on the
 * checkout had no way back into the app but a text link, and no way to change
 * language on the page where the language matters most. See P13e-05.
 *
 * Found through `locate_template()` rather than required by path, so the plugin
 * still renders, with its own minimal wrapper, under a theme that has no shell.
 *
 * Expects `$stg_wrapper_class` (string) from the caller.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$stg_app_open = locate_template( array( 'templates/parts/app-open.php' ) );

if ( $stg_app_open ) {
	require $stg_app_open;
} else {
	get_header();
	?>
	<main id="stg-main" class="stg-app" tabindex="-1">
		<div class="stg-shell">
	<?php
}
?>
<div class="<?php echo esc_attr( isset( $stg_wrapper_class ) ? (string) $stg_wrapper_class : '' ); ?>">
