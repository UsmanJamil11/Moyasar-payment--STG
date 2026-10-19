<?php
/**
 * What state the gateway is actually in.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar\Admin;

use STG\Core\Subscriptions\Plans;
use STG\Moyasar\Api\Fake_Client;
use STG\Moyasar\Credentials;
use STG\Moyasar\Mode;
use STG\Moyasar\Payments\Repository;
use STG\Moyasar\Payments\Status;
use STG\Moyasar\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers the six questions an administrator opens this plugin to ask.
 *
 * Is it on? Which environment? Are the credentials there? Are webhooks
 * arriving? What has been paid? Does anything need me?
 *
 * They are answered here rather than in a view because three different screens
 * ask some of them — the overview, the settings status strip and the admin
 * notices — and three implementations of "is the gateway working" would
 * eventually give three answers. The views render what this returns.
 *
 * Every method is cheap: option reads and indexed `COUNT(*)` queries. Nothing
 * here calls Moyasar. A status panel that made an outbound HTTP request would
 * put a network round trip, and a potential ten-second timeout, in front of
 * every admin page load — which is how a payment plugin gets blamed for a slow
 * dashboard. Reaching the API is an explicit action on the Settings screen.
 */
final class Health {

	/**
	 * Option holding the last successful API call's timestamp.
	 */
	public const LAST_API = 'stg_moyasar_last_api_call';

	/**
	 * Option holding the last received webhook's timestamp.
	 */
	public const LAST_WEBHOOK = 'stg_moyasar_last_webhook';

	/**
	 * Is the gateway switched on and able to take a payment?
	 *
	 * @return array{state: string, label: string, detail: string}
	 */
	public static function gateway(): array {
		if ( ! Settings::get( 'enabled', false ) ) {
			return array(
				'state'  => 'warn',
				'label'  => __( 'Disabled', 'stg-moyasar-payments' ),
				'detail' => __( 'Customers are invited to contact you instead.', 'stg-moyasar-payments' ),
			);
		}

		if ( ! Settings::is_operational() ) {
			return array(
				'state'  => 'bad',
				'label'  => __( 'Not ready', 'stg-moyasar-payments' ),
				'detail' => __( 'Switched on, but this environment is missing a key.', 'stg-moyasar-payments' ),
			);
		}

		return array(
			'state'  => 'ok',
			'label'  => __( 'Accepting payments', 'stg-moyasar-payments' ),
			'detail' => __( 'Customers can reach the checkout.', 'stg-moyasar-payments' ),
		);
	}

	/**
	 * Which world are we in?
	 *
	 * @return array{state: string, label: string, detail: string, mode: string}
	 */
	public static function environment(): array {
		$mode = Settings::mode();

		if ( Mode::FAKE === $mode ) {
			return array(
				'state'  => 'bad',
				'mode'   => $mode,
				'label'  => __( 'Fake', 'stg-moyasar-payments' ),
				'detail' => __( 'A local test double is installed. Nothing reaches Moyasar.', 'stg-moyasar-payments' ),
			);
		}

		if ( Mode::is_live( $mode ) ) {
			return array(
				'state'  => 'warn',
				'mode'   => $mode,
				'label'  => __( 'Live', 'stg-moyasar-payments' ),
				'detail' => __( 'Real cards are being charged.', 'stg-moyasar-payments' ),
			);
		}

		return array(
			'state'  => 'info',
			'mode'   => $mode,
			'label'  => __( 'Test', 'stg-moyasar-payments' ),
			'detail' => __( 'No money is being taken.', 'stg-moyasar-payments' ),
		);
	}

	/**
	 * Are the credentials for the active environment present and well formed?
	 *
	 * This reports configuration, not reachability. "Connected" would be a
	 * claim this method cannot honestly make without calling Moyasar, so it
	 * does not make it — the Settings screen has a button for that, and its
	 * result is reported separately.
	 *
	 * @return array{state: string, label: string, detail: string}
	 */
	public static function credentials(): array {
		$mode = Settings::mode();

		if ( Mode::FAKE === $mode ) {
			return array(
				'state'  => 'info',
				'label'  => __( 'Not required', 'stg-moyasar-payments' ),
				'detail' => __( 'The test double needs no keys.', 'stg-moyasar-payments' ),
			);
		}

		if ( ! Credentials::complete_for( $mode ) ) {
			return array(
				'state'  => 'bad',
				'label'  => __( 'Incomplete', 'stg-moyasar-payments' ),
				'detail' => sprintf(
					/* translators: %s: environment name */
					__( 'The %s publishable and secret keys are not both set.', 'stg-moyasar-payments' ),
					Mode::label( $mode )
				),
			);
		}

		$last = (int) get_option( self::LAST_API, 0 );

		return array(
			'state'  => 'ok',
			'label'  => __( 'Configured', 'stg-moyasar-payments' ),
			'detail' => $last > 0
				? sprintf(
					/* translators: %s: human-readable time difference */
					__( 'Last reached Moyasar %s ago.', 'stg-moyasar-payments' ),
					human_time_diff( $last )
				)
				: __( 'Not yet used to call Moyasar.', 'stg-moyasar-payments' ),
		);
	}

	/**
	 * Are webhooks configured, and have any arrived?
	 *
	 * @return array{state: string, label: string, detail: string}
	 */
	public static function webhooks(): array {
		$mode = Settings::mode();
		$last = (int) get_option( self::LAST_WEBHOOK, 0 );

		if ( '' === Credentials::webhook_secret( $mode ) ) {
			return array(
				'state'  => 'warn',
				'label'  => __( 'Not configured', 'stg-moyasar-payments' ),
				/*
				 * Deliberately not called an error. Webhooks are an
				 * optimisation here, not a dependency: the browser callback
				 * settles most payments and the hourly reconciler catches the
				 * rest. Saying "broken" would send an administrator chasing
				 * something that is merely not set up yet.
				 */
				'detail' => __( 'Payments still settle without them, just more slowly.', 'stg-moyasar-payments' ),
			);
		}

		if ( $last < 1 ) {
			return array(
				'state'  => 'warn',
				'label'  => __( 'Never received', 'stg-moyasar-payments' ),
				'detail' => __( 'A secret is set, but no event has arrived yet.', 'stg-moyasar-payments' ),
			);
		}

		return array(
			'state'  => 'ok',
			'label'  => __( 'Receiving', 'stg-moyasar-payments' ),
			'detail' => sprintf(
				/* translators: %s: human-readable time difference */
				__( 'Last event %s ago.', 'stg-moyasar-payments' ),
				human_time_diff( $last )
			),
		);
	}

	/**
	 * Payment counts by status.
	 *
	 * @return array<string, int>
	 */
	public static function counts(): array {
		$out = array();

		foreach ( Status::all() as $status ) {
			$out[ $status ] = Repository::count_by_status( $status );
		}

		return $out;
	}

	/**
	 * Things that need a person, most urgent first.
	 *
	 * Deliberately short. A warnings list that always has nine entries is a
	 * list nobody reads, so this only reports states that are genuinely
	 * actionable right now.
	 *
	 * @return array<int, array{level: string, text: string, action: string, url: string}>
	 */
	public static function warnings(): array {
		$out     = array();
		$mode    = Settings::mode();
		$flagged = Repository::count_needing_review();

		if ( $flagged > 0 ) {
			$out[] = array(
				'level'  => 'error',
				'text'   => sprintf(
					/* translators: %s: number of payments */
					_n(
						'%s payment needs attention: money moved but something did not add up, so no plan was granted automatically.',
						'%s payments need attention: money moved but something did not add up, so no plan was granted automatically.',
						$flagged,
						'stg-moyasar-payments'
					),
					number_format_i18n( $flagged )
				),
				'action' => __( 'Review them', 'stg-moyasar-payments' ),
				'url'    => add_query_arg(
					array(
						'page'   => 'stg-payments-transactions',
						'status' => Status::FLAGGED,
					),
					admin_url( 'admin.php' )
				),
			);
		}

		if ( Fake_Client::is_installed() ) {
			$out[] = array(
				'level'  => 'error',
				'text'   => __( 'A fake payment provider is installed. Every payment recorded here is simulated and nothing reaches Moyasar.', 'stg-moyasar-payments' ),
				'action' => '',
				'url'    => '',
			);
		}

		if ( Settings::get( 'enabled', false ) && ! Settings::is_operational() && Mode::FAKE !== $mode ) {
			$out[] = array(
				'level'  => 'error',
				'text'   => sprintf(
					/* translators: %s: environment name */
					__( 'Payments are switched on but the %s keys are missing, so the checkout cannot open.', 'stg-moyasar-payments' ),
					Mode::label( $mode )
				),
				'action' => __( 'Add keys', 'stg-moyasar-payments' ),
				'url'    => add_query_arg(
					array(
						'page' => 'stg-payments-settings',
						'tab'  => 'credentials',
					),
					admin_url( 'admin.php' )
				),
			);
		}

		if ( ! self::has_priced_plan() ) {
			$out[] = array(
				'level'  => 'warning',
				'text'   => __( 'No paid plan has a price yet, so nothing can be bought. Plans with no price appear on the pricing page as an invitation to get in touch.', 'stg-moyasar-payments' ),
				'action' => __( 'Set prices', 'stg-moyasar-payments' ),
				'url'    => add_query_arg(
					array(
						'page' => 'stg-payments-settings',
						'tab'  => 'general',
					),
					admin_url( 'admin.php' )
				),
			);
		}

		$stranded = Repository::count_stranded();

		if ( $stranded > 0 ) {
			$out[] = array(
				'level'  => 'warning',
				'text'   => sprintf(
					/* translators: %s: number of payments */
					_n(
						'%s checkout has been awaiting completion for more than a day.',
						'%s checkouts have been awaiting completion for more than a day.',
						$stranded,
						'stg-moyasar-payments'
					),
					number_format_i18n( $stranded )
				),
				'action' => __( 'View them', 'stg-moyasar-payments' ),
				'url'    => add_query_arg(
					array(
						'page'   => 'stg-payments-transactions',
						'status' => Status::PENDING,
					),
					admin_url( 'admin.php' )
				),
			);
		}

		return $out;
	}

	/**
	 * Whether at least one paid plan has a price.
	 *
	 * @return bool
	 */
	public static function has_priced_plan(): bool {
		foreach ( Plans::all() as $plan ) {
			if ( Plans::FREE !== $plan['slug'] && Plans::is_purchasable( $plan ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The checks that must pass before live mode is allowed.
	 *
	 * @return array<int, array{ok: bool, label: string, hint: string}>
	 */
	public static function preflight(): array {
		$host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$is_local = (bool) preg_match( '/(^localhost$|\.local$|\.test$|^\d+\.\d+\.\d+\.\d+$)/i', $host );

		return array(
			array(
				'ok'    => is_ssl(),
				'label' => __( 'This site is served over HTTPS.', 'stg-moyasar-payments' ),
				'hint'  => __( 'Moyasar will not send webhooks to a plain HTTP address, and card details must never travel over one.', 'stg-moyasar-payments' ),
			),
			array(
				'ok'    => ! $is_local,
				'label' => __( 'This site is on a public address.', 'stg-moyasar-payments' ),
				'hint'  => __( 'A .local, .test or IP address cannot receive webhooks from Moyasar.', 'stg-moyasar-payments' ),
			),
			array(
				'ok'    => 'production' === wp_get_environment_type(),
				'label' => __( 'WordPress reports this as a production environment.', 'stg-moyasar-payments' ),
				'hint'  => __( 'Set WP_ENVIRONMENT_TYPE to production in wp-config.php on the live site.', 'stg-moyasar-payments' ),
			),
			array(
				'ok'    => Credentials::complete_for( Mode::LIVE ),
				'label' => __( 'Both live keys are present and correctly formatted.', 'stg-moyasar-payments' ),
				'hint'  => __( 'Add them on the Credentials tab. They are not used until live mode is on.', 'stg-moyasar-payments' ),
			),
			array(
				'ok'    => '' !== Credentials::webhook_secret( Mode::LIVE ),
				'label' => __( 'A live webhook secret is set.', 'stg-moyasar-payments' ),
				'hint'  => __( 'Create a live webhook in the Moyasar dashboard and paste its secret on the Webhooks tab.', 'stg-moyasar-payments' ),
			),
			array(
				'ok'    => self::has_priced_plan(),
				'label' => __( 'At least one paid plan has a price.', 'stg-moyasar-payments' ),
				'hint'  => __( 'A plan with no price cannot be bought, so live mode would have nothing to sell.', 'stg-moyasar-payments' ),
			),
			array(
				'ok'    => 0 === Repository::count_stranded(),
				'label' => __( 'No payment has been stuck awaiting completion for more than a day.', 'stg-moyasar-payments' ),
				'hint'  => __( 'Switching environment while payments are in flight leaves them unsettleable.', 'stg-moyasar-payments' ),
			),
			array(
				'ok'    => ! Fake_Client::is_installed(),
				'label' => __( 'The local test double is not installed.', 'stg-moyasar-payments' ),
				'hint'  => __( 'Remove the STG_MOYASAR_FAKE constant from wp-config.php.', 'stg-moyasar-payments' ),
			),
		);
	}

	/**
	 * Whether every preflight check passes.
	 *
	 * @return bool
	 */
	public static function ready_for_live(): bool {
		foreach ( self::preflight() as $check ) {
			if ( ! $check['ok'] ) {
				return false;
			}
		}

		return true;
	}
}
