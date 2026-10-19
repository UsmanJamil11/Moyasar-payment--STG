<?php
/**
 * Elementor widget: the plan cards.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The pricing cards as an Elementor block (D-060), so the Pricing page can be
 * laid out in Elementor like the other public pages.
 *
 * Renders `templates/parts/plans.php` - the one file the /pricing/ page and
 * the `[stg_pricing]` shortcode already share - so a price can never differ
 * between the three. Loaded only from the `elementor/widgets/register` hook,
 * after Elementor's base class exists.
 */
final class Pricing_Widget extends \Elementor\Widget_Base {

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'stg-pricing-plans';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'STG Pricing: plans', 'stg-moyasar-payments' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-price-table';
	}

	/**
	 * Category - the theme's STG group when it exists.
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( 'stg', 'general' );
	}

	/**
	 * Controls.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->start_controls_section( 'content', array( 'label' => __( 'Plans', 'stg-moyasar-payments' ) ) );
		$this->add_control(
			'heading',
			array(
				'label'        => __( 'Show the page heading', 'stg-moyasar-payments' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		// Wording per Page - English on the Pricing Page, Arabic on its twin.
		// Empty shows the standard, translated wording.
		foreach ( array(
			'title' => array( __( 'Heading', 'stg-moyasar-payments' ), __( 'Plans', 'stg-moyasar-payments' ), \Elementor\Controls_Manager::TEXT ),
			'intro' => array( __( 'Introduction', 'stg-moyasar-payments' ), __( 'Everyone can post projects, browse work and send proposals for free. Paid plans buy more of it, not the right to take part.', 'stg-moyasar-payments' ), \Elementor\Controls_Manager::TEXTAREA ),
		) as $key => $field ) {
			$this->add_control(
				$key,
				array(
					'label'       => $field[0],
					'type'        => $field[2],
					'placeholder' => $field[1],
					'default'     => '',
					'label_block' => true,
					'dynamic'     => array( 'active' => true ),
					'condition'   => array( 'heading' => 'yes' ),
				)
			);
		}

		$this->end_controls_section();
	}

	/**
	 * Render.
	 *
	 * @return void
	 */
	protected function render() {
		if ( ! class_exists( '\STG\Core\Subscriptions\Plans' ) ) {
			return;
		}

		$settings      = $this->get_settings_for_display();
		$show_heading  = 'yes' === ( $settings['heading'] ?? 'yes' );
		$heading_title = trim( (string) ( $settings['title'] ?? '' ) );
		$heading_intro = trim( (string) ( $settings['intro'] ?? '' ) );

		include STG_MOYASAR_DIR . 'templates/parts/plans.php';
	}
}
