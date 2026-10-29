<?php
/**
 * Plugin Name:       STG Payments — Moyasar
 * Plugin URI:        https://servethegalaxy.com/
 * Description:       Subscription payment processing for Serve The Galaxy through the Moyasar gateway. Provides mada, Visa and Mastercard checkout, mandatory server-side payment verification, webhook handling with reconciliation, a transaction record with a full audit trail, and separate test and live environments guarded by a preflight checklist. Card details pass from the customer's browser directly to Moyasar and never reach this server. STG never holds funds between users — this charges for STG's own subscription plans only.
 * Version:           1.0.2
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Requires Plugins:  stg-core
 * Author:            Serve The Galaxy
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       stg-moyasar-payments
 * Domain Path:       /languages
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION     = '1.0.2';
const MIN_PHP     = '8.1';
const MIN_WP      = '6.8';

define( 'STG_MOYASAR_FILE', __FILE__ );
define( 'STG_MOYASAR_DIR', plugin_dir_path( __FILE__ ) );
define( 'STG_MOYASAR_URL', plugin_dir_url( __FILE__ ) );
define( 'STG_MOYASAR_VERSION', VERSION );

require_once __DIR__ . '/src/Autoloader.php';
Autoloader::register( __NAMESPACE__, __DIR__ . '/src' );

/**
 * Reasons this plugin cannot run, in language an administrator can act on.
 *
 * Checked on every request rather than only at activation. A host can downgrade
 * PHP, and STG Core can be deactivated, long after this plugin was switched on -
 * and the failure mode for a payment plugin that half-loads is worse than for
 * most, because the half that loads might be the half that takes money.
 *
 * @return string[] Empty when the runtime is fine.
 */
function runtime_problems(): array {
	$problems = array();

	if ( version_compare( PHP_VERSION, MIN_PHP, '<' ) ) {
		$problems[] = sprintf(
			/* translators: 1: required PHP version, 2: current PHP version */
			__( 'STG Moyasar Payments requires PHP %1$s or later. This server runs PHP %2$s.', 'stg-moyasar-payments' ),
			MIN_PHP,
			PHP_VERSION
		);
	}

	if ( version_compare( get_bloginfo( 'version' ), MIN_WP, '<' ) ) {
		$problems[] = sprintf(
			/* translators: 1: required WordPress version, 2: current WordPress version */
			__( 'STG Moyasar Payments requires WordPress %1$s or later. This site runs %2$s.', 'stg-moyasar-payments' ),
			MIN_WP,
			get_bloginfo( 'version' )
		);
	}

	/*
	 * The hard dependency.
	 *
	 * This plugin is an adapter for a seam that lives in STG Core: the
	 * `Gateway` interface, the `stg_core_payment_gateway` filter and
	 * `Subscriptions\Service::start()`, which is the only thing in the project
	 * that grants a plan. Without Core there is nothing to adapt.
	 *
	 * `Requires Plugins` in the header covers WordPress 6.5+ in wp-admin. This
	 * covers everything else - WP-CLI, a REST request, a plugin deactivated
	 * directly in the database - and it degrades to an admin notice rather than
	 * a fatal, because a white screen on a live site is not an improvement on a
	 * checkout button that says it is unavailable.
	 */
	if ( ! interface_exists( '\STG\Core\Subscriptions\Gateway' ) ) {
		$problems[] = __( 'STG Moyasar Payments requires the STG Core plugin to be active.', 'stg-moyasar-payments' );
	}

	return $problems;
}

/*
 * Boot.
 *
 * Priority 20, after STG Core's 5. Core must have registered its modules before
 * this plugin can hook `stg_core_payment_gateway` or add a REST controller to
 * the list Core assembles.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		$problems = runtime_problems();

		if ( $problems ) {
			add_action(
				'admin_notices',
				static function () use ( $problems ): void {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}

					echo '<div class="notice notice-error"><p><strong>STG Moyasar Payments</strong></p><ul style="list-style:disc;padding-inline-start:20px">';

					foreach ( $problems as $problem ) {
						echo '<li>' . esc_html( $problem ) . '</li>';
					}

					echo '</ul></div>';
				}
			);

			return;
		}

		Plugin::instance()->boot();
	},
	20
);

register_activation_hook( __FILE__, array( Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Installer::class, 'deactivate' ) );
