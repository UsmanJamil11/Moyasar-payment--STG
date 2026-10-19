<?php
/**
 * The plans table, placeable anywhere.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `[stg_pricing]` — renders the plan cards inside any page, post or builder.
 *
 * ## Why a shortcode and not an Elementor widget
 *
 * The requirement is "an editor must be able to put the plans on a page they
 * are designing". A shortcode satisfies that everywhere at once: Elementor's
 * own Shortcode widget, the block editor's Shortcode block, a classic editor
 * page, a text widget, a template `do_shortcode()` call, and any future
 * builder. A custom Elementor widget would satisfy it in exactly one place, in
 * exchange for a dependency on `\Elementor\Widget_Base` - a third-party class
 * whose constructor signature and registration hook have both changed across
 * major versions - plus controls, an icon, a category registration and a
 * `register_widgets` hook that has itself been renamed once.
 *
 * That trade only pays off when the component needs editor-level controls:
 * per-instance colours, layout toggles, drag-and-drop sub-elements. This one
 * has no such controls, and should not - the plans come from configuration and
 * the prices come from the server, because a pricing table an editor can
 * retype is a pricing table that will eventually disagree with what the
 * checkout charges.
 *
 * So: one shortcode, no Elementor dependency, and the plugin stays working if
 * Elementor is deactivated tomorrow.
 *
 * ## Attributes
 *
 * `heading="no"` drops the title and lede, for when the surrounding design
 * already provides them - which is the normal case inside a page builder.
 */
final class Shortcode {

	/**
	 * Tag.
	 */
	public const TAG = 'stg_pricing';

	/**
	 * Register.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Render the plans.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'heading' => 'yes',
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);

		/*
		 * Assets are enqueued here rather than on `wp_enqueue_scripts`.
		 *
		 * A shortcode can appear on any page, and there is no way to know
		 * which from the enqueue hook without parsing post content - which
		 * misses builder-stored content anyway, since Elementor keeps its
		 * layout in post meta rather than in `post_content`. Enqueuing at
		 * render time means the stylesheet loads on exactly the pages that use
		 * the shortcode and nowhere else. WordPress prints late-registered
		 * styles in the footer, which for a non-critical component is the
		 * right place regardless.
		 */
		if ( ! wp_style_is( 'stg-moyasar', 'enqueued' ) ) {
			$deps = array_values( array_filter( array( 'stg-tokens', 'stg-app' ), 'wp_style_is' ) );

			wp_enqueue_style(
				'stg-moyasar',
				STG_MOYASAR_URL . 'assets/css/checkout.css',
				$deps,
				STG_MOYASAR_VERSION
			);
		}

		$show_heading = 'no' !== strtolower( trim( (string) $atts['heading'] ) );

		ob_start();

		/*
		 * The same partial the /pricing/ route renders.
		 *
		 * Deliberately one file rather than two: a shortcode that drifted away
		 * from the page it mirrors is how a site ends up quoting two different
		 * prices depending on which URL the customer landed on.
		 */
		$template = STG_MOYASAR_DIR . 'templates/parts/plans.php';

		if ( is_readable( $template ) ) {
			include $template;
		}

		return (string) ob_get_clean();
	}
}
