<?php
/**
 * Moyasar API keys and webhook secrets.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where the secrets live, and what is honestly true about protecting them.
 *
 * **Storage.** One option, `stg_moyasar_credentials`, written with
 * `autoload = false`. Deliberately not part of STG Core's `stg_core_settings`,
 * which IS autoloaded: there is no reason for a secret key to be read out of
 * the database and held in memory on every front-end request when it is needed
 * on perhaps one request in ten thousand, and `alloptions` is readable by every
 * other plugin on the site.
 *
 * **Constants win.** If `STG_MOYASAR_LIVE_SECRET_KEY` and friends are defined
 * in `wp-config.php` they take precedence and the admin field renders
 * read-only. This is the recommended production arrangement and it matches the
 * pattern STG already uses for `STG_TRANSLATION_API_KEY`. It is genuinely
 * stronger than the database: `wp-config.php` is git-ignored, is not in a
 * database dump, and is not reachable by SQL injection.
 *
 * **On encryption, plainly.** This class does not encrypt the option, and that
 * is a decision rather than an oversight. Encrypting a value with a key that
 * lives in the same database, or in the same repository, protects against
 * nothing - anything able to read the ciphertext can read the key beside it. It
 * would buy a reassuring `encrypt()` call, a false line in a security document,
 * and no attacker who is actually stopped. The two threats that matter are a
 * stolen database dump and arbitrary PHP execution, and a database-resident key
 * mitigates neither. What does reduce exposure is the list this class does
 * implement - fewer copies, fewer requests that load it, a capability check on
 * every path that could surface it, never rendered back into HTML, never in a
 * REST response, never in a log - plus wp-config constants for anyone who wants
 * the value out of the database altogether. If you are going to put a secret in
 * wp-config, put the credential there, not a key that decrypts the credential.
 *
 * @see \STG\Moyasar\Mode for why test and live keys never substitute for each other.
 */
final class Credentials {

	/**
	 * Option holding the keys. Never autoloaded.
	 */
	public const OPTION = 'stg_moyasar_credentials';

	/**
	 * Field keys, mapped to the wp-config constant that overrides each one.
	 *
	 * Webhook secrets are per mode. Moyasar's dashboard configures test and
	 * live webhooks as separate endpoints with separate tokens, so treating
	 * them as one shared value would let a sandbox event authenticate against
	 * the secret that guards real money.
	 *
	 * @return array<string, string>
	 */
	public static function fields(): array {
		return array(
			'test_publishable'    => 'STG_MOYASAR_TEST_PUBLISHABLE_KEY',
			'test_secret'         => 'STG_MOYASAR_TEST_SECRET_KEY',
			'test_webhook_secret' => 'STG_MOYASAR_TEST_WEBHOOK_SECRET',
			'live_publishable'    => 'STG_MOYASAR_LIVE_PUBLISHABLE_KEY',
			'live_secret'         => 'STG_MOYASAR_LIVE_SECRET_KEY',
			'live_webhook_secret' => 'STG_MOYASAR_LIVE_WEBHOOK_SECRET',
		);
	}

	/**
	 * Fields holding something that must never leave the server.
	 *
	 * The publishable keys are absent from this list, and that is correct:
	 * Moyasar documents them as safe to ship in browser and mobile code, and
	 * the checkout form cannot work without one being there.
	 *
	 * @return string[]
	 */
	public static function secret_fields(): array {
		return array( 'test_secret', 'test_webhook_secret', 'live_secret', 'live_webhook_secret' );
	}

	/**
	 * Whether a field is fixed by a wp-config constant.
	 *
	 * @param string $field Field key.
	 * @return bool
	 */
	public static function is_constant( string $field ): bool {
		$constant = self::fields()[ $field ] ?? '';

		return '' !== $constant && defined( $constant ) && '' !== trim( (string) constant( $constant ) );
	}

	/**
	 * Read one credential.
	 *
	 * Returns an empty string when unset. Callers must read empty as "not
	 * configured" and refuse to proceed - never as "use the other mode's key".
	 *
	 * @param string $field Field key.
	 * @return string
	 */
	public static function get( string $field ): string {
		if ( ! array_key_exists( $field, self::fields() ) ) {
			return '';
		}

		if ( self::is_constant( $field ) ) {
			return trim( (string) constant( self::fields()[ $field ] ) );
		}

		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return '';
		}

		return trim( (string) ( $stored[ $field ] ?? '' ) );
	}

	/**
	 * The publishable key for a mode.
	 *
	 * @param string $mode Mode.
	 * @return string
	 */
	public static function publishable( string $mode ): string {
		return self::get( Mode::is_live( $mode ) ? 'live_publishable' : 'test_publishable' );
	}

	/**
	 * The secret key for a mode.
	 *
	 * @param string $mode Mode.
	 * @return string
	 */
	public static function secret( string $mode ): string {
		return self::get( Mode::is_live( $mode ) ? 'live_secret' : 'test_secret' );
	}

	/**
	 * The webhook shared secret for a mode.
	 *
	 * @param string $mode Mode.
	 * @return string
	 */
	public static function webhook_secret( string $mode ): string {
		return self::get( Mode::is_live( $mode ) ? 'live_webhook_secret' : 'test_webhook_secret' );
	}

	/**
	 * Whether both keys needed to transact in a mode are present and well formed.
	 *
	 * @param string $mode Mode.
	 * @return bool
	 */
	public static function complete_for( string $mode ): bool {
		$mode = Mode::normalise( $mode );

		return Mode::of_key( self::publishable( $mode ) ) === $mode
			&& Mode::of_key( self::secret( $mode ) ) === $mode;
	}

	/**
	 * Assert a key is the right type and the right mode, immediately before use.
	 *
	 * The last line of defence, and the cheapest. Called just before a key is
	 * put into an Authorization header and just before a publishable key is
	 * printed into a page. A wrong constant in `wp-config.php`, a hand-edited
	 * option row or a restored backup from another environment all produce the
	 * same symptom - a key from the wrong world - and all of them are caught
	 * here rather than by a customer.
	 *
	 * @param string $key  The key.
	 * @param string $type 'pk' or 'sk'.
	 * @param string $mode Mode the key must belong to.
	 * @return bool
	 */
	public static function assert_shape( string $key, string $type, string $mode ): bool {
		return 1 === preg_match(
			'/^' . preg_quote( $type, '/' ) . '_' . preg_quote( Mode::normalise( $mode ), '/' ) . '_' . self::BODY . '$/',
			trim( $key )
		);
	}

	/**
	 * What may follow a key's prefix.
	 *
	 * Moyasar documents the four prefixes and nothing else: no length, no
	 * alphabet. So this does not invent one. It asks only that the rest is
	 * visible text with none of the things a paste goes wrong with - a space,
	 * a quote, a bracket, the `*` and dots of a masked display - and is long
	 * enough not to be a fragment.
	 */
	private const BODY = '[!#$%&()+,\-.\/0-9:;=?@A-Z\[\]^_a-z{|}~]{8,}';

	/**
	 * A pasted key with what the clipboard added taken off.
	 *
	 * Surrounding whitespace of every kind, including the non-breaking and
	 * zero-width characters a web page hands over with a copied value, and a
	 * byte-order mark. Those are never part of a key and are invisible in a
	 * masked field, so the person who pasted them cannot see why it failed.
	 * Nothing visible is changed: a key with a space in the middle is refused
	 * rather than quietly joined.
	 *
	 * @param string $value What was submitted.
	 * @return string
	 */
	public static function clean( string $value ): string {
		$value = (string) preg_replace( '/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $value );
		$value = (string) preg_replace( '/^[\s\x{00A0}\x{2000}-\x{200A}\x{202F}\x{3000}]+|[\s\x{00A0}\x{2000}-\x{200A}\x{202F}\x{3000}]+$/u', '', $value );

		return $value;
	}

	/**
	 * Why a candidate key cannot go in a field, as a code. '' when it can.
	 *
	 * A code rather than a sentence so the same answer can be logged, tested
	 * and shown: see `refusal_message()`. Checked from the most specific
	 * mistake to the least, so somebody who pasted a live secret key into the
	 * test publishable field is told the thing that matters most first.
	 *
	 * @param string $value    Candidate, already through `clean()`.
	 * @param string $type     'pk' or 'sk'.
	 * @param string $for_mode Mode the field belongs to.
	 * @return string
	 */
	public static function refusal( string $value, string $type, string $for_mode ): string {
		if ( '' === $value ) {
			return '';
		}

		// What the dashboard shows in place of a key it will not show again.
		if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value ) ) {
			return 'key_id';
		}

		if ( ! preg_match( '/^(pk|sk)_(test|live)_(.*)$/su', $value, $matches ) ) {
			return 'not_a_key';
		}

		if ( $matches[1] !== $type ) {
			return 'pk' === $type ? 'secret_in_publishable' : 'publishable_in_secret';
		}

		if ( $matches[2] !== Mode::normalise( $for_mode ) ) {
			return Mode::is_live( $for_mode ) ? 'test_in_live' : 'live_in_test';
		}

		if ( preg_match( '/\*|\x{2022}|\x{2026}|\.{3}/u', $matches[3] ) ) {
			return 'masked';
		}

		if ( preg_match( '/\s/u', $matches[3] ) ) {
			return 'has_space';
		}

		if ( strlen( $matches[3] ) < 8 ) {
			return 'too_short';
		}

		return self::assert_shape( $value, $type, $for_mode ) ? '' : 'bad_character';
	}

	/**
	 * A refusal code in words an administrator can act on.
	 *
	 * Never includes the value.
	 *
	 * @param string $code     Code from `refusal()`, or 'not_stored'.
	 * @param string $type     'pk' or 'sk'.
	 * @param string $for_mode Mode the field belongs to.
	 * @return string
	 */
	public static function refusal_message( string $code, string $type, string $for_mode ): string {
		$prefix = $type . '_' . Mode::normalise( $for_mode ) . '_';

		switch ( $code ) {
			case 'secret_in_publishable':
				return __( 'That is a secret key, and this is the publishable key field, which is printed into the payment page. The value has been discarded. Regenerate this key in your Moyasar dashboard, because it has now been handled as though it were public.', 'stg-moyasar-payments' );

			case 'publishable_in_secret':
				return __( 'That is a publishable key, not a secret key.', 'stg-moyasar-payments' );

			case 'test_in_live':
				return __( 'That is a test key, and this is the live key field.', 'stg-moyasar-payments' );

			case 'live_in_test':
				return __( 'That is a live key, and this is the test key field. Live keys charge real cards.', 'stg-moyasar-payments' );

			case 'key_id':
				return __( 'That is a key ID, not the key. Moyasar shows the ID in place of a key it will not show again; copy the key itself, or regenerate it.', 'stg-moyasar-payments' );

			case 'masked':
				return __( 'That is a masked or shortened key. Copy the complete key from your Moyasar dashboard.', 'stg-moyasar-payments' );

			case 'has_space':
				return __( 'That key has a space or a line break in the middle. Copy it again in one piece.', 'stg-moyasar-payments' );

			case 'too_short':
				return __( 'That key is incomplete. Copy the complete key from your Moyasar dashboard.', 'stg-moyasar-payments' );

			case 'bad_character':
				return __( 'That key contains a quote or another character that is not part of a key. Copy it again.', 'stg-moyasar-payments' );

			case 'already_set':
				return __( 'A value is already saved for this field. Remove it first, then save the new one.', 'stg-moyasar-payments' );

			case 'bad_secret':
				return __( 'A webhook secret cannot contain a line break or be longer than 200 characters.', 'stg-moyasar-payments' );

			case 'not_stored':
				return __( 'The key was accepted but could not be written to the database. The previous value is unchanged.', 'stg-moyasar-payments' );

			default:
				return sprintf(
					/* translators: %s: key prefix such as pk_test_ */
					__( 'That is not a Moyasar key for this field. It must start with %s', 'stg-moyasar-payments' ),
					$prefix
				);
		}
	}

	/**
	 * Take a submitted set of credentials and store the ones that are right.
	 *
	 * Each field stands alone: a refused publishable key never stops the
	 * secret key beside it being stored, and never touches what was there
	 * before. An empty field means "unchanged". A field fixed by a wp-config
	 * constant is reported as such and nothing is written for it. A field
	 * that already holds a value is never overwritten: see `forget()`.
	 *
	 * @param array<string, mixed> $submitted Field => raw submitted value.
	 * @return array{saved: string[], refused: array<string, string>, locked: string[]}
	 */
	public static function submit( array $submitted ): array {
		$rules = array(
			'test_publishable' => array( 'pk', Mode::TEST ),
			'test_secret'      => array( 'sk', Mode::TEST ),
			'live_publishable' => array( 'pk', Mode::LIVE ),
			'live_secret'      => array( 'sk', Mode::LIVE ),
		);

		$out    = array(
			'saved'   => array(),
			'refused' => array(),
			'locked'  => array(),
		);
		$values = array();

		foreach ( array_keys( self::fields() ) as $field ) {
			if ( ! isset( $submitted[ $field ] ) || ! is_scalar( $submitted[ $field ] ) ) {
				continue;
			}

			$value = self::clean( (string) $submitted[ $field ] );

			if ( '' === $value ) {
				continue;
			}

			if ( self::is_constant( $field ) ) {
				$out['locked'][] = $field;

				continue;
			}

			/*
			 * A saved value is replaced on purpose or not at all.
			 *
			 * The screen shows no box for a field that already holds a value,
			 * so a value arriving here for one did not come from the screen -
			 * or came from a page left open since before the value was saved.
			 * Either way the saved value stays until somebody removes it.
			 */
			if ( '' !== self::get( $field ) ) {
				$out['refused'][ $field ] = 'already_set';

				continue;
			}

			if ( isset( $rules[ $field ] ) ) {
				$code = self::refusal( $value, $rules[ $field ][0], $rules[ $field ][1] );

				if ( '' !== $code ) {
					$out['refused'][ $field ] = $code;

					continue;
				}
			} elseif ( strlen( $value ) > 200 || preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
				// A webhook secret is the administrator's own text, so almost
				// anything goes - but not something that cannot be a token.
				$out['refused'][ $field ] = 'bad_secret';

				continue;
			}

			$values[ $field ] = $value;
		}

		if ( $values ) {
			self::update( $values );

			// Believe the database, not the call: read each one back.
			wp_cache_delete( self::OPTION, 'options' );

			foreach ( $values as $field => $value ) {
				if ( hash_equals( $value, self::get( $field ) ) ) {
					$out['saved'][] = $field;
				} else {
					$out['refused'][ $field ] = 'not_stored';
				}
			}
		}

		return $out;
	}

	/**
	 * A field's name for a message.
	 *
	 * @param string $field Field key.
	 * @return string
	 */
	public static function label( string $field ): string {
		$labels = array(
			'test_publishable'    => __( 'Test publishable key', 'stg-moyasar-payments' ),
			'test_secret'         => __( 'Test secret key', 'stg-moyasar-payments' ),
			'test_webhook_secret' => __( 'Test webhook secret', 'stg-moyasar-payments' ),
			'live_publishable'    => __( 'Live publishable key', 'stg-moyasar-payments' ),
			'live_secret'         => __( 'Live secret key', 'stg-moyasar-payments' ),
			'live_webhook_secret' => __( 'Live webhook secret', 'stg-moyasar-payments' ),
		);

		return $labels[ $field ] ?? $field;
	}

	/**
	 * Validate a candidate key before storing it.
	 *
	 * Format checking is not authentication - only Moyasar can say whether a key
	 * works. What it catches is the mistakes people actually make under time
	 * pressure: pasting the secret key into the publishable field that gets
	 * printed into a web page, pasting a live key into a test field, and
	 * pasting a stray quote or a trailing newline.
	 *
	 * Note what is NOT refused: storing live keys while the site is in test
	 * mode. That has to be allowed, or there is no way to prepare a go-live.
	 * The guard against *using* the wrong mode's key lives in
	 * `assert_shape()`, at the moment of use.
	 *
	 * @param string $value    Candidate key.
	 * @param string $type     'pk' or 'sk'.
	 * @param string $for_mode Mode the field belongs to.
	 * @return true|string True, or a translated reason it was refused.
	 */
	public static function validate_key( string $value, string $type, string $for_mode ): bool|string {
		$code = self::refusal( self::clean( $value ), $type, $for_mode );

		return '' === $code ? true : self::refusal_message( $code, $type, $for_mode );
	}

	/**
	 * How many mask characters stand in for a saved value. Always this many.
	 */
	private const MASK = 16;

	/**
	 * Where a field's effective value comes from.
	 *
	 * Two sources exist and no others: a wp-config constant, which always
	 * wins, and the database option. There is no environment-variable source.
	 *
	 * @param string $field Field key.
	 * @return string 'constant', 'database' or 'none'.
	 */
	public static function source( string $field ): string {
		if ( self::is_constant( $field ) ) {
			return 'constant';
		}

		return '' !== self::get( $field ) ? 'database' : 'none';
	}

	/**
	 * The prefix a field's key carries, or '' for a webhook secret.
	 *
	 * From the field, not from the value: it is what the field is for.
	 *
	 * @param string $field Field key.
	 * @return string
	 */
	public static function prefix( string $field ): string {
		$prefixes = array(
			'test_publishable' => 'pk_test_',
			'test_secret'      => 'sk_test_',
			'live_publishable' => 'pk_live_',
			'live_secret'      => 'sk_live_',
		);

		return $prefixes[ $field ] ?? '';
	}

	/**
	 * What an admin screen shows in place of a saved value.
	 *
	 * Not a masked copy of the value: nothing of the value is in it. The
	 * prefix says which kind of key the field holds and the same fixed run of
	 * dots follows whatever is saved, so the page gives away neither the
	 * key's length nor any character of it.
	 *
	 * @param string $field Field key.
	 * @return string '' when nothing is configured.
	 */
	public static function mask( string $field ): string {
		if ( 'none' === self::source( $field ) ) {
			return '';
		}

		return self::prefix( $field ) . str_repeat( "\u{2022}", self::MASK );
	}

	/**
	 * A safe description of a stored credential, for an admin screen.
	 *
	 * The mask, or "Not set". Never the body, a suffix or the length.
	 *
	 * @param string $field Field key.
	 * @return string
	 */
	public static function describe( string $field ): string {
		$mask = self::mask( $field );

		return '' !== $mask ? $mask : __( 'Not set', 'stg-moyasar-payments' );
	}

	/**
	 * Write credentials, merging over what is stored.
	 *
	 * An empty submitted value LEAVES the stored one alone rather than clearing
	 * it, because the admin form never renders a real value back into an input -
	 * so an empty field means "unchanged", not "delete". Clearing is a separate,
	 * explicit action; see `forget()`.
	 *
	 * @param array<string, string> $values Field => value.
	 * @return bool
	 */
	public static function update( array $values ): bool {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		foreach ( array_keys( self::fields() ) as $field ) {
			if ( ! array_key_exists( $field, $values ) ) {
				continue;
			}

			$value = trim( (string) $values[ $field ] );

			if ( '' === $value ) {
				continue;
			}

			$stored[ $field ] = $value;
		}

		return self::write( $stored );
	}

	/**
	 * Clear one credential.
	 *
	 * @param string $field Field key.
	 * @return bool
	 */
	public static function forget( string $field ): bool {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		unset( $stored[ $field ] );

		return self::write( $stored );
	}

	/**
	 * Whether any live credential is resolvable.
	 *
	 * Used by the fake client's refusal check: a site that can reach live
	 * Moyasar must never be running a fake payment provider.
	 *
	 * @return bool
	 */
	public static function has_any_live(): bool {
		return '' !== self::get( 'live_publishable' ) || '' !== self::get( 'live_secret' );
	}

	/**
	 * Persist, keeping the option out of the autoload set.
	 *
	 * The row is created with `add_option( ..., false )` the first time, because
	 * `update_option()` on a missing key creates it with autoload defaulted on
	 * in some WordPress versions - and an autoloaded secret is precisely what
	 * this class exists to avoid.
	 *
	 * @param array<string, string> $values Field => value.
	 * @return bool
	 */
	private static function write( array $values ): bool {
		if ( false === get_option( self::OPTION, false ) ) {
			return add_option( self::OPTION, $values, '', false );
		}

		return update_option( self::OPTION, $values, false );
	}
}
