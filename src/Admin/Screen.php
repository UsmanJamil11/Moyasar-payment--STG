<?php
/**
 * Base class for the payment admin screens.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The shared shape of an admin screen, and the checks none of them may skip.
 *
 * Two deliberate architectural choices, restated here because both look like
 * omissions to anyone expecting the usual WordPress plugin.
 *
 * **No Settings API.** `register_setting()` / `add_settings_field()` buys a
 * nonce, a referer check and `settings_errors()`, and costs more than it gives
 * on this particular screen. Its sanitize callback fires on *every*
 * `update_option` for the registered option, which is a subtle footgun when
 * the option holds API keys. "Leave blank to keep the existing secret" is
 * awkward to express through `options.php`, because the Settings API round-trips
 * the value it rendered. And a per-field "this is fixed in wp-config.php, so
 * it is read-only" state does not fit `add_settings_field()` cleanly. An
 * explicit `admin_post_` handler with an explicit capability check, an explicit
 * nonce check and explicit per-field validation is longer but says what it
 * does — and for credential handling, saying what it does is the requirement.
 *
 * **No `WP_List_Table`.** It is marked `@access private` in core, its API has
 * shifted between releases, and the transactions screen needs eight columns,
 * three filters and one row action. That is less markup written directly than
 * subclassing it would be, and it does not drag the screen into core's `$_GET`
 * conventions. The markup still uses core's `.wp-list-table` classes, so the
 * result looks and behaves like every other list in wp-admin.
 *
 * What the screens do NOT hand-roll: the menu, the page shell, tabs, form
 * tables, buttons, notices, pagination links, nonces and capability checks all
 * come from WordPress.
 */
abstract class Screen {

	/**
	 * The capability required to see or act on any payment screen.
	 *
	 * Checked in three places for every screen: when the menu is registered,
	 * when the page renders, and again inside every POST handler. The menu
	 * check alone is not access control — `admin.php?page=…` is a URL anybody
	 * can type.
	 */
	public const CAPABILITY = 'stg_manage_payments';

	/**
	 * Top-level menu slug.
	 */
	public const MENU = 'stg-payments';

	/**
	 * This screen's page slug.
	 *
	 * @return string
	 */
	abstract public function slug(): string;

	/**
	 * Title for the browser and the page heading.
	 *
	 * @return string
	 */
	abstract public function page_title(): string;

	/**
	 * Title for the menu row.
	 *
	 * @return string
	 */
	abstract public function menu_title(): string;

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	abstract public function render(): void;

	/**
	 * Register any `admin_post_` handlers this screen owns.
	 *
	 * Separate from rendering because handlers must exist on the request that
	 * submits the form, which is `admin-post.php` — a request on which no
	 * screen is ever rendered.
	 *
	 * @return void
	 */
	public function register_handlers(): void {}

	/**
	 * Whether the current user may be here at all.
	 *
	 * Requires the STG payment capability AND `manage_options`. The second is
	 * not redundant: a role plugin that hands out a custom capability should
	 * not thereby hand over the merchant account's API keys. Both, or neither.
	 *
	 * @return bool
	 */
	public static function permitted(): bool {
		return current_user_can( self::CAPABILITY ) && current_user_can( 'manage_options' );
	}

	/**
	 * Stop dead unless the caller is permitted.
	 *
	 * @return void
	 */
	protected function require_permission(): void {
		if ( ! self::permitted() ) {
			wp_die(
				esc_html__( 'You do not have permission to manage payments.', 'stg-moyasar-payments' ),
				esc_html__( 'Not permitted', 'stg-moyasar-payments' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * A URL for one of this plugin's screens.
	 *
	 * @param string                    $slug Page slug, or '' for this screen.
	 * @param array<string, string|int> $args Extra query arguments.
	 * @return string
	 */
	public function url( string $slug = '', array $args = array() ): string {
		return add_query_arg(
			array_merge( array( 'page' => '' !== $slug ? $slug : $this->slug() ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render the page header: title, description and tabs.
	 *
	 * @param array<string, mixed> $args Header arguments.
	 * @return void
	 */
	protected function header( array $args = array() ): void {
		View::render(
			'partials/header',
			array_merge(
				array(
					'title'    => $this->page_title(),
					'subtitle' => '',
					'actions'  => '',
					'current'  => $this->slug(),
					'tabs'     => array(),
					'screen'   => $this,
				),
				$args
			)
		);
	}

	/**
	 * Finish a POST handler by redirecting back with a message.
	 *
	 * Post-redirect-get, so a refresh cannot resubmit — which on these screens
	 * could mean re-saving a credential or re-flipping the environment.
	 *
	 * @param string                    $notice Message key.
	 * @param string                    $slug   Screen to return to, or '' for this one.
	 * @param array<string, string|int> $args   Extra query arguments.
	 * @return never
	 */
	protected function redirect_back( string $notice, string $slug = '', array $args = array() ): void {
		wp_safe_redirect( $this->url( $slug, array_merge( $args, array( 'stg_notice' => $notice ) ) ) );
		exit;
	}

	/**
	 * Render an admin notice from the redirect, if there is one.
	 *
	 * @param array<string, array{type: string, text: string}> $messages Known messages.
	 * @return void
	 */
	protected function print_notice( array $messages ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads a key set by our own redirect; renders text from a fixed list and changes nothing.
		$key = isset( $_GET['stg_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['stg_notice'] ) ) : '';

		if ( '' === $key || ! isset( $messages[ $key ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $key ]['type'] ),
			esc_html( $messages[ $key ]['text'] )
		);
	}

	/**
	 * A sanitised value from the current request's query string.
	 *
	 * @param string $key     Parameter.
	 * @param string $default Fallback.
	 * @return string
	 */
	protected function query( string $key, string $default = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view state: filters, tabs and pagination. Nothing here changes anything.
		if ( ! isset( $_GET[ $key ] ) ) {
			return $default;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
		return sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) );
	}
}
