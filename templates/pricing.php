<?php
/**
 * The pricing page.
 *
 * A thin wrapper. The cards themselves live in `parts/plans.php`, shared with
 * the `[stg_pricing]` shortcode so the two can never quote different prices.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$stg_wrapper_class = 'stg-pricing-page';

require STG_MOYASAR_DIR . 'templates/parts/open.php';

// Laid out in Elementor when the theme provides a Pricing template (D-060);
// its plans widget renders the same parts/plans.php.
if ( ! function_exists( 'hello_stg_render_route_document' ) || ! hello_stg_render_route_document( 'pricing' ) ) {
	$show_heading = true;

	include STG_MOYASAR_DIR . 'templates/parts/plans.php';
}

require STG_MOYASAR_DIR . 'templates/parts/close.php';
