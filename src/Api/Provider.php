<?php
/**
 * The boundary between STG and a payment provider's HTTP API.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar\Api;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the settlement logic needs from an HTTP client, and nothing else.
 *
 * Three methods, all of them reads. There is no `create_payment()`, and its
 * absence is worth explaining: the hosted form creates the payment from the
 * customer's browser using the publishable key, so the card details travel
 * from the browser to Moyasar and never touch this server. That is the whole
 * reason STG stays out of PCI SAQ-D scope, and it is why the only thing this
 * application ever does with a payment is *ask about* one.
 *
 * There is also no `refund()`, `capture()` or `void()`. STG has no business
 * requirement for them, and an interface method is a thing somebody will
 * eventually call - so the safest refund button is one that does not exist in
 * the codebase. Refunds are issued from the Moyasar dashboard by whoever is
 * authorised to move money; STG's job is to notice and record.
 *
 * The interface exists so the test harness can substitute a fake HTTP client
 * rather than a fake gateway. That distinction matters: everything above this
 * line - intent creation, the compare-and-set, status mapping, every flagged
 * path, all six front-end states - then runs UNCHANGED under test. A fake that
 * replaced the gateway would test a code path that never ships.
 */
interface Provider {

	/**
	 * Which world this client talks to.
	 *
	 * @return string One of the Mode constants.
	 */
	public function mode(): string;

	/**
	 * Ask the provider what really happened to one payment.
	 *
	 * The only source of truth about money in this integration. A callback's
	 * query string and a webhook's body are hints that something happened; this
	 * is the fact.
	 *
	 * @param string $payment_id The provider's own payment id.
	 * @return Payment_Result|WP_Error
	 */
	public function fetch( string $payment_id ): Payment_Result|WP_Error;

	/**
	 * Find a payment by the STG reference carried in its metadata.
	 *
	 * Used by reconciliation, for the case where a customer paid, the webhook
	 * never arrived and the browser never came back - a dropped connection at
	 * exactly the wrong moment. Without this, that customer's money is taken and
	 * their plan never starts, and nothing in the system ever notices.
	 *
	 * @param string $reference STG public reference.
	 * @return Payment_Result|WP_Error|null Null when there is genuinely no such payment.
	 */
	public function find_by_reference( string $reference ): Payment_Result|WP_Error|null;

	/**
	 * Check that the configured credentials authenticate.
	 *
	 * A read, never a write: it must be safe to press the button on the
	 * diagnostics screen in live mode without creating anything.
	 *
	 * @return true|WP_Error
	 */
	public function ping(): bool|WP_Error;
}
