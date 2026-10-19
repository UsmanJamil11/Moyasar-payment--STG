<?php
/**
 * The overview screen.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar\Admin;

use STG\Moyasar\Payments\Repository;
use STG\Moyasar\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The landing page: gateway state, anything needing attention, recent payments.
 *
 * It is the first submenu item and therefore what "Payments" opens, because the
 * question an administrator arrives with is almost never "let me change a
 * setting" — it is "is this working, and does anything need me". Settings is
 * one click away; a status answer that needed a click would be the wrong way
 * round.
 */
final class Overview_Screen extends Screen {

	/**
	 * Page slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return self::MENU;
	}

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public function page_title(): string {
		return __( 'STG Payments', 'stg-moyasar-payments' );
	}

	/**
	 * Menu title.
	 *
	 * @return string
	 */
	public function menu_title(): string {
		return __( 'Overview', 'stg-moyasar-payments' );
	}

	/**
	 * Render.
	 *
	 * @return void
	 */
	public function render(): void {
		$this->require_permission();

		$gateway     = Health::gateway();
		$environment = Health::environment();
		$credentials = Health::credentials();
		$webhooks    = Health::webhooks();

		echo '<div class="wrap stg-pay">';

		$this->header(
			array(
				'subtitle' => __( 'Subscription payments through Moyasar.', 'stg-moyasar-payments' ),
				'actions'  => sprintf(
					'<a class="page-title-action" href="%1$s">%2$s</a>',
					esc_url( $this->url( 'stg-payments-settings' ) ),
					esc_html__( 'Settings', 'stg-moyasar-payments' )
				),
			)
		);

		View::render(
			'overview',
			array(
				'cards'            => array(
					array(
						'label' => __( 'Gateway', 'stg-moyasar-payments' ),
						'value' => $gateway['label'],
						'meta'  => $gateway['detail'],
						'state' => $gateway['state'],
					),
					array(
						'label' => __( 'Environment', 'stg-moyasar-payments' ),
						'value' => $environment['label'],
						'badge' => View::mode_badge( $environment['mode'] ),
						'meta'  => $environment['detail'],
						'state' => $environment['state'],
					),
					array(
						'label' => __( 'API keys', 'stg-moyasar-payments' ),
						'value' => $credentials['label'],
						'meta'  => $credentials['detail'],
						'state' => $credentials['state'],
					),
					array(
						'label' => __( 'Webhooks', 'stg-moyasar-payments' ),
						'value' => $webhooks['label'],
						'meta'  => $webhooks['detail'],
						'state' => $webhooks['state'],
					),
				),
				'warnings'         => Health::warnings(),
				'counts'           => Health::counts(),
				'recent'           => Repository::paginate( array(), 1, 5 )['rows'],
				'transactions_url' => $this->url( 'stg-payments-transactions' ),
				'settings_url'     => $this->url( 'stg-payments-settings', array( 'tab' => 'credentials' ) ),
				'configured'       => Settings::is_operational() || Repository::count_by_status( 'paid' ) > 0,
			)
		);

		echo '</div>';
	}
}
