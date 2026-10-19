<?php
/**
 * The payment settings screen.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar\Admin;

use STG\Core\Subscriptions\Plans;
use STG\Moyasar\Api\Fake_Client;
use STG\Moyasar\Credentials;
use STG\Moyasar\Mode;
use STG\Moyasar\Payments\Status;
use STG\Moyasar\Plugin;
use STG\Moyasar\Settings;
use STG\Moyasar\Support\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Four tabs: General, Credentials, Webhooks, Advanced.
 *
 * This class now holds decisions and no markup. Every `echo` that used to be
 * here — ninety-two of them — moved into `views/settings/*.php`, which turned
 * an eight-hundred-line file into one where the page's structure is visible
 * and a designer can change a label without reading PHP.
 *
 * The tabs are not decoration. The previous single page put API keys, plan
 * prices, payment methods, webhook secrets, the environment switch and the
 * diagnostics panel in one column, so the most dangerous control on the screen
 * — switch to live — sat below a fold, underneath a form about card networks.
 * Grouping by task means each screen asks one question, and the destructive
 * one arrives with its checklist attached.
 */
final class Settings_Screen extends Screen {

	/**
	 * Transient holding the outcome of a key save, per user, until it is shown.
	 */
	private const RESULT = 'stg_moyasar_key_result_';

	/**
	 * Page slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'stg-payments-settings';
	}

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public function page_title(): string {
		return __( 'Payment settings', 'stg-moyasar-payments' );
	}

	/**
	 * Menu title.
	 *
	 * @return string
	 */
	public function menu_title(): string {
		return __( 'Settings', 'stg-moyasar-payments' );
	}

	/**
	 * Register POST handlers.
	 *
	 * @return void
	 */
	public function register_handlers(): void {
		add_action( 'admin_post_stg_moyasar_save', array( $this, 'save' ) );
		add_action( 'admin_post_stg_moyasar_mode', array( $this, 'switch_mode' ) );
		add_action( 'admin_post_stg_moyasar_test', array( $this, 'test_connection' ) );
		add_action( 'admin_post_stg_moyasar_forget', array( $this, 'forget_key' ) );
	}

	/**
	 * The tabs, as the header partial wants them.
	 *
	 * @return array<string, array{label: string, url: string}>
	 */
	private function tabs(): array {
		$labels = array(
			'general'     => __( 'General', 'stg-moyasar-payments' ),
			'credentials' => __( 'API keys', 'stg-moyasar-payments' ),
			'webhooks'    => __( 'Webhooks', 'stg-moyasar-payments' ),
			'advanced'    => __( 'Advanced', 'stg-moyasar-payments' ),
		);

		$out = array();

		foreach ( $labels as $key => $label ) {
			$out[ $key ] = array(
				'label' => $label,
				'url'   => $this->url( '', array( 'tab' => $key ) ),
			);
		}

		return $out;
	}

	/**
	 * The requested tab, defaulting to the first.
	 *
	 * @return string
	 */
	private function current_tab(): string {
		$tab = $this->query( 'tab', 'general' );

		return array_key_exists( $tab, $this->tabs() ) ? $tab : 'general';
	}

	/**
	 * Render.
	 *
	 * @return void
	 */
	public function render(): void {
		$this->require_permission();

		$tab  = $this->current_tab();
		$mode = Settings::mode();

		echo '<div class="wrap stg-pay">';

		$this->header(
			array(
				'subtitle' => __( 'Credentials, environment and payment behaviour.', 'stg-moyasar-payments' ),
				'current'  => $tab,
				'tabs'     => $this->tabs(),
			)
		);

		$this->print_notice( $this->messages() );
		$this->print_key_result();

		switch ( $tab ) {
			case 'credentials':
				$this->render_credentials( $mode );
				break;

			case 'webhooks':
				$this->render_webhooks( $mode );
				break;

			case 'advanced':
				$this->render_advanced( $mode );
				break;

			default:
				$this->render_general( $mode );
		}

		echo '</div>';
	}

	/**
	 * Messages shown after a redirect.
	 *
	 * @return array<string, array{type: string, text: string}>
	 */
	private function messages(): array {
		return array(
			'saved'        => array(
				'type' => 'success',
				'text' => __( 'Settings saved.', 'stg-moyasar-payments' ),
			),
			'mode_live'    => array(
				'type' => 'warning',
				'text' => __( 'Live mode is on. Real cards will now be charged.', 'stg-moyasar-payments' ),
			),
			'mode_test'    => array(
				'type' => 'success',
				'text' => __( 'Test mode is on. No money will be taken.', 'stg-moyasar-payments' ),
			),
			'mode_blocked' => array(
				'type' => 'error',
				'text' => __( 'Live mode was not switched on: either a check has not passed, or the confirmation did not match.', 'stg-moyasar-payments' ),
			),
			'test_ok'      => array(
				'type' => 'success',
				'text' => __( 'Connected to Moyasar successfully with the current keys.', 'stg-moyasar-payments' ),
			),
			'test_failed'  => array(
				'type' => 'error',
				'text' => __( 'Could not connect to Moyasar with the current keys. Check that they belong to this environment and were copied in full.', 'stg-moyasar-payments' ),
			),
			'key_removed'  => array(
				'type' => 'success',
				'text' => __( 'The saved value was removed. You can enter a new one now.', 'stg-moyasar-payments' ),
			),
			'key_unconfirmed' => array(
				'type' => 'error',
				'text' => __( 'Nothing was removed, because the removal was not confirmed.', 'stg-moyasar-payments' ),
			),
			'key_kept'     => array(
				'type' => 'error',
				'text' => __( 'Nothing was removed: that value is set in wp-config.php, or was not saved here.', 'stg-moyasar-payments' ),
			),
			'test_refused' => array(
				'type' => 'error',
				'text' => __( 'Moyasar rejected the secret key for this environment. Check it was copied in full, or regenerate it in your Moyasar dashboard.', 'stg-moyasar-payments' ),
			),
			'test_missing' => array(
				'type' => 'error',
				'text' => __( 'There is no secret key saved for this environment, so there was nothing to test.', 'stg-moyasar-payments' ),
			),
			'test_offline' => array(
				'type' => 'error',
				'text' => __( 'Moyasar could not be reached from this server. The keys were not tested; try again in a moment.', 'stg-moyasar-payments' ),
			),
		);
	}

	/**
	 * General tab.
	 *
	 * @param string $mode Active mode.
	 * @return void
	 */
	private function render_general( string $mode ): void {
		$locked = Fake_Client::is_installed() || defined( 'STG_MOYASAR_MODE' );
		$reason = '';

		if ( Fake_Client::is_installed() ) {
			$reason = __( 'A local test double is installed, so the environment cannot be changed here. Remove the STG_MOYASAR_FAKE constant from wp-config.php to use the real provider.', 'stg-moyasar-payments' );
		} elseif ( defined( 'STG_MOYASAR_MODE' ) ) {
			$reason = __( 'The environment is fixed by the STG_MOYASAR_MODE constant in wp-config.php and cannot be changed here.', 'stg-moyasar-payments' );
		}

		$plans = array();

		foreach ( Plans::all() as $plan ) {
			if ( Plans::FREE !== $plan['slug'] ) {
				$plans[] = $plan;
			}
		}

		View::render(
			'settings/general',
			array(
				'enabled'            => (bool) Settings::get( 'enabled', false ),
				'mode'               => $mode,
				'mode_locked'        => $locked,
				'mode_locked_reason' => $reason,
				'ready_for_live'     => Health::ready_for_live(),
				'preflight'          => Health::preflight(),
				'plans'              => $plans,
				'methods'            => Settings::available_methods(),
				'methods_on'         => Settings::methods(),
				'networks'           => Settings::available_networks(),
				'networks_on'        => Settings::supported_networks(),
				'currencies'         => Settings::optional_currencies(),
				'currencies_on'      => Settings::currencies(),
				'post_url'           => admin_url( 'admin-post.php' ),
			)
		);
	}

	/**
	 * Credentials tab.
	 *
	 * @param string $mode Active mode.
	 * @return void
	 */
	private function render_credentials( string $mode ): void {
		View::render(
			'settings/credentials',
			array(
				'mode'        => $mode,
				'tab'         => 'credentials',
				'can_test'    => Credentials::complete_for( $mode ) || Mode::FAKE === $mode,
				'post_url'    => admin_url( 'admin-post.php' ),
				'enabled'     => (bool) Settings::get( 'enabled', false ),
				'removing'    => $this->removing( array( 'test_publishable', 'test_secret', 'live_publishable', 'live_secret' ) ),
				'cancel_url'  => $this->url( '', array( 'tab' => 'credentials' ) ),
				'test_fields' => array(
					$this->field( 'test_publishable', __( 'Publishable key', 'stg-moyasar-payments' ), $mode ),
					$this->field( 'test_secret', __( 'Secret key', 'stg-moyasar-payments' ), $mode ),
				),
				'live_fields' => array(
					$this->field( 'live_publishable', __( 'Publishable key', 'stg-moyasar-payments' ), $mode ),
					$this->field( 'live_secret', __( 'Secret key', 'stg-moyasar-payments' ), $mode ),
				),
			)
		);
	}

	/**
	 * Webhooks tab.
	 *
	 * @param string $mode Active mode.
	 * @return void
	 */
	private function render_webhooks( string $mode ): void {
		$events = array();

		foreach ( Status::webhook_events() as $event => $actionable ) {
			if ( $actionable ) {
				$events[] = $event;
			}
		}

		View::render(
			'settings/webhooks',
			array(
				'endpoint' => rest_url( 'stg/v1/payments/moyasar/webhook' ),
				'https'    => is_ssl(),
				'events'   => $events,
				'status'   => Health::webhooks(),
				'tab'      => 'webhooks',
				'post_url' => admin_url( 'admin-post.php' ),
				'removing'   => $this->removing( array( 'test_webhook_secret', 'live_webhook_secret' ) ),
				'cancel_url' => $this->url( '', array( 'tab' => 'webhooks' ) ),
				'fields'     => array(
					$this->field( 'test_webhook_secret', __( 'Test webhook secret', 'stg-moyasar-payments' ), $mode ),
					$this->field( 'live_webhook_secret', __( 'Live webhook secret', 'stg-moyasar-payments' ), $mode ),
				),
			)
		);

		unset( $mode );
	}

	/**
	 * Advanced tab.
	 *
	 * @param string $mode Active mode.
	 * @return void
	 */
	private function render_advanced( string $mode ): void {
		$publishable = Mode::is_live( $mode ) ? 'live_publishable' : 'test_publishable';
		$secret      = Mode::is_live( $mode ) ? 'live_secret' : 'test_secret';
		$webhook     = Mode::is_live( $mode ) ? 'live_webhook_secret' : 'test_webhook_secret';

		View::render(
			'settings/advanced',
			array(
				'debug'       => (bool) Settings::get( 'debug', false ),
				'timeout'     => (int) Settings::get( 'api_timeout', 15 ),
				'ttl'         => (int) Settings::get( 'intent_ttl_minutes', 30 ),
				'post_url'    => admin_url( 'admin-post.php' ),
				'can_test'    => Credentials::complete_for( $mode ) || Mode::FAKE === $mode,
				'diagnostics' => array(
					__( 'Environment', 'stg-moyasar-payments' )          => Mode::label( $mode ),
					__( 'Publishable key', 'stg-moyasar-payments' )      => Credentials::describe( $publishable ),
					__( 'Secret key', 'stg-moyasar-payments' )           => Credentials::describe( $secret ),
					__( 'Webhook secret', 'stg-moyasar-payments' )       => Credentials::describe( $webhook ),
					__( 'Last Moyasar API call', 'stg-moyasar-payments' ) => View::ago( (int) get_option( Health::LAST_API, 0 ) ),
					__( 'Last webhook received', 'stg-moyasar-payments' ) => View::ago( (int) get_option( Health::LAST_WEBHOOK, 0 ) ),
					__( 'Plugin version', 'stg-moyasar-payments' )       => STG_MOYASAR_VERSION,
				),
			)
		);
	}

	/**
	 * Build one credential's view data.
	 *
	 * Everything the card needs and nothing of the value: the mask is fixed
	 * text, and `source` is what decides between a box, a masked card with a
	 * Remove button, and a card that says the server holds the value.
	 *
	 * @param string $field Field key.
	 * @param string $label Label.
	 * @param string $mode  Active mode.
	 * @return array<string, mixed>
	 */
	private function field( string $field, string $label, string $mode = '' ): array {
		$mode   = '' !== $mode ? $mode : Settings::mode();
		$env    = str_starts_with( $field, 'live_' ) ? Mode::LIVE : Mode::TEST;
		$active = Mode::is_live( $mode ) === Mode::is_live( $env ) && Mode::FAKE !== $mode;

		return array(
			'field'    => $field,
			'label'    => $label,
			'name'     => Credentials::label( $field ),
			'prefix'   => Credentials::prefix( $field ),
			'constant' => Credentials::fields()[ $field ] ?? '',
			'source'   => Credentials::source( $field ),
			'mask'     => Credentials::mask( $field ),
			'secret'   => in_array( $field, Credentials::secret_fields(), true ),
			'active'   => $active,
			'impact'   => $this->removal_impact( $field, $active ),
			'remove'   => $this->url(
				'',
				array(
					'tab'    => str_contains( $field, 'webhook' ) ? 'webhooks' : 'credentials',
					'remove' => $field,
				)
			) . '#stg-pay-remove',
		);
	}

	/**
	 * What removing a credential will do, in words, for the confirmation.
	 *
	 * Said before the button is pressed, because afterwards is too late: the
	 * value is not kept anywhere and comes back only from Moyasar.
	 *
	 * @param string $field  Field key.
	 * @param bool   $active Whether the field belongs to the environment in use.
	 * @return string
	 */
	private function removal_impact( string $field, bool $active ): string {
		if ( str_contains( $field, 'webhook' ) ) {
			return $active
				? __( 'Webhooks from Moyasar will be refused until a new secret is saved. Payments still complete through the customer’s return and the hourly check, only more slowly.', 'stg-moyasar-payments' )
				: __( 'This environment is not in use, so nothing changes for customers now.', 'stg-moyasar-payments' );
		}

		if ( ! $active ) {
			return __( 'This environment is not in use, so nothing changes for customers now.', 'stg-moyasar-payments' );
		}

		$impact = __( 'This environment is in use. Without both of its keys the checkout is not offered, so nobody can buy a plan until a new key is saved.', 'stg-moyasar-payments' );

		if ( str_ends_with( $field, '_secret' ) ) {
			$open = \STG\Moyasar\Payments\Repository::count_by_status( Status::PENDING );

			if ( $open > 0 ) {
				$impact .= ' ' . sprintf(
					/* translators: %d: number of payments */
					_n(
						'%d payment is still awaiting confirmation and cannot be checked with Moyasar until a secret key is saved.',
						'%d payments are still awaiting confirmation and cannot be checked with Moyasar until a secret key is saved.',
						$open,
						'stg-moyasar-payments'
					),
					$open
				);
			}
		}

		return $impact;
	}

	/**
	 * The credential a no-script Remove link asked to confirm, if it may be.
	 *
	 * @param string[] $allowed Field keys shown on this tab.
	 * @return array<string, mixed>|null
	 */
	private function removing( array $allowed ): ?array {
		$field = sanitize_key( $this->query( 'remove' ) );

		if ( ! in_array( $field, $allowed, true ) || 'database' !== Credentials::source( $field ) ) {
			return null;
		}

		return $this->field( $field, Credentials::label( $field ) );
	}

	/**
	 * Save the settings form.
	 *
	 * One handler for all four tabs, because they post to the same action and
	 * only ever submit their own fields. `isset()` guards mean a tab that does
	 * not include a field leaves that setting alone rather than clearing it —
	 * which is the bug you get from a naive "read everything from $_POST".
	 *
	 * @return void
	 */
	public function save(): void {
		$this->require_permission();
		check_admin_referer( 'stg_moyasar_save' );

		$tab = sanitize_key( isset( $_POST['tab'] ) ? wp_unslash( (string) $_POST['tab'] ) : 'general' );

		if ( 'general' === $tab ) {
			Settings::set( 'enabled', ! empty( $_POST['enabled'] ) );

			$methods = isset( $_POST['methods'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['methods'] ) ) : array();
			Settings::set( 'methods', array_values( array_intersect( $methods, array_keys( Settings::available_methods() ) ) ) );

			$networks = isset( $_POST['supported_networks'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['supported_networks'] ) ) : array();
			Settings::set( 'supported_networks', array_values( array_intersect( $networks, array_keys( Settings::available_networks() ) ) ) );

			$currencies = isset( $_POST['currencies'] ) ? array_map( static fn( $c ): string => strtoupper( sanitize_key( (string) $c ) ), (array) wp_unslash( $_POST['currencies'] ) ) : array();
			Settings::set( 'currencies', array_values( array_intersect( $currencies, Settings::optional_currencies() ) ) );

			$this->save_prices();
		}

		if ( 'advanced' === $tab ) {
			Settings::set( 'debug', ! empty( $_POST['debug'] ) );

			$timeout = isset( $_POST['api_timeout'] ) ? (int) $_POST['api_timeout'] : 15;
			Settings::set( 'api_timeout', max( 5, min( 30, $timeout ) ) );

			$ttl = isset( $_POST['intent_ttl_minutes'] ) ? (int) $_POST['intent_ttl_minutes'] : 30;
			Settings::set( 'intent_ttl_minutes', max( 5, min( 120, $ttl ) ) );
		}

		$result = $this->save_credentials();

		/*
		 * `info`, not `warning`.
		 *
		 * An administrator saving a settings tab is an audit event, not a
		 * problem, and the phase gate fails on anything above `info` - so
		 * logging it as a warning meant every legitimate save turned the gate
		 * amber and taught whoever ran it to ignore that line. The severity
		 * that matters is reserved for the events that deserve it: a refused
		 * credential below, and the switch to live mode, which stays
		 * `critical`.
		 */
		Log::info(
			'Payment settings were changed.',
			array(
				'user'  => get_current_user_id(),
				'tab'   => $tab,
				'saved' => implode( ',', $result['saved'] ),
			)
		);

		if ( in_array( $tab, array( 'credentials', 'webhooks' ), true ) || $result['refused'] ) {
			// Codes and field names only; the screen turns them into words.
			set_transient( self::RESULT . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );

			$this->redirect_back( 'keys', '', array( 'tab' => in_array( $tab, array( 'credentials', 'webhooks' ), true ) ? $tab : 'credentials' ) );
		}

		$this->redirect_back( 'saved', '', array( 'tab' => $tab ) );
	}

	/**
	 * Write plan prices back into STG Core's plan configuration.
	 *
	 * Into Core's own store rather than a second one, so there is a single
	 * answer to "what does this plan cost" and `Plans::is_purchasable()` keeps
	 * deciding whether checkout can open.
	 *
	 * @return void
	 */
	private function save_prices(): void {
		if ( ! isset( $_POST['prices'] ) || ! is_array( $_POST['prices'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each element is sanitized in the loop.
		$submitted = (array) wp_unslash( $_POST['prices'] );
		$stored    = (array) \STG\Core\Modules\Settings::get( 'plans', array() );
		$out       = array();

		foreach ( Plans::all() as $slug => $plan ) {
			$price = $plan['price'];

			if ( array_key_exists( $slug, $submitted ) ) {
				$raw = trim( sanitize_text_field( (string) $submitted[ $slug ] ) );

				/*
				 * Empty means unpriced, not free.
				 *
				 * `null` and `0` are different statements: one says "we have
				 * not decided", the other says "this costs nothing". The
				 * pricing page renders them completely differently, and
				 * `Plans::is_purchasable()` treats only the second as buyable.
				 */
				if ( '' === $raw ) {
					$price = null;
				} else {
					// Minor units, like every other amount in STG.
					$price = (int) round( (float) str_replace( ',', '', $raw ) * 100 );

					if ( $price < 1 ) {
						$price = null;
					}
				}
			}

			/*
			 * Only the price is this screen's to change.
			 *
			 * Everything else about a plan is edited in STG, Plans, and is
			 * kept exactly as stored. This used to write four fields per plan
			 * and nothing else, so saving a price here erased the names, the
			 * Arabic copy, the feature lines and the order entered there, and
			 * made every hidden plan visible again.
			 *
			 * The stored row is carried over, not `$plan`: `Plans::all()` has
			 * already translated the name and description into the language of
			 * whoever is saving, and storing that would freeze them in the
			 * admin's language for every visitor (P13e-03). A plan with no
			 * stored row yet gets the four fields it always did, and Core
			 * supplies the shipped, translated copy for the rest.
			 */
			$row = isset( $stored[ $slug ] ) && is_array( $stored[ $slug ] )
				? $stored[ $slug ]
				: array(
					'currency'     => $plan['currency'],
					'interval'     => $plan['interval'],
					'entitlements' => $plan['entitlements'],
				);

			$row['price'] = $price;
			$out[ $slug ] = $row;
		}

		\STG\Core\Modules\Settings::set( 'plans', $out );
	}

	/**
	 * Validate and store submitted credentials.
	 *
	 * The values are taken as typed: `sanitize_text_field()` strips anything
	 * shaped like a percent-encoded byte or a tag, which silently changes a
	 * secret and leaves a webhook that can never authenticate. `Credentials`
	 * refuses what does not belong instead of rewriting it.
	 *
	 * @return array{saved: string[], refused: array<string, string>, locked: string[]}
	 */
	private function save_credentials(): array {
		$submitted = array();

		foreach ( array_keys( Credentials::fields() ) as $field ) {
			if ( isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A credential must be stored exactly as issued; Credentials::submit() validates it and never echoes it.
				$submitted[ $field ] = wp_unslash( $_POST[ $field ] );
			}
		}

		$result = Credentials::submit( $submitted );

		foreach ( $result['refused'] as $field => $code ) {
			$value = Credentials::clean( (string) $submitted[ $field ] );

			// What was wrong, never what was typed.
			Log::warning(
				'Refused a credential that failed validation. The value was discarded.',
				array(
					'field'  => $field,
					'reason' => $code,
					'length' => strlen( $value ),
					'starts' => preg_match( '/^((?:pk|sk)_(?:test|live))_/', $value, $matches ) ? $matches[1] : 'other',
				)
			);
		}

		return $result;
	}

	/**
	 * Say what happened to each submitted credential.
	 *
	 * One line per field, because "some values were refused" left an
	 * administrator with two masked boxes and no way to tell which one, or why.
	 *
	 * @return void
	 */
	private function print_key_result(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads a key set by our own redirect and renders a result stored for this user.
		if ( ! isset( $_GET['stg_notice'] ) || 'keys' !== sanitize_key( wp_unslash( (string) $_GET['stg_notice'] ) ) ) {
			return;
		}

		$name   = self::RESULT . get_current_user_id();
		$result = get_transient( $name );

		delete_transient( $name );

		if ( ! is_array( $result ) ) {
			return;
		}

		$types = array(
			'test_publishable' => array( 'pk', Mode::TEST ),
			'test_secret'      => array( 'sk', Mode::TEST ),
			'live_publishable' => array( 'pk', Mode::LIVE ),
			'live_secret'      => array( 'sk', Mode::LIVE ),
		);

		$known = Credentials::fields();
		$lines = array();

		foreach ( (array) ( $result['refused'] ?? array() ) as $field => $code ) {
			if ( ! isset( $known[ $field ] ) ) {
				continue;
			}

			$why = isset( $types[ $field ] )
				? Credentials::refusal_message( (string) $code, $types[ $field ][0], $types[ $field ][1] )
				: Credentials::refusal_message( (string) $code, '', '' );

			$kept = '' !== Credentials::get( (string) $field )
				? __( 'The value saved before is unchanged.', 'stg-moyasar-payments' )
				: __( 'Nothing is saved for this field.', 'stg-moyasar-payments' );

			$lines[] = array( 'error', Credentials::label( (string) $field ) . ': ' . $why . ' ' . $kept );
		}

		foreach ( (array) ( $result['locked'] ?? array() ) as $field ) {
			if ( isset( $known[ $field ] ) ) {
				$lines[] = array( 'warning', Credentials::label( (string) $field ) . ': ' . __( 'This value is set in wp-config.php and was not changed here.', 'stg-moyasar-payments' ) );
			}
		}

		$saved = array();

		foreach ( (array) ( $result['saved'] ?? array() ) as $field ) {
			if ( isset( $known[ $field ] ) ) {
				$saved[] = Credentials::label( (string) $field );
			}
		}

		if ( $saved ) {
			$lines[] = array(
				'success',
				sprintf(
					/* translators: %s: list of credential names */
					__( 'Saved: %s. Saving a key does not prove it works; use Test the connection on the Advanced tab.', 'stg-moyasar-payments' ),
					implode( ', ', $saved )
				),
			);
		}

		if ( ! $lines ) {
			$lines[] = array( 'info', __( 'Nothing was entered, so nothing changed.', 'stg-moyasar-payments' ) );
		}

		foreach ( $lines as $line ) {
			printf(
				'<div class="notice notice-%1$s"><p>%2$s</p></div>',
				esc_attr( $line[0] ),
				esc_html( $line[1] )
			);
		}
	}

	/**
	 * Switch between test and live.
	 *
	 * @return void
	 */
	public function switch_mode(): void {
		$this->require_permission();
		check_admin_referer( 'stg_moyasar_mode' );

		$target = Mode::normalise( isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['mode'] ) ) : '' );

		if ( Mode::TEST === $target ) {
			Settings::set( 'mode', Mode::TEST );

			Log::warning( 'Payments switched to TEST mode.', array( 'user' => get_current_user_id() ) );

			$this->redirect_back( 'mode_test', '', array( 'tab' => 'general' ) );
		}

		$confirm = isset( $_POST['confirm'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['confirm'] ) ) ) : '';

		if ( 'LIVE' !== $confirm || ! Health::ready_for_live() ) {
			$this->redirect_back( 'mode_blocked', '', array( 'tab' => 'general' ) );
		}

		Settings::set( 'mode', Mode::LIVE );

		/*
		 * Critical rather than warning. This is the single most consequential
		 * switch in the application, and the log line naming who threw it is
		 * what an audit asks for first.
		 */
		Log::critical(
			'Payments switched to LIVE mode. Real cards can now be charged.',
			array( 'user' => get_current_user_id() )
		);

		$this->redirect_back( 'mode_live', '', array( 'tab' => 'general' ) );
	}

	/**
	 * Test the API connection.
	 *
	 * @return void
	 */
	public function test_connection(): void {
		$this->require_permission();
		check_admin_referer( 'stg_moyasar_test' );

		$result = Plugin::instance()->provider()->ping();

		$notice = 'test_ok';

		if ( is_wp_error( $result ) ) {
			$known  = array(
				'stg_moyasar_unauthorised'   => 'test_refused',
				'stg_moyasar_not_configured' => 'test_missing',
				'stg_moyasar_unreachable'    => 'test_offline',
			);
			$notice = $known[ (string) $result->get_error_code() ] ?? 'test_failed';
		}

		// The button sits on two tabs; answer on the one it was pressed on.
		$return = isset( $_POST['return'] ) ? sanitize_key( wp_unslash( (string) $_POST['return'] ) ) : '';

		$this->redirect_back( $notice, '', array( 'tab' => 'credentials' === $return ? 'credentials' : 'advanced' ) );
	}

	/**
	 * Remove one saved credential.
	 *
	 * The only way to clear a value: an empty box means "unchanged", so
	 * without this a key entered by mistake - a live key on a test site, say -
	 * could not be taken back out from the screen.
	 *
	 * @return void
	 */
	public function forget_key(): void {
		$this->require_permission();
		check_admin_referer( 'stg_moyasar_forget' );

		$field = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( (string) $_POST['field'] ) ) : '';
		$tab   = isset( $_POST['tab'] ) && 'webhooks' === sanitize_key( wp_unslash( (string) $_POST['tab'] ) ) ? 'webhooks' : 'credentials';

		/*
		 * Only what the database holds can be removed here. A value fixed in
		 * wp-config.php would still be in force afterwards, and saying it had
		 * been removed would be a lie about which key takes payments.
		 */
		if ( ! array_key_exists( $field, Credentials::fields() ) || 'database' !== Credentials::source( $field ) ) {
			$this->redirect_back( 'key_kept', '', array( 'tab' => $tab ) );
		}

		// The confirmation step posts this; a request without it never saw one.
		if ( empty( $_POST['confirm'] ) ) {
			$this->redirect_back( 'key_unconfirmed', '', array( 'tab' => $tab ) );
		}

		Credentials::forget( $field );
		wp_cache_delete( Credentials::OPTION, 'options' );

		$removed = 'none' === Credentials::source( $field );

		/*
		 * Who, which field, which environment, and whether it was in use.
		 * Never the value. Warning when the environment in use lost a key,
		 * because that is the moment the checkout stopped being offered.
		 */
		$context = array(
			'user'    => get_current_user_id(),
			'field'   => $field,
			'removed' => $removed,
			'mode'    => Settings::mode(),
		);

		if ( $removed && ! str_contains( $field, 'webhook' ) && Mode::is_live( Settings::mode() ) === str_starts_with( $field, 'live_' ) && Settings::get( 'enabled', false ) ) {
			Log::warning( 'A payment key for the environment in use was removed. Checkout is not offered until it is replaced.', $context );
		} else {
			Log::info( 'A saved payment credential was removed.', $context );
		}

		$this->redirect_back( $removed ? 'key_removed' : 'key_kept', '', array( 'tab' => $tab ) );
	}
}
