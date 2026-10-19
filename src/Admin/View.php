<?php
/**
 * Rendering helpers for the admin screens.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar\Admin;

use STG\Moyasar\Mode;
use STG\Moyasar\Payments\Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads view files and renders the few components used across all of them.
 *
 * The screens used to build their markup with `echo` and `printf` - ninety-two
 * calls in the settings screen alone, with seven inline `style` attributes
 * scattered through them. That is fine for thirty lines and unreadable at eight
 * hundred: you cannot see the page's structure, a designer cannot touch it, and
 * every change risks an escaping mistake because the escaping is interleaved
 * with the layout.
 *
 * Views are plain PHP templates instead. No engine, no compilation, no
 * dependency - `include` with an extracted array, which is what WordPress
 * itself does. The screen classes keep the logic and hand the view a prepared
 * array; the view does presentation and escaping only.
 */
final class View {

	/**
	 * Render a view file.
	 *
	 * @param string               $view View name, relative to `views/`, without extension.
	 * @param array<string, mixed> $data Variables for the view.
	 * @return void
	 */
	public static function render( string $view, array $data = array() ): void {
		// Directory traversal is not a live risk here - every caller passes a
		// literal - but a view name is a path, and paths get parameterised
		// eventually.
		if ( ! preg_match( '#^[a-z0-9/_-]+$#', $view ) ) {
			return;
		}

		$file = STG_MOYASAR_DIR . 'views/' . $view . '.php';

		if ( ! is_readable( $file ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- The array is built by the calling screen, never from request data.
		extract( $data, EXTR_SKIP );

		include $file;
	}

	/**
	 * A status badge for a payment.
	 *
	 * **Never colour alone.** Each badge carries its own word, and a glyph that
	 * differs in shape between states, so the information survives greyscale,
	 * colour blindness and a screen reader. The colour is the third signal, not
	 * the first.
	 *
	 * @param string $status Payment status.
	 * @return string Escaped HTML.
	 */
	public static function status_badge( string $status ): string {
		$glyphs = array(
			Status::PAID     => '✓',
			Status::PENDING  => '○',
			Status::FAILED   => '✕',
			Status::EXPIRED  => '—',
			Status::REFUNDED => '↩',
			Status::VOIDED   => '⊘',
			Status::FLAGGED  => '!',
		);

		return sprintf(
			'<span class="stg-pay-badge stg-pay-badge--%1$s"><span class="stg-pay-badge__glyph" aria-hidden="true">%2$s</span>%3$s</span>',
			esc_attr( $status ),
			esc_html( $glyphs[ $status ] ?? '·' ),
			esc_html( Status::label( $status ) )
		);
	}

	/**
	 * An environment badge.
	 *
	 * @param string $mode Mode.
	 * @return string Escaped HTML.
	 */
	public static function mode_badge( string $mode ): string {
		return sprintf(
			'<span class="stg-pay-badge stg-pay-badge--mode-%1$s">%2$s</span>',
			esc_attr( $mode ),
			esc_html( Mode::label( $mode ) )
		);
	}

	/**
	 * A tick or cross for a checklist row.
	 *
	 * @param bool $ok Whether the check passed.
	 * @return string Escaped HTML.
	 */
	public static function check( bool $ok ): string {
		return sprintf(
			'<span class="stg-pay-check stg-pay-check--%1$s" role="img" aria-label="%2$s">%3$s</span>',
			$ok ? 'yes' : 'no',
			esc_attr( $ok ? __( 'Passed', 'stg-moyasar-payments' ) : __( 'Not yet', 'stg-moyasar-payments' ) ),
			$ok ? '✓' : '✕'
		);
	}

	/**
	 * Human "x ago", or a dash.
	 *
	 * @param int $timestamp Unix timestamp, or 0.
	 * @return string
	 */
	public static function ago( int $timestamp ): string {
		if ( $timestamp < 1 ) {
			return '—';
		}

		return sprintf(
			/* translators: %s: human-readable time difference, e.g. "5 mins" */
			__( '%s ago', 'stg-moyasar-payments' ),
			human_time_diff( $timestamp )
		);
	}

	/**
	 * Format a minor-unit amount for display.
	 *
	 * Core's formatter (D-150): the riyal sign or "$", the amount, isolated in
	 * a left-to-right `<bdi>` so an Arabic, right-to-left cell cannot reorder
	 * the sign and the digits into something that is not the price.
	 *
	 * @param int    $minor    Amount in minor units.
	 * @param string $currency ISO-4217 code.
	 * @return string Escaped HTML.
	 */
	public static function money( int $minor, string $currency ): string {
		return \STG\Core\Support\Money::html( $minor, $currency );
	}
}
