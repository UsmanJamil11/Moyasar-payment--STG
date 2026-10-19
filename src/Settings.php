<?php
/**
 * Non-credential configuration.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything an administrator can change that is not a secret.
 *
 * Kept in its own autoloaded option rather than in STG Core's
 * `stg_core_settings`, so that deactivating this plugin removes its
 * configuration surface cleanly and Core has no opinion about Moyasar. Secrets
 * live in `Credentials`, in a separate non-autoloaded row; the split is the
 * whole point.
 */
final class Settings {

	/**
	 * Option name.
	 */
	public const OPTION = 'stg_moyasar_settings';

	/**
	 * Runtime cache.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'update_option_' . self::OPTION, array( self::class, 'flush' ) );
		add_action( 'add_option_' . self::OPTION, array( self::class, 'flush' ) );
		add_filter( 'stg_plan_currency_payable', array( self::class, 'payable_filter' ), 10, 2 );
	}

	/**
	 * Core's question "can a plan in this currency be bought online?",
	 * answered from the currencies enabled on the merchant account.
	 *
	 * @param bool   $payable Core's answer (SAR only).
	 * @param string $code    ISO code.
	 * @return bool
	 */
	public static function payable_filter( bool $payable, string $code ): bool {
		unset( $payable );

		return self::accepts_currency( $code );
	}

	/**
	 * Shipped defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(

			/*
			 * Off, and test, on a fresh install.
			 *
			 * Two separate switches rather than one. "Is a provider configured"
			 * and "should customers be able to reach it" are different
			 * questions, and an administrator part-way through entering keys
			 * should not have a half-configured checkout live on the site.
			 */
			'enabled'            => false,
			'mode'               => Mode::TEST,

			/*
			 * Payment methods offered by the hosted form.
			 *
			 * Card is on; everything else is off, because everything else needs
			 * work on the client's Moyasar account (and, for Apple Pay, an Apple
			 * merchant identifier and a domain-verification file) that cannot be
			 * done from here. A method switched on without that groundwork
			 * renders a button that fails, which is worse than a button that is
			 * absent. The admin screen says so next to each one.
			 */
			'methods'            => array( 'creditcard' ),

			/*
			 * Card networks the form will accept.
			 *
			 * mada is not optional for a Saudi marketplace - it is the domestic
			 * debit scheme and a large share of cards in the market carry it.
			 * amex is off by default because acceptance has to be arranged
			 * separately and its pricing differs.
			 */
			'supported_networks' => array( 'mada', 'visa', 'mastercard' ),

			/*
			 * Currencies the merchant account takes, beyond SAR.
			 *
			 * Empty. A Moyasar account accepts SAR only until Moyasar turns on
			 * multi-currency at the merchant's request, for cards only, and
			 * settles every currency to the bank in SAR at its own rate
			 * (docs.moyasar.com, Multi-currency, checked 12 Oct 2026). Nothing
			 * in the API says in advance whether it is on, so the
			 * administrator says so here once Moyasar has confirmed it.
			 */
			'currencies'         => array(),

			/*
			 * How long an unpaid intent stays open.
			 *
			 * Long enough to read a 3-D Secure SMS, find the card, and be
			 * interrupted; short enough that a price change is not honoured for
			 * a day. Thirty minutes is also comfortably longer than Moyasar's
			 * own 3DS window, so a user who completes the challenge never
			 * returns to an expired intent.
			 */
			'intent_ttl_minutes' => 30,

			// Seconds to wait on the Moyasar API before giving up.
			'api_timeout'        => 15,

			/*
			 * Extra logging.
			 *
			 * Off by default. STG Core's logger already records every error and
			 * every state change; this adds request-level detail that is useful
			 * while integrating and noise afterwards. It never widens what may
			 * be logged - see Support\Log, which allow-lists fields rather than
			 * redacting them.
			 */
			'debug'              => false,
		);
	}

	/**
	 * All settings, defaults merged with stored values.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}

		return self::$cache;
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( string $key, mixed $default = null ): mixed {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Write one setting.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	public static function set( string $key, mixed $value ): bool {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$stored[ $key ] = $value;

		self::flush();

		return update_option( self::OPTION, $stored, true );
	}

	/**
	 * The mode in force.
	 *
	 * The fake client, when installed, overrides the stored setting - it is the
	 * only thing that can produce `Mode::FAKE`, and it cannot be installed on a
	 * site that could reach live Moyasar. See `Api\Fake_Client`.
	 *
	 * A wp-config constant overrides the option, so a staging clone of a
	 * production database cannot inherit live mode by accident.
	 *
	 * @return string
	 */
	public static function mode(): string {
		if ( Api\Fake_Client::is_installed() ) {
			return Mode::FAKE;
		}

		if ( defined( 'STG_MOYASAR_MODE' ) ) {
			return Mode::normalise( constant( 'STG_MOYASAR_MODE' ) );
		}

		return Mode::normalise( self::get( 'mode', Mode::TEST ) );
	}

	/**
	 * Whether checkout should be offered to customers at all.
	 *
	 * Every condition must hold. This is the single switch the gateway, the
	 * checkout screen and `GET /stg/v1/plans` all consult, so there is one
	 * answer rather than three that can disagree.
	 *
	 * @return bool
	 */
	public static function is_operational(): bool {
		if ( empty( self::get( 'enabled', false ) ) ) {
			return false;
		}

		$mode = self::mode();

		if ( Mode::FAKE === $mode ) {
			return true;
		}

		return Credentials::complete_for( $mode );
	}

	/**
	 * Payment methods to enable on the hosted form.
	 *
	 * Filtered against what Moyasar actually supports, so a hand-edited option
	 * row cannot put an unknown string into the form's configuration.
	 *
	 * @return string[]
	 */
	public static function methods(): array {
		$allowed = array_keys( self::available_methods() );
		$chosen  = array_values( array_intersect( (array) self::get( 'methods', array() ), $allowed ) );

		// Never render a form with nothing on it.
		return $chosen ? $chosen : array( 'creditcard' );
	}

	/**
	 * Methods that need options this plugin does not send.
	 *
	 * Moyasar's form refuses to start at all when Apple Pay is listed without
	 * its `apple_pay` options (label, merchant validation URL, country), and
	 * Samsung Pay likewise needs `samsung_pay`. The refusal takes cards down
	 * with it: on beta, 19 Oct 2026, one Apple Pay switch left checkout with no
	 * form. Until those options exist, these are never handed to the form.
	 */
	private const NEEDS_WALLET_OPTIONS = array( 'applepay', 'samsungpay' );

	/**
	 * Methods to hand Moyasar's form: the saved choice, less any that would
	 * stop the form from starting.
	 *
	 * The settings screen keeps showing the saved choice (`methods()`); only
	 * the checkout uses this.
	 *
	 * @return string[]
	 */
	public static function form_methods(): array {
		$usable = array_values( array_diff( self::methods(), self::NEEDS_WALLET_OPTIONS ) );

		return $usable ? $usable : array( 'creditcard' );
	}

	/**
	 * Card networks to accept.
	 *
	 * @return string[]
	 */
	public static function supported_networks(): array {
		$allowed = array_keys( self::available_networks() );
		$chosen  = array_values( array_intersect( (array) self::get( 'supported_networks', array() ), $allowed ) );

		return $chosen ? $chosen : array( 'mada', 'visa', 'mastercard' );
	}

	/**
	 * Currencies checkout may charge in: SAR always, plus what the
	 * administrator has confirmed is enabled on the Moyasar account.
	 *
	 * @return string[]
	 */
	public static function currencies(): array {
		$extra = array_intersect( array_map( 'strtoupper', array_map( 'strval', (array) self::get( 'currencies', array() ) ) ), self::optional_currencies() );

		return array_values( array_unique( array_merge( array( 'SAR' ), $extra ) ) );
	}

	/**
	 * Whether checkout may charge in this currency.
	 *
	 * @param string $code ISO code.
	 * @return bool
	 */
	public static function accepts_currency( string $code ): bool {
		return in_array( strtoupper( $code ), self::currencies(), true );
	}

	/**
	 * Currencies a plan may be priced in that need Moyasar to enable them:
	 * STG's supported list (Core) less SAR. Moyasar's own list (SAR, AED,
	 * BHD, KWD, QAR, USD) is wider; STG prices in SAR and USD only.
	 *
	 * @return string[]
	 */
	public static function optional_currencies(): array {
		return array_values( array_diff( \STG\Core\Support\Money::SUPPORTED, array( 'SAR' ) ) );
	}

	/**
	 * Every payment method Moyasar's form supports, with what each one needs.
	 *
	 * The notes are shown beside each toggle in wp-admin. They exist because
	 * "why doesn't the Apple Pay button work" is otherwise a support ticket
	 * rather than a sentence somebody already read.
	 *
	 * @return array<string, array{label: string, note: string}>
	 */
	public static function available_methods(): array {
		return array(
			'creditcard' => array(
				'label' => __( 'Cards (mada, Visa, Mastercard)', 'stg-moyasar-payments' ),
				'note'  => __( 'Works as soon as your API keys are entered. This is the method most customers in Saudi Arabia will use.', 'stg-moyasar-payments' ),
			),
			'stcpay'     => array(
				'label' => __( 'STC Pay', 'stg-moyasar-payments' ),
				'note'  => __( 'Moyasar must enable STC Pay on your merchant account before this will work. Ask Moyasar support to confirm it is active, then switch it on here.', 'stg-moyasar-payments' ),
			),
			'applepay'   => array(
				'label' => __( 'Apple Pay', 'stg-moyasar-payments' ),
				'note'  => __( 'Needs more than a switch: an Apple developer merchant identifier, a domain-verification file served from this site, and Apple Pay enabled on your Moyasar account. Leave this off until that is arranged — on its own it renders a button that cannot complete a payment.', 'stg-moyasar-payments' ),
			),
			'samsungpay' => array(
				'label' => __( 'Samsung Pay', 'stg-moyasar-payments' ),
				'note'  => __( 'Needs a Samsung Pay service identifier and Samsung Pay enabled on your Moyasar account.', 'stg-moyasar-payments' ),
			),
		);
	}

	/**
	 * Card networks Moyasar's form can accept.
	 *
	 * @return array<string, string>
	 */
	public static function available_networks(): array {
		return array(
			'mada'       => __( 'mada', 'stg-moyasar-payments' ),
			'visa'       => __( 'Visa', 'stg-moyasar-payments' ),
			'mastercard' => __( 'Mastercard', 'stg-moyasar-payments' ),
			'amex'       => __( 'American Express', 'stg-moyasar-payments' ),
			'unionpay'   => __( 'UnionPay', 'stg-moyasar-payments' ),
		);
	}

	/**
	 * Clear the runtime cache.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$cache = null;
	}
}
