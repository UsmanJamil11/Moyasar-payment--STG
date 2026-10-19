<?php
/**
 * Payment persistence.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar\Payments;

use STG\Core\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes `wp_stg_payments`.
 *
 * Every method that changes a payment's status is a compare-and-set: the status
 * the caller believes the row is in goes into the WHERE clause, and the caller
 * must check that exactly one row was affected. That is not defensive style,
 * it is the mechanism. A browser callback and a webhook can arrive at the same
 * instant from different processes, and the only thing standing between that
 * and a double activation is MySQL's row lock on a single UPDATE.
 *
 * The rule the rest of the plugin depends on: **whoever wins the CAS owns the
 * activation, and nobody else may act.**
 */
final class Repository {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		return Schema::table( 'payments' );
	}

	/**
	 * Cast a raw row.
	 *
	 * @param array<string, mixed>|null $row Raw row.
	 * @return array<string, mixed>|null
	 */
	private static function hydrate( ?array $row ): ?array {
		if ( ! $row ) {
			return null;
		}

		foreach ( array( 'id', 'user_id', 'amount', 'period_days', 'subscription_id', 'reviewed_by' ) as $int ) {
			if ( isset( $row[ $int ] ) ) {
				$row[ $int ] = null === $row[ $int ] ? null : (int) $row[ $int ];
			}
		}

		return $row;
	}

	/**
	 * An unguessable public reference.
	 *
	 * Thirty-two hex characters from the CSPRNG. It appears in the checkout URL
	 * and in the metadata sent to Moyasar, so it must not be enumerable - a
	 * sequential id in that position lets somebody walk other people's
	 * checkouts. Being unguessable is not an authorisation model, though: every
	 * screen that loads one still checks it belongs to the current user.
	 *
	 * @return string
	 */
	public static function new_reference(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Create an intent, or return the open one that already exists.
	 *
	 * `INSERT ... ON DUPLICATE KEY UPDATE id = id` rather than a bare INSERT,
	 * for the reason `Subscriptions\Repository::insert()` gives: the unique
	 * constraint on open intents is EXPECTED to refuse the second of two
	 * simultaneous attempts, and letting that surface as a database error would
	 * log a failure for something working exactly as designed.
	 *
	 * A refusal is not an error here. It means another request - a double
	 * click, a second tab, a retried form post - already made this intent, and
	 * the caller should use that one.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return int New id, or 0 when an open intent already exists.
	 */
	public static function insert( array $data ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; name from a constant.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO `' . self::table() . '`'
				. ' (public_ref, user_id, plan_slug, amount, currency, period_days, provider, mode, status, expires_at, created_at, updated_at)'
				. ' VALUES (%s, %d, %s, %s, %s, %d, %s, %s, %s, %s, %s, %s)'
				. ' ON DUPLICATE KEY UPDATE id = id',
				(string) $data['public_ref'],
				(int) $data['user_id'],
				(string) $data['plan_slug'],
				(string) $data['amount'],
				(string) $data['currency'],
				(int) ( $data['period_days'] ?? 30 ),
				(string) ( $data['provider'] ?? 'moyasar' ),
				(string) $data['mode'],
				Status::PENDING,
				(string) $data['expires_at'],
				$now,
				$now
			)
		);

		return 1 === (int) $wpdb->rows_affected ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Fetch by internal id.
	 *
	 * @param int $id Payment id.
	 * @return array<string, mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;

		if ( $id < 1 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		return self::hydrate(
			$wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM `' . self::table() . '` WHERE id = %d', $id ),
				ARRAY_A
			)
		);
	}

	/**
	 * Fetch by public reference.
	 *
	 * @param string $reference Public reference.
	 * @return array<string, mixed>|null
	 */
	public static function find_by_reference( string $reference ): ?array {
		global $wpdb;

		if ( ! preg_match( '/^[a-f0-9]{32}$/', $reference ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		return self::hydrate(
			$wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM `' . self::table() . '` WHERE public_ref = %s', $reference ),
				ARRAY_A
			)
		);
	}

	/**
	 * Fetch by the provider's payment id.
	 *
	 * Scoped by mode as well as provider, because a test payment and a live
	 * payment are different universes that happen to share an id space.
	 *
	 * @param string $provider_payment_id Provider id.
	 * @param string $mode                Mode.
	 * @param string $provider            Provider slug.
	 * @return array<string, mixed>|null
	 */
	public static function find_by_provider_id( string $provider_payment_id, string $mode, string $provider = 'moyasar' ): ?array {
		global $wpdb;

		if ( '' === trim( $provider_payment_id ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		return self::hydrate(
			$wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM `' . self::table() . '` WHERE provider = %s AND mode = %s AND provider_payment_id = %s',
					$provider,
					$mode,
					$provider_payment_id
				),
				ARRAY_A
			)
		);
	}

	/**
	 * The open intent for a user and plan, if there is one.
	 *
	 * @param int    $user_id   User.
	 * @param string $plan_slug Plan.
	 * @return array<string, mixed>|null
	 */
	public static function open_intent( int $user_id, string $plan_slug ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		return self::hydrate(
			$wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM `' . self::table() . '` WHERE user_id = %d AND plan_slug = %s AND status = %s LIMIT 1',
					$user_id,
					$plan_slug,
					Status::PENDING
				),
				ARRAY_A
			)
		);
	}

	/**
	 * Claim a pending intent as paid. The mutex.
	 *
	 * One statement, and everything that matters is in the WHERE clause. The
	 * winner gets `rows_affected === 1` and owns the activation; everybody else
	 * gets 0 and must re-read rather than act.
	 *
	 * `AND mode = %s` is not redundant. It means a row minted in test mode can
	 * never be settled by live code, even if somebody flips the switch while a
	 * payment is in flight.
	 *
	 * Writing `provider_payment_id` here can violate the unique index on
	 * (provider, mode, provider_payment_id) if that Moyasar payment is already
	 * bound to a different intent. That is a genuine double-charge, and the
	 * caller checks for it before getting here so it can be flagged rather than
	 * surfacing as a raw database error.
	 *
	 * @param int    $id                  Payment id.
	 * @param string $mode                Expected mode.
	 * @param string $provider_payment_id Provider id.
	 * @param string $provider_status     Provider's own status string.
	 * @param string $source_type         Payment method category.
	 * @return bool Whether this caller won.
	 */
	public static function claim_paid( int $id, string $mode, string $provider_payment_id, string $provider_status, string $source_type ): bool {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . self::table() . '`'
				. ' SET status = %s, provider_payment_id = %s, provider_status = %s, source_type = %s,'
				. ' paid_at = %s, verified_at = %s, updated_at = %s'
				. ' WHERE id = %d AND status = %s AND mode = %s',
				Status::PAID,
				$provider_payment_id,
				$provider_status,
				'' !== $source_type ? $source_type : null,
				$now,
				$now,
				$now,
				$id,
				Status::PENDING,
				$mode
			)
		);

		return 1 === (int) $updated;
	}

	/**
	 * Move a payment from one status to another.
	 *
	 * @param int    $id   Payment id.
	 * @param string $from Expected status.
	 * @param string $to   New status.
	 * @param array<string, mixed> $extra Additional columns to set.
	 * @return bool
	 */
	public static function transition( int $id, string $from, string $to, array $extra = array() ): bool {
		global $wpdb;

		$sets   = array( 'status = %s', 'updated_at = %s' );
		$values = array( $to, current_time( 'mysql', true ) );

		foreach ( $extra as $column => $value ) {
			if ( ! preg_match( '/^[a-z_]+$/', $column ) ) {
				continue;
			}

			$sets[]   = '`' . $column . '` = %s';
			$values[] = null === $value ? null : (string) $value;
		}

		$values[] = $id;
		$values[] = $from;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column names are validated against a whitelist pattern above; values are placeholders.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . self::table() . '` SET ' . implode( ', ', $sets ) . ' WHERE id = %d AND status = %s',
				$values
			)
		);

		return 1 === (int) $updated;
	}

	/**
	 * Bind a subscription to the payment that bought it.
	 *
	 * Guarded by `subscription_id IS NULL` and backed by a unique index, so one
	 * subscription can be claimed by at most one payment and one payment can
	 * point at no more than one subscription.
	 *
	 * @param int $id              Payment id.
	 * @param int $subscription_id Subscription id.
	 * @return bool
	 */
	public static function bind_subscription( int $id, int $subscription_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . self::table() . '` SET subscription_id = %d, updated_at = %s WHERE id = %d AND subscription_id IS NULL',
				$subscription_id,
				current_time( 'mysql', true ),
				$id
			)
		);

		return 1 === (int) $updated;
	}

	/**
	 * Record that a channel reported on this payment.
	 *
	 * Purely observational. It answers "did the webhook ever arrive?" on the
	 * admin screen, which is the first question when something looks stuck, and
	 * it never influences a decision.
	 *
	 * @param int    $id      Payment id.
	 * @param string $channel 'callback' or 'webhook'.
	 * @return void
	 */
	public static function note_channel( int $id, string $channel ): void {
		global $wpdb;

		// The sweep and a staff re-check are neither: stamping them as the
		// browser made a recovered payment read "reported by: browser".
		if ( ! in_array( $channel, array( 'webhook', 'callback' ), true ) ) {
			return;
		}

		$column = 'webhook' === $channel ? 'webhook_seen_at' : 'callback_seen_at';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			self::table(),
			array( $column => current_time( 'mysql', true ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Remember which provider payment the customer's browser just started.
	 *
	 * A hint and nothing more: it came from a browser, so it is never compared,
	 * never bound and never unique. Its one use is telling the reconciler which
	 * payment to ask the provider about when the customer never came back from
	 * their bank's page. `provider_payment_id` stays empty until the provider
	 * itself has confirmed a payment.
	 *
	 * @param int    $id         Payment id.
	 * @param string $payment_id Provider payment id the browser reported.
	 * @return bool Whether an open checkout took the hint.
	 */
	public static function note_attempt( int $id, string $payment_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . self::table() . '` SET attempt_payment_id = %s, updated_at = %s WHERE id = %d AND status = %s',
				$payment_id,
				current_time( 'mysql', true ),
				$id,
				Status::PENDING
			)
		);

		return false !== $updated && (int) $updated > 0;
	}

	/**
	 * Record a failure's provider-supplied detail.
	 *
	 * The message is stored for an administrator, never shown to a customer:
	 * it is English-only provider text that frequently names internals.
	 *
	 * @param int    $id      Payment id.
	 * @param string $code    Provider failure code.
	 * @param string $message Provider failure message.
	 * @return void
	 */
	public static function note_failure( int $id, string $code, string $message ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			self::table(),
			array(
				'failure_code'    => '' !== $code ? substr( $code, 0, 64 ) : null,
				'failure_message' => '' !== $message ? substr( $message, 0, 255 ) : null,
				'updated_at'      => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Insert a row for a paid payment that belongs to no known intent.
	 *
	 * This is the double-charge record. It exists so that money which arrived
	 * without a matching intent leaves a trace somebody can act on, instead of
	 * being dropped because it did not fit the happy path.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return int New id, or 0.
	 */
	public static function insert_orphan( array $data ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			self::table(),
			array(
				'public_ref'          => self::new_reference(),
				'user_id'             => (int) ( $data['user_id'] ?? 0 ),
				'plan_slug'           => (string) ( $data['plan_slug'] ?? '' ),
				'amount'              => (string) ( $data['amount'] ?? 0 ),
				'currency'            => (string) ( $data['currency'] ?? 'SAR' ),
				'period_days'         => 0,
				'provider'            => (string) ( $data['provider'] ?? 'moyasar' ),
				'provider_payment_id' => (string) ( $data['provider_payment_id'] ?? '' ),
				'provider_status'     => (string) ( $data['provider_status'] ?? '' ),
				'mode'                => (string) $data['mode'],
				'status'              => Status::FLAGGED,
				'flag_reason'         => (string) ( $data['flag_reason'] ?? 'unattributable' ),
				'expires_at'          => $now,
				'created_at'          => $now,
				'updated_at'          => $now,
			)
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Pending intents whose time has run out.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function stale( int $limit = 200 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM `' . self::table() . '`'
				. ' WHERE status = %s AND expires_at < %s ORDER BY id ASC LIMIT %d',
				Status::PENDING,
				current_time( 'mysql', true ),
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		);

		return array_values( array_filter( array_map( array( self::class, 'hydrate' ), (array) $rows ) ) );
	}

	/**
	 * Pending intents old enough to be worth asking the provider about.
	 *
	 * The reconciler's input. A payment that succeeded while the customer's
	 * connection dropped, and whose webhook never arrived, lives here until
	 * somebody goes and looks.
	 *
	 * @param int $older_than_seconds Minimum age.
	 * @param int $limit              Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function unreconciled( int $older_than_seconds = 300, int $limit = 50 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM `' . self::table() . '`'
				. ' WHERE status = %s AND created_at < %s AND created_at > %s'
				. ' ORDER BY id ASC LIMIT %d',
				Status::PENDING,
				gmdate( 'Y-m-d H:i:s', time() - max( 60, $older_than_seconds ) ),
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				max( 1, min( 200, $limit ) )
			),
			ARRAY_A
		);

		return array_values( array_filter( array_map( array( self::class, 'hydrate' ), (array) $rows ) ) );
	}

	/**
	 * A user's payments, newest first.
	 *
	 * @param int $user_id User.
	 * @param int $limit   Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_user( int $user_id, int $limit = 20 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM `' . self::table() . '` WHERE user_id = %d ORDER BY id DESC LIMIT %d',
				$user_id,
				max( 1, min( 100, $limit ) )
			),
			ARRAY_A
		);

		return array_values( array_filter( array_map( array( self::class, 'hydrate' ), (array) $rows ) ) );
	}

	/**
	 * A filtered, paginated page of payments for the admin screen.
	 *
	 * @param array<string, mixed> $filters Supported: status, mode, search.
	 * @param int                  $page    1-based page.
	 * @param int                  $per_page Page size.
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public static function paginate( array $filters, int $page = 1, int $per_page = 25 ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $filters['status'] ) && in_array( $filters['status'], Status::all(), true ) ) {
			$where[]  = 'status = %s';
			$values[] = (string) $filters['status'];
		}

		if ( ! empty( $filters['mode'] ) && in_array( $filters['mode'], \STG\Moyasar\Mode::all(), true ) ) {
			$where[]  = 'mode = %s';
			$values[] = (string) $filters['mode'];
		}

		if ( ! empty( $filters['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where[]  = '( public_ref LIKE %s OR provider_payment_id LIKE %s )';
			$values[] = $like;
			$values[] = $like;
		}

		$clause = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Clause built from a fixed set of literals; all values are placeholders.
		$total = (int) $wpdb->get_var(
			$values
				? $wpdb->prepare( 'SELECT COUNT(*) FROM `' . self::table() . '` WHERE ' . $clause, $values )
				: 'SELECT COUNT(*) FROM `' . self::table() . '`'
		);

		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = max( 0, ( max( 1, $page ) - 1 ) * $per_page );

		$page_values = array_merge( $values, array( $per_page, $offset ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM `' . self::table() . '` WHERE ' . $clause . ' ORDER BY id DESC LIMIT %d OFFSET %d',
				$page_values
			),
			ARRAY_A
		);

		return array(
			'rows'  => array_values( array_filter( array_map( array( self::class, 'hydrate' ), (array) $rows ) ) ),
			'total' => $total,
		);
	}

	/**
	 * Count rows in a status.
	 *
	 * @param string $status Status.
	 * @return int
	 */
	public static function count_by_status( string $status ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM `' . self::table() . '` WHERE status = %s', $status )
		);
	}

	/**
	 * Flagged payments nobody has dealt with yet.
	 *
	 * @return int
	 */
	public static function count_needing_review(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM `' . self::table() . '` WHERE status = %s AND reviewed_at IS NULL',
				Status::FLAGGED
			)
		);
	}

	/**
	 * Record that a person has dealt with a flagged payment.
	 *
	 * The status stays `flagged`: what happened to the money is still what
	 * happened. This only says somebody looked, when, and what they did, so
	 * the row stops being counted as waiting.
	 *
	 * @param int    $id    Row id.
	 * @param int    $actor Staff user id.
	 * @param string $note  What was done about it.
	 * @return bool False when the row is not flagged or was already dealt with.
	 */
	public static function mark_reviewed( int $id, int $actor, string $note ): bool {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$changed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . self::table() . '`
				 SET reviewed_at = %s, reviewed_by = %d, review_note = %s, updated_at = %s
				 WHERE id = %d AND status = %s AND reviewed_at IS NULL',
				$now,
				$actor,
				$note,
				$now,
				$id,
				Status::FLAGGED
			)
		);

		return 1 === (int) $changed;
	}

	/**
	 * Whether any payment is still open and older than a cutoff.
	 *
	 * Part of the live-mode preflight: flipping to live while payments are
	 * stranded in flight means those customers settle against the wrong world.
	 *
	 * @param int $older_than_seconds Age threshold.
	 * @return int
	 */
	public static function count_stranded( int $older_than_seconds = DAY_IN_SECONDS ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM `' . self::table() . '` WHERE status = %s AND created_at < %s',
				Status::PENDING,
				gmdate( 'Y-m-d H:i:s', time() - $older_than_seconds )
			)
		);
	}
}
