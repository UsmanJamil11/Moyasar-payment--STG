<?php
/**
 * Schema, activation and deactivation.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar;

use RuntimeException;
use STG\Core\Database\Schema;
use STG\Moyasar\Payments\Status;
use STG\Moyasar\Support\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates `wp_stg_payments` and keeps it correct.
 *
 * Versioned independently of STG Core. Core's migrator runs a numbered sequence
 * against `stg_core_schema_version`; borrowing that counter from another plugin
 * would mean two independently-released artefacts incrementing the same number,
 * which goes wrong the first time they are deployed out of order. This plugin
 * owns `stg_moyasar_schema_version` and nothing else touches it.
 *
 * Both activation and deactivation are idempotent. Deactivation removes
 * scheduled events and nothing else: no table, no option, no subscription. A
 * plugin that destroys payment records when it is switched off is a plugin
 * nobody dares switch off, and these records are what a billing dispute is
 * settled with.
 */
final class Installer {

	/**
	 * Option holding the schema version.
	 */
	public const VERSION_OPTION = 'stg_moyasar_schema_version';

	/**
	 * Current schema version.
	 */
	public const SCHEMA_VERSION = 3;

	/**
	 * Activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		$problems = runtime_problems();

		if ( $problems ) {
			deactivate_plugins( plugin_basename( STG_MOYASAR_FILE ) );

			wp_die(
				esc_html( implode( ' ', $problems ) ),
				esc_html__( 'STG Moyasar Payments cannot be activated', 'stg-moyasar-payments' ),
				array( 'back_link' => true )
			);
		}

		self::migrate();

		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', true );
		}

		/*
		 * Grant the capability here as well as in Core's Roles::install().
		 *
		 * Core only adds capabilities when Core itself is activated, and this
		 * plugin is typically switched on long afterwards. Without this, the
		 * payments screens are invisible to the administrator who just
		 * activated them, which reads as a broken plugin.
		 */
		$admin = get_role( 'administrator' );

		if ( $admin ) {
			$admin->add_cap( 'stg_manage_payments' );
		}

		// The checkout routes are Core's, but they may not have been flushed
		// since Core added them.
		flush_rewrite_rules();

		update_option( 'stg_moyasar_version', VERSION, false );
	}

	/**
	 * Deactivation.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Plugin::RECONCILE_HOOK );
	}

	/**
	 * Whether the schema is behind.
	 *
	 * @return bool
	 */
	public static function needs_migration(): bool {
		return (int) get_option( self::VERSION_OPTION, 0 ) < self::SCHEMA_VERSION;
	}

	/**
	 * Bring the schema up to date.
	 *
	 * Safe to call repeatedly: `dbDelta` is idempotent, the generated column and
	 * both unique indexes are each guarded by an existence check, and the
	 * version option is only advanced once everything is confirmed present.
	 *
	 * @return void
	 * @throws RuntimeException When a constraint cannot be created.
	 */
	public static function migrate(): void {
		if ( ! self::needs_migration() ) {
			return;
		}

		/*
		 * The plain table first, via dbDelta.
		 *
		 * The generated column is NOT in this definition on purpose. dbDelta
		 * cannot parse `GENERATED ALWAYS AS ... STORED`; it would read the
		 * column as missing on every subsequent run and try to "fix" it. The
		 * same reasoning, and the same shape of solution, as
		 * Migration_0012_Subscriptions.
		 *
		 * There are no cardholder columns here, and that is the design rather
		 * than an omission. No PAN, no truncated PAN, no expiry, no CVV, no
		 * cardholder name, no billing address, no bank detail. `source_type`
		 * records the METHOD CATEGORY - 'creditcard', 'stcpay' - which answers
		 * every reporting question STG actually has and is not cardholder data
		 * at all. The moment this table could hold a card number, every backup,
		 * every export and every developer's local copy becomes PCI scope.
		 */
		Schema::create(
			'payments',
			"id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_ref CHAR(32) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			plan_slug VARCHAR(50) NOT NULL,
			amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
			currency CHAR(3) NOT NULL DEFAULT 'SAR',
			period_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
			provider VARCHAR(30) NOT NULL DEFAULT 'moyasar',
			provider_payment_id VARCHAR(64) NULL DEFAULT NULL,
			provider_status VARCHAR(20) NULL DEFAULT NULL,
			attempt_payment_id VARCHAR(64) NULL DEFAULT NULL,
			source_type VARCHAR(20) NULL DEFAULT NULL,
			mode VARCHAR(4) NOT NULL DEFAULT 'test',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			subscription_id BIGINT UNSIGNED NULL DEFAULT NULL,
			flag_reason VARCHAR(64) NULL DEFAULT NULL,
			reviewed_at DATETIME NULL DEFAULT NULL,
			reviewed_by BIGINT UNSIGNED NULL DEFAULT NULL,
			review_note VARCHAR(255) NULL DEFAULT NULL,
			failure_code VARCHAR(64) NULL DEFAULT NULL,
			failure_message VARCHAR(255) NULL DEFAULT NULL,
			callback_seen_at DATETIME NULL DEFAULT NULL,
			webhook_seen_at DATETIME NULL DEFAULT NULL,
			verified_at DATETIME NULL DEFAULT NULL,
			paid_at DATETIME NULL DEFAULT NULL,
			refunded_at DATETIME NULL DEFAULT NULL,
			expires_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_public_ref (public_ref),
			KEY idx_user (user_id, status),
			KEY idx_status (status, created_at),
			KEY idx_mode (mode, status),
			KEY idx_expiry (status, expires_at),
			KEY idx_flag (flag_reason, created_at)"
		);

		self::enforce_one_open_intent();
		self::enforce_one_payment_per_provider_id();
		self::enforce_one_payment_per_subscription();

		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );

		Log::info( 'Payments schema installed.', array( 'version' => self::SCHEMA_VERSION ) );
	}

	/**
	 * One open intent per user per plan, enforced by the database.
	 *
	 * The `live_flag` technique from Migration_0012, applied to a different
	 * question: 1 while the intent is open, NULL once it is not, and MySQL
	 * permits repeated NULLs in a unique index - so the constraint binds only
	 * open rows while unlimited history stays allowed.
	 *
	 * This is what makes a double-click, a second browser tab and a refreshed
	 * form post produce ONE intent rather than three. It matters more than it
	 * looks: three intents means three prices quoted, three references in play,
	 * and a customer who can pay two of them.
	 *
	 * The status list below is duplicated from `Status::PENDING`. If open ever
	 * stops meaning exactly `pending`, it MUST change here too.
	 *
	 * @return void
	 * @throws RuntimeException When the constraint cannot be created.
	 */
	private static function enforce_one_open_intent(): void {
		global $wpdb;

		$table = Schema::table( 'payments' );

		if ( ! Schema::has_column( 'payments', 'open_flag' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL; identifiers are constants.
			$wpdb->query(
				"ALTER TABLE `{$table}`
				 ADD COLUMN open_flag TINYINT UNSIGNED
				 GENERATED ALWAYS AS (
					CASE WHEN status = 'pending' THEN 1 ELSE NULL END
				 ) STORED"
			);
		}

		self::add_unique_index( 'payments', 'uq_open_intent', '(user_id, plan_slug, open_flag)' );
	}

	/**
	 * One provider payment id binds to at most one row.
	 *
	 * The backstop against a replayed webhook or a duplicated callback creating
	 * a second record for the same money. Rows with no provider id yet carry
	 * NULL and coexist freely, because a composite unique index in MySQL
	 * exempts any row with a NULL component.
	 *
	 * `mode` is part of the key deliberately: a test payment and a live payment
	 * are different universes that happen to share an id space, and a collision
	 * between them is not a conflict.
	 *
	 * @return void
	 * @throws RuntimeException When the constraint cannot be created.
	 */
	private static function enforce_one_payment_per_provider_id(): void {
		self::add_unique_index( 'payments', 'uq_provider_payment', '(provider, mode, provider_payment_id)' );
	}

	/**
	 * One subscription is claimed by at most one payment.
	 *
	 * No generated column needed: the column is already nullable, and "unique
	 * among the non-NULLs" is exactly what a plain unique index gives you.
	 *
	 * Together with the compare-and-set in `Repository::claim_paid()`, this is
	 * what makes double activation impossible rather than merely unlikely. The
	 * CAS is the mechanism; this is the proof that the mechanism held.
	 *
	 * @return void
	 * @throws RuntimeException When the constraint cannot be created.
	 */
	private static function enforce_one_payment_per_subscription(): void {
		self::add_unique_index( 'payments', 'uq_subscription', '(subscription_id)' );
	}

	/**
	 * Add a unique index, and refuse to continue if it is not there afterwards.
	 *
	 * The confirmation step is the important half. A silently-missing unique
	 * index on this table is the one failure mode that produces duplicate
	 * charges with nothing in the log to explain them, so the migration throws
	 * rather than recording a version it did not achieve.
	 *
	 * @param string $table_name Bare table name.
	 * @param string $index_name Index name.
	 * @param string $columns    Parenthesised column list.
	 * @return void
	 * @throws RuntimeException When the index is absent afterwards.
	 */
	private static function add_unique_index( string $table_name, string $index_name, string $columns ): void {
		global $wpdb;

		$table = Schema::table( $table_name );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection.
		$exists = $wpdb->get_var(
			$wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $index_name )
		);

		if ( ! $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL; identifiers are validated constants from this class.
			$wpdb->query( "ALTER TABLE `{$table}` ADD UNIQUE KEY {$index_name} {$columns}" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection.
		$confirmed = $wpdb->get_var(
			$wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $index_name )
		);

		if ( ! $confirmed ) {
			throw new RuntimeException(
				sprintf(
					'Could not create the unique constraint %s on %s. Without it, duplicate payments are possible. Last error: %s',
					$index_name,
					$table,
					$wpdb->last_error ? $wpdb->last_error : 'none reported'
				)
			);
		}
	}

	/**
	 * Statuses the schema knows about, for a sanity assertion in tests.
	 *
	 * @return string[]
	 */
	public static function known_statuses(): array {
		return Status::all();
	}
}
