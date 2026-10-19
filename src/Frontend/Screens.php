<?php
/**
 * The customer-facing screens.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar\Frontend;

use STG\Core\Modules\Routing;
use STG\Core\Support\Rate_Limiter;
use STG\Core\Subscriptions\Plans;
use STG\Moyasar\Credentials;
use STG\Moyasar\Mode;
use STG\Moyasar\Payments\Intents;
use STG\Moyasar\Payments\Repository;
use STG\Moyasar\Payments\Settlement;
use STG\Moyasar\Payments\Status;
use STG\Moyasar\Settings;
use STG\Moyasar\Support\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pricing, checkout and the return screen.
 *
 * STG Core owns which routes exist; the theme owns what routes look like. This
 * class sits between them: it supplies a template for three routes Core
 * registered, and the theme can override any of them by dropping a file of the
 * same name into `templates/payments/`. That keeps the project's existing split
 * intact rather than inventing a third convention.
 *
 * **The theme is not edited by this plugin.** `/account/…` is already an
 * application-screen prefix in the theme's own list, so checkout inherits the
 * asset policy - Elementor dequeued, STG tokens loaded - for free. `/pricing/`
 * is added through the theme's existing `hello_stg_app_routes` filter.
 */
final class Screens {

	/**
	 * Routes this plugin renders.
	 *
	 * @var array<string, string>
	 */
	private const TEMPLATES = array(
		'pricing'         => 'pricing.php',
		'checkout'        => 'checkout.php',
		'checkout-return' => 'checkout-return.php',
	);

	/**
	 * The Moyasar payment form release loaded on checkout.
	 *
	 * Latest published as of 2026-09-23. Check that
	 * `https://cdn.moyasar.com/mpf/<version>/moyasar.js` answers 200 before
	 * changing it - unpublished versions answer 403, not 404.
	 *
	 * @var string
	 */
	private const MOYASAR_FORM_VERSION = '1.19.0';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// 25, after the theme's own template_include at 20.
		add_filter( 'template_include', array( $this, 'template' ), 25 );
		add_filter( 'hello_stg_app_routes', array( $this, 'app_routes' ) );
		add_action( 'template_redirect', array( $this, 'guard' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ), 25 );
		add_action( 'admin_post_stg_moyasar_checkout', array( $this, 'start_checkout' ) );
		// A member's form: Core keeps members out of admin-post.php otherwise.
		add_filter( 'stg_core_member_admin_post_actions', static fn( array $actions ): array => array_merge( $actions, array( 'stg_moyasar_checkout' ) ) );

		// The plan cards as an Elementor block for the Pricing layout (D-060).
		add_action( 'elementor/widgets/register', array( $this, 'elementor_widget' ) );
		add_filter( 'hello_stg_scriptless_widgets', array( $this, 'scriptless' ) );
	}

	/**
	 * Register the pricing widget with Elementor.
	 *
	 * @param \Elementor\Widgets_Manager $widgets Widgets manager.
	 * @return void
	 */
	public function elementor_widget( $widgets ): void {
		$widgets->register( new Pricing_Widget() );
	}

	/**
	 * The pricing widget runs no JavaScript; the theme may drop Elementor's.
	 *
	 * @param mixed $widgets Script-free widget names.
	 * @return array<int, string>
	 */
	public function scriptless( $widgets ): array {
		$widgets   = is_array( $widgets ) ? $widgets : array();
		$widgets[] = 'stg-pricing-plans';

		return $widgets;
	}

	/**
	 * Open an intent from the pricing page and send the customer to pay.
	 *
	 * Registered only for signed-in users - there is no `nopriv` variant, so an
	 * anonymous POST gets WordPress's own refusal rather than reaching this
	 * method at all.
	 *
	 * @return void
	 */
	public function start_checkout(): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( home_url( '/account/login/' ) );
			exit;
		}

		check_admin_referer( 'stg_moyasar_checkout' );

		if ( ! current_user_can( 'stg_manage_subscription_own' ) ) {
			wp_safe_redirect( home_url( '/pricing/' ) );
			exit;
		}

		/*
		 * A modest ceiling on how many checkouts one account may open.
		 *
		 * The unique index already means a user cannot hold two open intents
		 * for the same plan, so this is not about duplicates - it is about
		 * somebody cycling through plans in a loop to make the site create rows
		 * and, in live mode, to make Moyasar create payments.
		 */
		$attempt = Rate_Limiter::attempt(
			'moyasar.checkout',
			Rate_Limiter::identity(),
			15,
			HOUR_IN_SECONDS
		);

		if ( ! $attempt['allowed'] ) {
			wp_safe_redirect( add_query_arg( 'slow_down', '1', home_url( '/pricing/' ) ) );
			exit;
		}

		$plan = isset( $_POST['plan'] ) ? sanitize_key( wp_unslash( (string) $_POST['plan'] ) ) : '';

		$intent = Intents::open( get_current_user_id(), $plan );

		if ( is_wp_error( $intent ) ) {
			wp_safe_redirect(
				add_query_arg( 'unavailable', '1', home_url( '/pricing/' ) )
			);
			exit;
		}

		wp_safe_redirect( Intents::checkout_url( (string) $intent['public_ref'] ) );
		exit;
	}

	/**
	 * Treat the pricing page as an STG application screen.
	 *
	 * Through the theme's filter rather than by editing the theme, so the
	 * payment plugin owns its own presentation policy and removing the plugin
	 * removes it cleanly.
	 *
	 * @param array<int, string> $routes Path prefixes.
	 * @return array<int, string>
	 */
	public function app_routes( $routes ): array {
		$routes   = is_array( $routes ) ? $routes : array();
		$routes[] = 'pricing';

		return $routes;
	}

	/**
	 * The current STG route, if this plugin renders it.
	 *
	 * @return string
	 */
	private function route(): string {
		if ( ! class_exists( Routing::class ) ) {
			return '';
		}

		$route = Routing::current();

		return isset( self::TEMPLATES[ $route ] ) ? $route : '';
	}

	/**
	 * Supply a template for our routes.
	 *
	 * @param string $template Template WordPress resolved.
	 * @return string
	 */
	public function template( $template ) {
		$route = $this->route();

		if ( '' === $route ) {
			return $template;
		}

		/*
		 * The theme gets first refusal.
		 *
		 * `locate_template()` checks the child theme then the parent, so a
		 * designer can restyle the checkout without touching the plugin - which
		 * is the same arrangement every other STG screen already has.
		 */
		$override = locate_template( array( 'templates/payments/' . self::TEMPLATES[ $route ] ) );

		if ( $override ) {
			return $override;
		}

		$candidate = STG_MOYASAR_DIR . 'templates/' . self::TEMPLATES[ $route ];

		return file_exists( $candidate ) ? $candidate : $template;
	}

	/**
	 * Keep these pages out of caches and away from the wrong people.
	 *
	 * @return void
	 */
	public function guard(): void {
		$route = $this->route();

		if ( '' === $route ) {
			return;
		}

		/*
		 * Never cached, including the pricing page.
		 *
		 * Pricing shows the visitor's current plan, and checkout shows a
		 * payment in progress. A page cache that served one customer's payment
		 * state to another would be the worst kind of bug to find in production,
		 * and `nocache_headers()` is what most WordPress caching layers and CDNs
		 * actually honour.
		 */
		nocache_headers();

		if ( 'pricing' === $route ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect(
				add_query_arg(
					'redirect_to',
					rawurlencode( home_url( add_query_arg( array() ) ) ),
					home_url( '/account/login/' )
				)
			);
			exit;
		}

		$reference = $this->reference();

		// No reference at all: somebody typed the path. Send them to choose.
		if ( '' === $reference ) {
			wp_safe_redirect( home_url( '/pricing/' ) );
			exit;
		}

		$row = Repository::find_by_reference( $reference );

		/*
		 * Somebody else's payment is a 404, not a 403.
		 *
		 * A 403 confirms the reference exists, which is the existence oracle
		 * STG Core's REST base class was written to prevent. The same reasoning
		 * applies to a page.
		 */
		if ( ! $row || (int) $row['user_id'] !== get_current_user_id() ) {
			if ( function_exists( 'hello_stg_render_404' ) ) {
				hello_stg_render_404();
				exit;
			}

			wp_safe_redirect( home_url( '/pricing/' ) );
			exit;
		}

		if ( 'checkout-return' === $route ) {
			$this->settle_return( $row );

			return;
		}

		// An intent that is already finished has nothing left to pay.
		if ( Status::PENDING !== (string) $row['status'] ) {
			wp_safe_redirect( Intents::return_url( $reference ) );
			exit;
		}

		if ( strtotime( (string) $row['expires_at'] . ' UTC' ) < time() ) {
			wp_safe_redirect( add_query_arg( 'expired', '1', home_url( '/pricing/' ) ) );
			exit;
		}
	}

	/**
	 * Verify the payment the customer has just come back from.
	 *
	 * Runs on `template_redirect`, so the template renders a settled row rather
	 * than doing the work itself. The query string is read for one thing only -
	 * which payment id to go and ask the provider about - and its `status`
	 * parameter is ignored entirely.
	 *
	 * @param array<string, mixed> $row Payment row.
	 * @return void
	 */
	private function settle_return( array $row ): void {
		if ( in_array( (string) $row['status'], Status::terminal(), true ) ) {
			// Already settled, by this channel or the webhook. Refreshing the
			// page must not re-run anything.
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A provider redirect cannot carry a nonce; the id is verified against the provider's API, which is the actual authentication.
		$payment_id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['id'] ) ) : '';

		if ( '' === $payment_id ) {
			/*
			 * No id means the customer came back without completing - closed
			 * the provider's page, or pressed back. Nothing has been charged
			 * and the intent stays open so they can try again.
			 */
			return;
		}

		Settlement::settle( $payment_id, (string) $row['public_ref'], 'callback' );
	}

	/**
	 * The payment reference on this request.
	 *
	 * @return string
	 */
	public function reference(): string {
		if ( ! class_exists( Routing::class ) ) {
			return '';
		}

		$slug = Routing::current_slug();

		return preg_match( '/^[a-f0-9]{32}$/', $slug ) ? $slug : '';
	}

	/**
	 * Load assets, only where they are used.
	 *
	 * The single most important performance rule in this project is that
	 * application screens do not pay for things they do not use. Moyasar's
	 * script is third-party JavaScript on the page where money is taken, and it
	 * loads on that page and nowhere else.
	 *
	 * **On loading a third-party script here at all:** it is the accepted price
	 * of staying out of PCI SAQ-D. The card details go from the customer's
	 * browser straight to Moyasar and never touch this server, which is only
	 * possible because Moyasar's own code collects them. The form is pinned to
	 * one published version (`MOYASAR_FORM_VERSION`), so upgrading it is a
	 * deliberate one-line change that gets tested, not something that happens
	 * to the checkout on Moyasar's schedule.
	 *
	 * @return void
	 */
	public function assets(): void {
		$route = $this->route();

		if ( '' === $route ) {
			return;
		}

		/*
		 * Declared dependencies, not a priority race.
		 *
		 * This used to enqueue with `array()` at priority 25 and rely on
		 * running after the theme's 20. That is not a guarantee - it is an
		 * ordering that happens to hold until someone changes a priority - and
		 * while it held, this stylesheet loaded last and was in a position to
		 * silently restyle theme components. Naming the handles makes
		 * WordPress emit ours after theirs whatever the priorities are, and
		 * makes the intent readable: this file extends the theme, it does not
		 * compete with it.
		 *
		 * Only handles that are actually registered are named. The theme gates
		 * `stg-app` behind its own app-screen check, and listing a handle that
		 * does not exist makes WordPress drop the whole stylesheet - the same
		 * trap the theme documents against the parent's conditional handles.
		 */
		$deps = array_values( array_filter( array( 'stg-tokens', 'stg-app' ), 'wp_style_is' ) );

		wp_enqueue_style(
			'stg-moyasar',
			STG_MOYASAR_URL . 'assets/css/checkout.css',
			$deps,
			STG_MOYASAR_VERSION
		);

		if ( 'pricing' === $route ) {
			return;
		}

		/*
		 * The script loads on both checkout and the return screen.
		 *
		 * On checkout it mounts the payment form; on the return screen it polls
		 * while a payment is still confirming. Moyasar's own script, below,
		 * loads on the checkout page ONLY - there is no form to mount on the
		 * return screen, and third-party JavaScript should not be on a page
		 * that has no use for it.
		 */
		wp_enqueue_script(
			'stg-moyasar-checkout',
			STG_MOYASAR_URL . 'assets/js/checkout.js',
			array(),
			STG_MOYASAR_VERSION,
			true
		);

		if ( 'checkout' !== $route ) {
			return;
		}

		$reference = $this->reference();
		$row       = '' !== $reference ? Repository::find_by_reference( $reference ) : null;

		if ( ! $row || Status::PENDING !== (string) $row['status'] ) {
			return;
		}

		$mode        = (string) $row['mode'];
		$publishable = Credentials::publishable( $mode );

		/*
		 * The last check before a key reaches a browser.
		 *
		 * Moyasar documents the publishable key as safe in client code, so this
		 * is not about the key being secret. It is about the key being the RIGHT
		 * key: a secret key pasted into the publishable field, or a live key
		 * while the row says test, must not be printed into a page. The settings
		 * screen refuses both on save; this refuses them on the way out.
		 */
		if ( Mode::FAKE !== $mode && ! Credentials::assert_shape( $publishable, 'pk', $mode ) ) {
			Log::critical(
				'Refused to render the checkout form: the publishable key is missing or belongs to another mode.',
				array( 'mode' => $mode )
			);

			return;
		}

		/*
		 * Moyasar's own form, script and stylesheet, from a versioned path.
		 *
		 * There is no unversioned URL: `cdn.moyasar.com/moyasar.js` answers
		 * 403, and this line used to point at it, so every checkout showed the
		 * "could not be loaded" fallback. The version is in the path, which is
		 * also why no `ver` query string is added. See P13e-01.
		 */
		$cdn = 'https://cdn.moyasar.com/mpf/' . self::MOYASAR_FORM_VERSION . '/';

		wp_enqueue_style( 'moyasar', $cdn . 'moyasar.css', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- The version is in the path.
		wp_enqueue_script( 'moyasar', $cdn . 'moyasar.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- The version is in the path.

		/*
		 * Ours must run after theirs, or `Moyasar.init` is not defined yet.
		 *
		 * Through the registered dependency list itself. This used to call
		 * `add_data( …, 'deps', … )`, which only writes to `->extra` and
		 * changes nothing about print order - so checkout.js printed first
		 * and fell back even when Moyasar's script had loaded. See P13e-02.
		 */
		$script = wp_scripts()->query( 'stg-moyasar-checkout' );

		if ( $script ) {
			$script->deps[] = 'moyasar';
		}

		$plan = Plans::find( (string) $row['plan_slug'] );

		wp_localize_script(
			'stg-moyasar-checkout',
			'STG_MOYASAR',
			array(
				'publishableKey'    => $publishable,
				// From the stored intent, never from a fresh price lookup. A
				// price edited mid-checkout must not change what this customer
				// was quoted - and settlement compares against this same row.
				'amount'            => (int) $row['amount'],
				'currency'          => (string) $row['currency'],
				'description'       => $plan
					? sprintf(
						/* translators: %s: plan name */
						__( 'STG subscription — %s', 'stg-moyasar-payments' ),
						(string) $plan['name']
					)
					: __( 'STG subscription', 'stg-moyasar-payments' ),
				'callbackUrl'       => Intents::return_url( $reference ),
				// Where the form reports the payment it created, before the
				// customer leaves for their bank's page.
				'attemptUrl'        => rest_url( 'stg/v1/me/payments/' . $reference . '/attempt' ),
				'restNonce'         => wp_create_nonce( 'wp_rest' ),
				// The correlation key. This is what binds a Moyasar payment to
				// an STG customer, and it is what settlement trusts above
				// anything a browser or a webhook body claims.
				'metadata'          => array( 'stg_ref' => $reference ),
				// Never a wallet the form cannot start with: see form_methods().
				'methods'           => Settings::form_methods(),
				'supportedNetworks' => Settings::supported_networks(),
				// Passed explicitly rather than left to Moyasar's inference
				// from <html lang>. Inference is behaviour that can change.
				'language'          => $this->language(),
				'mode'              => $mode,
				'i18n'              => array(
					'processing' => __( 'Processing your payment…', 'stg-moyasar-payments' ),
					'failed'     => __( 'That payment could not be completed. Please check your card details and try again.', 'stg-moyasar-payments' ),
					'unavailable' => __( 'The payment form could not be loaded. Please refresh the page or contact us.', 'stg-moyasar-payments' ),
				),
			)
		);
	}

	/**
	 * The language to render the payment form in.
	 *
	 * @return string
	 */
	private function language(): string {
		$locale = determine_locale();

		return str_starts_with( (string) $locale, 'ar' ) ? 'ar' : 'en';
	}
}
