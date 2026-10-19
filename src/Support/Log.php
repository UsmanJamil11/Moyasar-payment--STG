<?php
/**
 * Logging, with an allow-list rather than a deny-list.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar\Support;

use STG\Core\Plugin as Core;
use STG\Moyasar\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes through STG Core's logger, and decides what is allowed to reach it.
 *
 * Core's `Logger` already redacts context keys containing `secret`, `token`,
 * `key`, `card`, `cvv` and so on, which catches a webhook's `secret_token`
 * twice over. **That is not enough here, and relying on it would be a mistake
 * worth naming.** Redaction is a deny-list matched on key names, and a Moyasar
 * payment object contains `source.number` - a masked card number - under a key
 * called `number`. Adding `number` to Core's deny-list would also redact
 * `page_number` and `reference_number` everywhere else in the project.
 *
 * So the rule here is inverted: **a provider payload is never passed to the
 * logger.** Callers build an explicit context array from the handful of fields
 * listed in `PAYMENT_FIELDS`, and anything not on that list does not get
 * logged at all. A deny-list fails open when the provider adds a field; an
 * allow-list fails closed, which is the direction you want when the field
 * somebody might add is a card number.
 */
final class Log {

	/**
	 * Fields of a provider payment object that may be logged.
	 *
	 * Note what is absent: `source` in its entirety, which is where the masked
	 * PAN, the cardholder name and the issuing bank live; `metadata`, which is
	 * ours but is free-form and could later carry anything; and the raw body.
	 *
	 * @var string[]
	 */
	private const PAYMENT_FIELDS = array( 'id', 'status', 'amount', 'currency', 'fee', 'created_at', 'source_type' );

	/**
	 * Build a safe context array from a decoded provider payment.
	 *
	 * @param array<string, mixed> $payment Decoded payment object.
	 * @return array<string, mixed>
	 */
	public static function payment_context( array $payment ): array {
		$safe = array();

		foreach ( self::PAYMENT_FIELDS as $field ) {
			if ( 'source_type' === $field ) {
				$source = $payment['source'] ?? array();

				if ( is_array( $source ) && isset( $source['type'] ) && is_scalar( $source['type'] ) ) {
					$safe['source_type'] = (string) $source['type'];
				}

				continue;
			}

			if ( isset( $payment[ $field ] ) && is_scalar( $payment[ $field ] ) ) {
				$safe[ $field ] = $payment[ $field ];
			}
		}

		return $safe;
	}

	/**
	 * Log at info level.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context. Must already be safe.
	 * @return string Error id.
	 */
	public static function info( string $message, array $context = array() ): string {
		return self::write( 'info', $message, $context );
	}

	/**
	 * Log at warning level.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context. Must already be safe.
	 * @return string Error id.
	 */
	public static function warning( string $message, array $context = array() ): string {
		return self::write( 'warning', $message, $context );
	}

	/**
	 * Log at error level.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context. Must already be safe.
	 * @return string Error id.
	 */
	public static function error( string $message, array $context = array() ): string {
		return self::write( 'error', $message, $context );
	}

	/**
	 * Log at critical level.
	 *
	 * Reserved for the cases where money has moved and an invariant did not
	 * hold - a mismatched amount, an unattributable paid payment, an activation
	 * that failed after a successful charge. Every one of these needs a person.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context. Must already be safe.
	 * @return string Error id.
	 */
	public static function critical( string $message, array $context = array() ): string {
		return self::write( 'critical', $message, $context );
	}

	/**
	 * Log at debug level, only when debug logging is switched on.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context. Must already be safe.
	 * @return string Error id, or an empty string when suppressed.
	 */
	public static function debug( string $message, array $context = array() ): string {
		if ( empty( Settings::get( 'debug', false ) ) ) {
			return '';
		}

		return self::write( 'debug', $message, $context );
	}

	/**
	 * Hand a line to STG Core's logger.
	 *
	 * Tagged so `grep 'moyasar'` over the debug log finds every payment line and
	 * nothing else.
	 *
	 * @param string              $level   Level.
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return string Error id.
	 */
	private static function write( string $level, string $message, array $context ): string {
		if ( ! class_exists( Core::class ) ) {
			return '';
		}

		return Core::instance()->logger()->log(
			$level,
			'[moyasar] ' . $message,
			$context
		);
	}
}
