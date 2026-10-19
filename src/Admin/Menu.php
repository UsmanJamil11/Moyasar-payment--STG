<?php
/**
 * The admin menu, and the assets that belong to it.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers every payment screen in one place.
 *
 * Previously each screen registered its own `admin_menu` callback, and the
 * transactions screen had to hook at priority 11 so its submenu landed after
 * the settings screen had created the parent. That works until somebody adds a
 * third screen and picks 11 again. One registrar, called once, in a fixed
 * order, removes the question.
 *
 * It also gives the stylesheet a correct condition. `admin_enqueue_scripts`
 * fires on every admin page, and the usual mistake is to compare `$_GET['page']`
 * - which is fine until an editor has a plugin that renames pages. The hook
 * suffixes returned by `add_menu_page()` are WordPress's own identifiers for
 * these screens, so comparing against them is exact, and the stylesheet cannot
 * leak onto somebody else's page.
 *
 * ## Why three items rather than six
 *
 * An administrator needs to answer: is the gateway working, what has been paid,
 * and where do I change it. That is Overview, Transactions, Settings.
 *
 * Webhooks and Diagnostics are *settings*, not destinations - they are things
 * you configure and read once, and giving each its own menu row makes the
 * sidebar longer without making anything easier to find. They are tabs.
 *
 * A Logs screen is deliberately absent. The plugin already logs through STG
 * Core's structured logger to the site's debug log, and building a log browser
 * means a reader, a paginator, a filter and a retention policy - a small
 * product, for a job `grep '[moyasar]' debug.log` already does. The Overview
 * surfaces what an administrator actually needs from logs: the last API call,
 * the last webhook, and the count of payments needing attention.
 */
final class Menu {

	/**
	 * Registered screens, in menu order.
	 *
	 * @var array<int, Screen>
	 */
	private array $screens;

	/**
	 * Hook suffixes returned by WordPress, for the asset condition.
	 *
	 * @var array<int, string>
	 */
	private array $hooks = array();

	/**
	 * Constructor.
	 *
	 * @param array<int, Screen> $screens Screens, in menu order. The first is the landing page.
	 */
	public function __construct( array $screens ) {
		$this->screens = $screens;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );

		foreach ( $this->screens as $screen ) {
			$screen->register_handlers();
		}
	}

	/**
	 * Create the menu and its submenus.
	 *
	 * @return void
	 */
	public function add_pages(): void {
		$first = $this->screens[0] ?? null;

		if ( ! $first instanceof Screen ) {
			return;
		}

		$this->hooks[] = (string) add_menu_page(
			__( 'STG Payments', 'stg-moyasar-payments' ),
			__( 'Payments', 'stg-moyasar-payments' ),
			Screen::CAPABILITY,
			$first->slug(),
			array( $first, 'render' ),
			'dashicons-money-alt',
			56
		);

		foreach ( $this->screens as $screen ) {
			$this->hooks[] = (string) add_submenu_page(
				$first->slug(),
				$screen->page_title(),
				$screen->menu_title(),
				Screen::CAPABILITY,
				$screen->slug(),
				array( $screen, 'render' )
			);
		}
	}

	/**
	 * Load the stylesheet, on this plugin's screens only.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function assets( $hook_suffix ): void {
		if ( ! in_array( (string) $hook_suffix, $this->hooks, true ) ) {
			return;
		}

		/*
		 * No declared dependency, deliberately. The stylesheet reads the
		 * administrator's colour scheme through `--wp-admin-theme-color`, which
		 * core sets on `body` - custom properties inherit down to `.stg-pay`
		 * regardless of which stylesheet loads first, so ordering is irrelevant
		 * and naming `wp-admin` here would only risk re-ordering core's own
		 * queue for nothing.
		 */
		wp_enqueue_style(
			'stg-moyasar-admin',
			STG_MOYASAR_URL . 'assets/css/admin.css',
			array(),
			STG_MOYASAR_VERSION
		);

		/*
		 * One small script, for the Remove key confirmation and the "Saving…"
		 * state on buttons. Everything it does also works without it: the
		 * Remove link opens the same confirmation as a page.
		 */
		wp_enqueue_script(
			'stg-moyasar-admin',
			STG_MOYASAR_URL . 'assets/js/admin.js',
			array(),
			STG_MOYASAR_VERSION,
			true
		);

		/*
		 * That script makes no request of its own, and that is worth stating.
		 *
		 * Everything these screens do is a form submission: saving settings,
		 * switching mode, testing the connection, re-checking a payment. Each
		 * is a POST to `admin-post.php` that redirects back with a message.
		 * Doing any of it over AJAX would mean a REST route or an ajax action,
		 * a nonce in JavaScript, a spinner, an error path and a way for the
		 * page to disagree with the database - in exchange for not repainting
		 * a page that repaints in under a hundred milliseconds.
		 *
		 * The one place a script would earn its place is copying the webhook
		 * URL to the clipboard, and a readonly input the browser can select
		 * natively does that without any.
		 */
	}
}
