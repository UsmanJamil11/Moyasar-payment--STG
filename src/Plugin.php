<?php
/**
 * Plugin container and wiring.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar;

use STG\Moyasar\Admin\Menu;
use STG\Moyasar\Admin\Notices;
use STG\Moyasar\Admin\Overview_Screen;
use STG\Moyasar\Admin\Settings_Screen;
use STG\Moyasar\Admin\Transactions_Screen;
use STG\Moyasar\Api\Client;
use STG\Moyasar\Api\Fake_Client;
use STG\Moyasar\Api\Provider;
use STG\Moyasar\Frontend\Screens;
use STG\Moyasar\Frontend\Shortcode;
use STG\Moyasar\Gateway\Moyasar_Gateway;
use STG\Moyasar\Payments\Mailer;
use STG\Moyasar\Payments\Reconciler;
use STG\Moyasar\Payments\Repository;
use STG\Moyasar\Payments\Status;
use STG\Moyasar\Rest\Webhook_Controller;
use STG\Moyasar\Support\Log;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots the plugin and holds the one shared service that has state.
 *
 * Follows STG Core's convention: hooks are registered here and nowhere else, so
 * the entire hook surface is readable from one method. Services, repositories
 * and controllers stay hook-free.
 *
 * The plugin attaches to Core through exactly two filters -
 * `stg_core_payment_gateway` and `stg_core_rest_controllers` - plus two theme
 * filters for the front end. That is the whole integration surface, and it is
 * small on purpose: it is what lets this plugin be deactivated without leaving
 * the marketplace broken. With it off, Core falls back to `Manual_Gateway` and
 * the checkout button honestly says payment is unavailable.
 */
final class Plugin {

	/**
	 * Cron hook for the reconciliation sweep.
	 */
	public const RECONCILE_HOOK = 'stg_moyasar_reconcile';

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Resolved API client.
	 *
	 * @var Provider|null
	 */
	private ?Provider $provider = null;

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Use instance().
	 */
	private function __construct() {}

	/**
	 * Container accessor.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register everything.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		Settings::boot();

		/*
		 * Run any pending schema change before anything can touch the table.
		 *
		 * Activation already does this; repeating it on boot covers the case
		 * where the plugin files are updated without a reactivation, which is
		 * what a deployment looks like.
		 */
		if ( Installer::needs_migration() ) {
			try {
				Installer::migrate();
			} catch ( Throwable $e ) {
				Log::critical(
					'The payments schema could not be created. Payments are disabled until this is fixed.',
					array( 'error' => $e->getMessage() )
				);
			}
		}

		// The one line that connects this plugin to the marketplace.
		add_filter( 'stg_core_payment_gateway', array( $this, 'gateway' ) );

		// The webhook lives in Core's stg/v1 namespace so it inherits the
		// project's error envelope and permission conventions.
		add_filter( 'stg_core_rest_controllers', array( $this, 'rest_controllers' ) );

		// The customer's own record of what they paid. Hooked whether or not
		// the gateway is enabled: switching payments off must not make last
		// month's receipt disappear from the account screen.
		add_filter( 'stg_core_payment_history', array( $this, 'payment_history' ), 10, 3 );

		add_action( self::RECONCILE_HOOK, array( $this, 'reconcile' ) );
		add_action( 'init', array( $this, 'schedule' ) );

		// The confirmation and the staff alert the checkout screens promise.
		( new Mailer() )->register();

		( new Screens() )->register();
		( new Shortcode() )->register();

		if ( is_admin() ) {
			/*
			 * One registrar for all three screens, in menu order.
			 *
			 * Each screen used to hook `admin_menu` itself, which meant the
			 * transactions screen needed priority 11 so its submenu landed
			 * after the parent existed - an ordering that holds right up until
			 * somebody adds a fourth screen and picks 11 as well.
			 */
			( new Menu(
				array(
					new Overview_Screen(),
					new Transactions_Screen(),
					new Settings_Screen(),
				)
			) )->register();

			( new Notices() )->register();
		}

		load_plugin_textdomain(
			'stg-moyasar-payments',
			false,
			dirname( plugin_basename( STG_MOYASAR_FILE ) ) . '/languages'
		);
	}

	/**
	 * Supply the gateway to STG Core.
	 *
	 * Returns the existing value untouched when this plugin is switched off, so
	 * disabling payments in settings leaves Core on its manual gateway rather
	 * than on a gateway that refuses every request.
	 *
	 * @param mixed $gateway Whatever Core or another plugin already resolved.
	 * @return mixed
	 */
	public function gateway( $gateway ) {
		if ( empty( Settings::get( 'enabled', false ) ) ) {
			return $gateway;
		}

		return new Moyasar_Gateway();
	}

	/**
	 * This user's payments in Core's history shape.
	 *
	 * Money that moved, or was meant to: paid, refunded, declined, and paid
	 * but held for review. Open, expired and voided intents are checkouts
	 * somebody started and walked away from, not payments, and listing them
	 * would fill a customer's billing history with things they never bought.
	 *
	 * @param mixed $rows    Rows another gateway supplied.
	 * @param int   $user_id User.
	 * @param int   $limit   Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function payment_history( $rows, $user_id, $limit = 20 ): array {
		$rows = is_array( $rows ) ? $rows : array();
		$map  = array(
			Status::PAID     => 'paid',
			Status::REFUNDED => 'refunded',
			Status::FAILED   => 'failed',
			Status::FLAGGED  => 'review',
		);

		foreach ( Repository::for_user( (int) $user_id, (int) $limit ) as $payment ) {
			$status = (string) $payment['status'];

			if ( ! isset( $map[ $status ] ) ) {
				continue;
			}

			$rows[] = array(
				'reference' => (string) $payment['public_ref'],
				'plan'      => (string) $payment['plan_slug'],
				'amount'    => (int) $payment['amount'],
				'currency'  => (string) $payment['currency'],
				'status'    => $map[ $status ],
				'date'      => (string) ( $payment['paid_at'] ?: $payment['created_at'] ),
				'test'      => ! Mode::is_live( (string) $payment['mode'] ),
			);
		}

		return $rows;
	}

	/**
	 * Add this plugin's REST controllers to Core's list.
	 *
	 * @param array<int, string> $controllers Controller class names.
	 * @return array<int, string>
	 */
	public function rest_controllers( $controllers ): array {
		$controllers   = is_array( $controllers ) ? $controllers : array();
		$controllers[] = Webhook_Controller::class;

		return $controllers;
	}

	/**
	 * The API client for the active mode.
	 *
	 * The fake client is tried first and only succeeds if every one of its
	 * gates holds; its constructor throws otherwise, and the catch falls back
	 * to the real client. That ordering means a misconfigured attempt to use
	 * the fake provider degrades to talking to real Moyasar with real
	 * credentials - which will then fail its own key check if those are absent.
	 * There is no path where a fake result is mistaken for a real one.
	 *
	 * @return Provider
	 */
	public function provider(): Provider {
		if ( $this->provider instanceof Provider ) {
			return $this->provider;
		}

		if ( Fake_Client::is_installed() ) {
			try {
				$this->provider = new Fake_Client();

				return $this->provider;
			} catch ( Throwable $e ) {
				Log::critical( 'The fake payment client refused to start.', array( 'error' => $e->getMessage() ) );
			}
		}

		$this->provider = new Client( Settings::mode() );

		return $this->provider;
	}

	/**
	 * Replace the API client. Test harness only.
	 *
	 * @param Provider|null $provider Client, or null to reset.
	 * @return void
	 */
	public function set_provider( ?Provider $provider ): void {
		$this->provider = $provider;
	}

	/**
	 * Schedule the reconciliation sweep.
	 *
	 * @return void
	 */
	public function schedule(): void {
		if ( ! wp_next_scheduled( self::RECONCILE_HOOK ) ) {
			wp_schedule_event( time() + ( 10 * MINUTE_IN_SECONDS ), 'hourly', self::RECONCILE_HOOK );
		}
	}

	/**
	 * Run the reconciliation sweep.
	 *
	 * @return void
	 */
	public function reconcile(): void {
		if ( ! Settings::is_operational() ) {
			return;
		}

		$result = Reconciler::run();

		if ( $result['expired'] > 0 || $result['recovered'] > 0 ) {
			Log::info( 'Reconciliation sweep completed.', $result );
		}
	}
}
