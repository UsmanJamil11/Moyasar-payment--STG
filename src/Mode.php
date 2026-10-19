<?php
/**
 * Test, live and fake environments.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which world a payment happened in, and what that implies.
 *
 * Moyasar has no environment switch. The mode *is* the key you authenticate
 * with: `sk_test_` reaches a sandbox that never touches a banking network,
 * `sk_live_` moves real money. Nothing in a request announces which world you
 * are in, so the only defence against mixing them is refusing to let the two
 * ever meet in one code path.
 *
 * Four rules follow, and all four are enforced rather than merely documented:
 *
 * 1. **Test is the default.** A fresh install cannot charge anybody.
 * 2. **The mode is stamped on every payment row** when the intent is created,
 *    and never recomputed. A payment is permanently marked with the world it
 *    happened in, so no later configuration change can retroactively make a
 *    sandbox transaction look like revenue.
 * 3. **There is no fallback in either direction.** A missing live key while in
 *    live mode is an error an administrator must fix. It is never quietly
 *    answered with a test key, which would produce a checkout that appears to
 *    work, takes no money, and grants a paid plan.
 * 4. **Settlement carries `AND mode = %s`.** A row minted in one mode cannot be
 *    settled in another, even if somebody flips the switch mid-payment.
 *
 * `FAKE` is a third world, not a flavour of test. It exists so that the local
 * test harness cannot produce a row that real code would ever act on: nothing
 * can settle a fake row unless the fake client is installed, and installing it
 * requires conditions that cannot be met on a production site. See
 * `Api\Fake_Client`.
 */
final class Mode {

	/** Sandbox. Real Moyasar, no banking network, no money. */
	public const TEST = 'test';

	/** Production. Real cards, real money. */
	public const LIVE = 'live';

	/** No Moyasar at all. Local test harness only. */
	public const FAKE = 'fake';

	/**
	 * The modes an administrator may choose between.
	 *
	 * `FAKE` is absent on purpose: it is never a setting, only a consequence of
	 * the fake client being installed by a developer.
	 *
	 * @return string[]
	 */
	public static function selectable(): array {
		return array( self::TEST, self::LIVE );
	}

	/**
	 * Every mode a stored row might carry.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::TEST, self::LIVE, self::FAKE );
	}

	/**
	 * Coerce a value to a selectable mode, defaulting to test.
	 *
	 * The failure direction is what matters: an unparseable mode that defaulted
	 * to LIVE would be a corrupt option row deciding to charge people.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	public static function normalise( mixed $value ): string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';

		return in_array( $value, self::selectable(), true ) ? $value : self::TEST;
	}

	/**
	 * Whether a mode is the live one.
	 *
	 * @param string $mode Mode.
	 * @return bool
	 */
	public static function is_live( string $mode ): bool {
		return self::LIVE === strtolower( trim( $mode ) );
	}

	/**
	 * The mode implied by a payload's `live` boolean.
	 *
	 * Moyasar sends `live: true|false` on every webhook and on every payment
	 * object. Translating it in one place keeps the comparison honest; JSON
	 * booleans have a habit of arriving as strings through a proxy.
	 *
	 * @param mixed $live The payload's `live` value.
	 * @return string
	 */
	public static function from_live_flag( mixed $live ): string {
		return in_array( $live, array( true, 1, '1', 'true' ), true ) ? self::LIVE : self::TEST;
	}

	/**
	 * Whether a provider's `live` flag agrees with the mode we are operating in.
	 *
	 * Asked as "do these agree" rather than "are these equal", because there are
	 * three modes and only two values the provider can send. `FAKE` and `TEST`
	 * are different worlds to STG but they are the same answer to the only
	 * question being asked here: is real money involved? Comparing the mode
	 * strings directly made this check vacuous under the test harness - the
	 * harness's `FAKE` never equalled the provider's `test`, so the guard was
	 * skipped exactly where it was being tested.
	 *
	 * The disagreements this catches are both real and both serious: a sandbox
	 * event arriving at a live site, and a live event arriving at a site that
	 * believes it is in test. The second is how a genuine charge gets treated as
	 * a rehearsal.
	 *
	 * @param string $mode Mode STG is operating in.
	 * @param mixed  $live The provider's `live` value.
	 * @return bool
	 */
	public static function agrees_with_live_flag( string $mode, mixed $live ): bool {
		return self::is_live( $mode ) === ( self::LIVE === self::from_live_flag( $live ) );
	}

	/**
	 * The mode a key belongs to, or an empty string if it is not a Moyasar key.
	 *
	 * @param string $key An API key.
	 * @return string
	 */
	public static function of_key( string $key ): string {
		if ( preg_match( '/^(?:pk|sk)_(test|live)_/', trim( $key ), $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	/**
	 * Human label.
	 *
	 * @param string $mode Mode.
	 * @return string
	 */
	public static function label( string $mode ): string {
		switch ( strtolower( trim( $mode ) ) ) {
			case self::LIVE:
				return __( 'Live', 'stg-moyasar-payments' );
			case self::FAKE:
				return __( 'Fake', 'stg-moyasar-payments' );
			default:
				return __( 'Test', 'stg-moyasar-payments' );
		}
	}

	/**
	 * The gateway id recorded on the subscription this mode's payment creates.
	 *
	 * This is the sharpest provenance marker in the design, and it costs
	 * nothing. `wp_stg_subscriptions.gateway` is already a free-form
	 * `VARCHAR(30)` that `Service::start()` runs through `Sanitizer::key()`,
	 * and it is the one field `GET /me/subscription` exposes. Writing
	 * `moyasar_test` rather than `moyasar` means "show me every subscription
	 * that came from a sandbox payment" is a WHERE clause on one table, for
	 * ever, with no join and no ambiguity - which is exactly the question
	 * somebody asks the week after go-live.
	 *
	 * @param string $mode Mode.
	 * @return string
	 */
	public static function gateway_id( string $mode ): string {
		switch ( strtolower( trim( $mode ) ) ) {
			case self::LIVE:
				return 'moyasar';
			case self::FAKE:
				return 'moyasar_fake';
			default:
				return 'moyasar_test';
		}
	}
}
