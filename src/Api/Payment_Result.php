<?php
/**
 * A provider's answer about one payment.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The normalised shape of "what does the provider say happened".
 *
 * Immutable, and deliberately narrow. The raw Moyasar payment object contains
 * a `source` block with a masked card number, the cardholder's name and the
 * issuing bank; none of it is needed to decide whether a subscription should
 * start, so none of it gets past this boundary. Code above this line cannot
 * accidentally log, store or return cardholder data, because it never holds
 * any.
 *
 * `metadata` is carried through because it is ours - it is where the STG
 * reference travels - but it is the only free-form field that survives.
 */
final class Payment_Result {

	/**
	 * Constructor.
	 *
	 * @param string               $id          Provider's payment id.
	 * @param string               $status      Provider's own status string.
	 * @param int                  $amount      Amount in minor units.
	 * @param string               $currency    ISO-4217 code.
	 * @param bool                 $live        Whether the provider says this is live.
	 * @param string               $source_type Payment method category.
	 * @param array<string,string> $metadata    Merchant metadata echoed back.
	 * @param string               $failure_code Provider failure code, if any.
	 * @param string               $failure_message Provider failure text, if any.
	 * @param int                  $refunded    Amount refunded in minor units.
	 * @param bool                 $live_known  Whether the provider said which environment this is.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $status,
		public readonly int $amount,
		public readonly string $currency,
		public readonly bool $live,
		public readonly string $source_type = '',
		public readonly array $metadata = array(),
		public readonly string $failure_code = '',
		public readonly string $failure_message = '',
		public readonly int $refunded = 0,
		public readonly bool $live_known = true
	) {}

	/**
	 * Build from a decoded Moyasar payment object.
	 *
	 * Every field is coerced rather than trusted. A provider that starts
	 * returning the amount as a string, or omits `currency`, must not produce a
	 * PHP notice inside the function that decides whether somebody gets charged.
	 *
	 * @param array<string, mixed> $payload Decoded payment object.
	 * @return self
	 */
	public static function from_payload( array $payload ): self {
		$source      = is_array( $payload['source'] ?? null ) ? $payload['source'] : array();
		$metadata    = is_array( $payload['metadata'] ?? null ) ? $payload['metadata'] : array();
		$clean_meta  = array();

		foreach ( $metadata as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$clean_meta[ (string) $key ] = (string) $value;
			}
		}

		return new self(
			(string) ( $payload['id'] ?? '' ),
			strtolower( (string) ( $payload['status'] ?? '' ) ),
			(int) ( $payload['amount'] ?? 0 ),
			strtoupper( (string) ( $payload['currency'] ?? '' ) ),
			// Absent means test. Defaulting the other way would let a malformed
			// response claim to be live.
			in_array( $payload['live'] ?? false, array( true, 1, '1', 'true' ), true ),
			isset( $source['type'] ) && is_scalar( $source['type'] ) ? (string) $source['type'] : '',
			$clean_meta,
			// `response_code` is the field the API reference documents (the
			// bank's two-digit answer); the others are fallbacks.
			self::first_string( array( $source['response_code'] ?? null, $source['transaction_status'] ?? null, $payload['status'] ?? null ), 64 ),
			self::first_string( array( $source['message'] ?? null, $payload['message'] ?? null ), 255 ),
			(int) ( $payload['refunded'] ?? 0 ),
			/*
			 * A payment fetched from the API carries no `live` field at all
			 * (seen on the sandbox, 2026-10-11; only a webhook's envelope has
			 * one). Absent is therefore "not said", which is a different thing
			 * from "test" - reading it as test meant every live payment would
			 * have disagreed with a live site and been refused.
			 */
			array_key_exists( 'live', $payload )
		);
	}

	/**
	 * The first usable string from a list of candidate fields.
	 *
	 * Moyasar's decline detail is not precisely specified in the public
	 * documentation - `source.message` carries the human text in the responses
	 * the docs show, and `source.transaction_status` appears to carry the
	 * machine code, but neither is guaranteed. Reading a list of candidates
	 * rather than one field means a real test transaction can confirm the shape
	 * without this class having to be rewritten, and an absent field degrades to
	 * an empty string rather than a warning inside the code path that decides
	 * whether somebody was charged.
	 *
	 * **To confirm when the client's test credentials arrive:** run a declined
	 * test card and record which fields are actually populated.
	 *
	 * @param array<int, mixed> $candidates Values in preference order.
	 * @param int               $max_length Truncation limit.
	 * @return string
	 */
	private static function first_string( array $candidates, int $max_length ): string {
		foreach ( $candidates as $candidate ) {
			if ( is_scalar( $candidate ) && '' !== (string) $candidate ) {
				return substr( (string) $candidate, 0, $max_length );
			}
		}

		return '';
	}

	/**
	 * The STG reference this payment claims to belong to.
	 *
	 * @return string
	 */
	public function stg_reference(): string {
		$ref = $this->metadata['stg_ref'] ?? '';

		return preg_match( '/^[a-f0-9]{32}$/', $ref ) ? $ref : '';
	}

	/**
	 * Whether the refund covers the whole charge.
	 *
	 * Moyasar supports partial refunds, so "was this refunded" and "was all of
	 * it refunded" are different questions - and only the second one should end
	 * somebody's subscription.
	 *
	 * @return bool
	 */
	public function fully_refunded(): bool {
		return $this->refunded > 0 && $this->refunded >= $this->amount;
	}
}
