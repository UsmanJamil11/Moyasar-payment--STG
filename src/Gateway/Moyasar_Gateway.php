<?php
/**
 * The adapter STG Core's gateway seam was built for.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar\Gateway;

use STG\Core\Subscriptions\Gateway;
use STG\Moyasar\Payments\Intents;
use STG\Moyasar\Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moyasar, as STG Core sees it.
 *
 * The whole surface between this plugin and the marketplace is four methods,
 * because that is all `Subscriptions\Gateway` asks for. Core has no idea that
 * Moyasar exists; it asks "where do I send this person to pay" and gets a URL.
 *
 * **`checkout_url()` returns an STG URL, not a Moyasar one, and that is not a
 * workaround.** Moyasar's card form is mounted client-side with the publishable
 * key - there is no provider-hosted page to redirect to. So the URL points at
 * STG's own checkout screen, which renders the form. The interface asks where
 * to send the user, and this is a truthful answer; a provider with a hosted
 * checkout would return theirs and Core would not notice the difference.
 *
 * One honest caveat: this method reads like a pure query and has a write side
 * effect - it opens a payment intent. That is acceptable only because the
 * intent is idempotent per (user, plan), enforced by a unique index, so calling
 * it twice produces one row and the same URL. Anyone adding a second caller
 * should know that.
 */
final class Moyasar_Gateway implements Gateway {

	/**
	 * Machine id, stored on the subscription row.
	 *
	 * Note that this is the id of the GATEWAY, which is a constant. The id
	 * actually written to a subscription comes from `Mode::gateway_id()` and
	 * varies by mode, so a test payment and a live payment are distinguishable
	 * for ever. See Settlement.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'moyasar';
	}

	/**
	 * Human name for an admin screen.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Moyasar', 'stg-moyasar-payments' );
	}

	/**
	 * Whether a user can subscribe themselves right now.
	 *
	 * False whenever payments are switched off or the active mode's credentials
	 * are absent or malformed. This is load-bearing rather than cosmetic:
	 * `GET /stg/v1/plans` already reports `self_service`, and the pricing page
	 * already renders "talk to us" when it is false, so an unconfigured site
	 * behaves correctly with no extra work anywhere. Hardcoding `true` here
	 * would throw that away and produce a checkout button that leads to a 503.
	 *
	 * @return bool
	 */
	public function supports_self_service(): bool {
		return Settings::is_operational();
	}

	/**
	 * Where to send a user to pay.
	 *
	 * @param int    $user_id   User.
	 * @param string $plan_slug Plan.
	 * @return string|WP_Error Absolute URL, or an error explaining why not.
	 */
	public function checkout_url( int $user_id, string $plan_slug ): string|WP_Error {
		$intent = Intents::open( $user_id, $plan_slug );

		if ( is_wp_error( $intent ) ) {
			return $intent;
		}

		return Intents::checkout_url( (string) $intent['public_ref'] );
	}
}
