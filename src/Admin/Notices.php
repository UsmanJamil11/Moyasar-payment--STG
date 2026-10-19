<?php
/**
 * Admin banners about the payment environment.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar\Admin;

use STG\Moyasar\Api\Fake_Client;
use STG\Moyasar\Mode;
use STG\Moyasar\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Says which world the site is taking payments in, outside the payment screens.
 *
 * Not dismissible, and that is the point. "Which environment are we in" is the
 * question behind almost every payment incident — a site left in test mode
 * takes no money for a week; a site switched to live before anyone expected it
 * charges people. A banner that can be dismissed is a banner that gets
 * dismissed once and then never answers the question again.
 *
 * **It is suppressed on this plugin's own screens.** Those already carry the
 * environment in a status card and, on Settings, in a whole section. Repeating
 * it at the top of the page would be the third statement of the same fact on
 * one screen, which teaches people to skip banners — including the ones that
 * matter.
 *
 * The wording comes from `Health`, so the banner, the overview cards and the
 * settings screen cannot drift into describing the same state three ways.
 */
final class Notices {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Whether the current screen belongs to this plugin.
	 *
	 * @return bool
	 */
	private function on_payment_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen ) {
			return false;
		}

		return str_contains( (string) $screen->id, Screen::MENU );
	}

	/**
	 * Print the banners.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! Screen::permitted() || $this->on_payment_screen() ) {
			return;
		}

		$this->environment();
		$this->needs_attention();
	}

	/**
	 * Which environment payments are running in.
	 *
	 * @return void
	 */
	private function environment(): void {
		if ( Fake_Client::is_installed() ) {
			$this->banner(
				'error',
				__( 'STG Payments: a fake payment provider is installed.', 'stg-moyasar-payments' ),
				__( 'Nothing reaches Moyasar and every payment recorded is simulated. Remove the STG_MOYASAR_FAKE constant from wp-config.php to use the real provider.', 'stg-moyasar-payments' )
			);

			return;
		}

		// Nothing to say about an environment that cannot take a payment. The
		// "needs attention" banner below covers a misconfiguration.
		if ( ! Settings::get( 'enabled', false ) ) {
			return;
		}

		$environment = Health::environment();

		if ( Mode::is_live( Settings::mode() ) ) {
			$this->banner(
				'warning',
				__( 'STG Payments are live.', 'stg-moyasar-payments' ),
				$environment['detail']
			);

			return;
		}

		$this->banner(
			'info',
			__( 'STG Payments are in test mode.', 'stg-moyasar-payments' ),
			__( 'Customers can reach the checkout, but no money is being taken.', 'stg-moyasar-payments' )
		);
	}

	/**
	 * Anything that needs a person, with a link to it.
	 *
	 * Errors only. The overview page lists warnings too; repeating those on
	 * every admin screen would be nagging rather than informing.
	 *
	 * @return void
	 */
	private function needs_attention(): void {
		foreach ( Health::warnings() as $warning ) {
			if ( 'error' !== $warning['level'] ) {
				continue;
			}

			// The fake-provider warning is already the environment banner.
			if ( '' === $warning['action'] ) {
				continue;
			}

			printf(
				'<div class="notice notice-error"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html( $warning['text'] ),
				esc_url( $warning['url'] ),
				esc_html( $warning['action'] )
			);
		}
	}

	/**
	 * One banner.
	 *
	 * @param string $level WordPress notice level.
	 * @param string $lead  Bold opening sentence.
	 * @param string $rest  Supporting sentence.
	 * @return void
	 */
	private function banner( string $level, string $lead, string $rest ): void {
		printf(
			'<div class="notice notice-%1$s"><p><strong>%2$s</strong> %3$s</p></div>',
			esc_attr( $level ),
			esc_html( $lead ),
			esc_html( $rest )
		);
	}
}
