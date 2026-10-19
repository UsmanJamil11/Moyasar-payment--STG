<?php
/**
 * The payment transactions screen.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar\Admin;

use STG\Core\Subscriptions\Plans;
use STG\Core\Subscriptions\Repository as Subscription_Repository;
use STG\Moyasar\Mode;
use STG\Moyasar\Payments\Repository;
use STG\Moyasar\Payments\Settlement;
use STG\Moyasar\Payments\Status;
use STG\Moyasar\Support\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What was charged, to whom, in which environment, and what happened next.
 *
 * **There is no refund button, and that is a decision.** Moyasar's API supports
 * refunds; STG deliberately does not expose them. Refunds at this volume are
 * occasional goodwill decisions, and putting a money-moving control into
 * wp-admin widens the blast radius of any compromised administrator account in
 * exchange for a convenience nobody asked for. Refunds are issued from the
 * Moyasar dashboard by whoever is authorised to move money; STG's job is to
 * notice, which it does through the refund webhook.
 *
 * The one action offered is "re-check with Moyasar", and it runs the *same*
 * settlement code the webhook runs — so a manual reconciliation tool can never
 * drift away from what production actually does.
 */
final class Transactions_Screen extends Screen {

	/**
	 * Rows per page.
	 */
	private const PER_PAGE = 25;

	/**
	 * Page slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'stg-payments-transactions';
	}

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public function page_title(): string {
		return __( 'Transactions', 'stg-moyasar-payments' );
	}

	/**
	 * Menu title.
	 *
	 * @return string
	 */
	public function menu_title(): string {
		return __( 'Transactions', 'stg-moyasar-payments' );
	}

	/**
	 * Register POST handlers.
	 *
	 * @return void
	 */
	public function register_handlers(): void {
		add_action( 'admin_post_stg_moyasar_recheck', array( $this, 'recheck' ) );
		add_action( 'admin_post_stg_moyasar_attach', array( $this, 'attach' ) );
		add_action( 'admin_post_stg_moyasar_review', array( $this, 'review' ) );
	}

	/**
	 * Notices the two screens share.
	 *
	 * @return array<string, array{type: string, text: string}>
	 */
	private function notices(): array {
		return array(
			'rechecked'    => array(
				'type' => 'success',
				'text' => __( 'Re-checked with Moyasar. The record now reflects what they reported.', 'stg-moyasar-payments' ),
			),
			'attached'     => array(
				'type' => 'success',
				'text' => __( 'Moyasar knows that payment and it now belongs to this checkout. The record shows what they reported.', 'stg-moyasar-payments' ),
			),
			'not_attached' => array(
				'type' => 'error',
				'text' => __( 'That payment was not attached. Either Moyasar does not know the id in this environment, or the payment belongs to a different checkout. Nothing was changed here.', 'stg-moyasar-payments' ),
			),
			'bad_id'       => array(
				'type' => 'error',
				'text' => __( 'That does not look like a Moyasar payment id. Copy it from the payment’s page in the Moyasar dashboard.', 'stg-moyasar-payments' ),
			),
			'reviewed'     => array(
				'type' => 'success',
				'text' => __( 'Marked as dealt with. It no longer counts as waiting.', 'stg-moyasar-payments' ),
			),
			'note_needed'  => array(
				'type' => 'error',
				'text' => __( 'Write what was done about it first, so the next person can see.', 'stg-moyasar-payments' ),
			),
		);
	}

	/**
	 * Render either the list or one payment.
	 *
	 * @return void
	 */
	public function render(): void {
		$this->require_permission();

		echo '<div class="wrap stg-pay">';

		$reference = $this->query( 'payment' );

		if ( preg_match( '/^[a-f0-9]{32}$/', $reference ) ) {
			$this->render_detail( $reference );
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	/**
	 * The filtered, paginated list.
	 *
	 * @return void
	 */
	private function render_list(): void {
		$filters = array(
			'status' => $this->query( 'status' ),
			'mode'   => $this->query( 'mode' ),
			'search' => $this->query( 's' ),
		);

		$page   = max( 1, (int) $this->query( 'paged', '1' ) );
		$result = Repository::paginate( $filters, $page, self::PER_PAGE );

		$this->header(
			array(
				'subtitle' => __( 'Every checkout a customer has started, successful or not.', 'stg-moyasar-payments' ),
			)
		);

		$this->print_notice( $this->notices() );

		$statuses = array();

		foreach ( Status::all() as $status ) {
			$statuses[ $status ] = Status::label( $status );
		}

		$modes = array();

		foreach ( Mode::all() as $mode ) {
			$modes[ $mode ] = Mode::label( $mode );
		}

		View::render(
			'transactions/list',
			array(
				'rows'       => $result['rows'],
				'total'      => $result['total'],
				'page'       => $page,
				'per_page'   => self::PER_PAGE,
				'filters'    => $filters,
				'filtered'   => (bool) array_filter( $filters ),
				'statuses'   => $statuses,
				'modes'      => $modes,
				'base_url'   => $this->url(),
				'detail_url' => fn( string $ref ): string => $this->url( '', array( 'payment' => $ref ) ),
			)
		);
	}

	/**
	 * One payment in full.
	 *
	 * @param string $reference Public reference.
	 * @return void
	 */
	private function render_detail( string $reference ): void {
		$row = Repository::find_by_reference( $reference );

		if ( ! $row ) {
			$this->header( array( 'subtitle' => '' ) );

			echo '<div class="stg-pay-empty"><h2>';
			esc_html_e( 'No such payment', 'stg-moyasar-payments' );
			echo '</h2><p>';
			esc_html_e( 'That reference does not match anything on this site.', 'stg-moyasar-payments' );
			echo '</p><p><a class="button" href="' . esc_url( $this->url() ) . '">';
			esc_html_e( 'Back to transactions', 'stg-moyasar-payments' );
			echo '</a></p></div>';

			return;
		}

		$this->header(
			array(
				'title'    => __( 'Payment', 'stg-moyasar-payments' ),
				'subtitle' => sprintf(
					/* translators: %s: short reference */
					__( 'Reference %s', 'stg-moyasar-payments' ),
					substr( $reference, 0, 12 )
				),
				'actions'  => sprintf(
					'<a class="page-title-action" href="%1$s">%2$s</a>',
					esc_url( $this->url() ),
					esc_html__( 'All transactions', 'stg-moyasar-payments' )
				),
			)
		);

		$this->print_notice( $this->notices() );

		$subscription_id = (int) ( $row['subscription_id'] ?? 0 );

		View::render(
			'transactions/detail',
			array(
				'row'          => $row,
				'user'         => get_userdata( (int) $row['user_id'] ),
				'plan'         => Plans::find( (string) $row['plan_slug'] ),
				'subscription' => $subscription_id > 0 ? Subscription_Repository::find( $subscription_id ) : null,
				'reviewer'     => ! empty( $row['reviewed_by'] ) ? get_userdata( (int) $row['reviewed_by'] ) : false,
				'timeline'     => $this->timeline( $row ),
				'back_url'     => $this->url(),
				'post_url'     => admin_url( 'admin-post.php' ),
			)
		);
	}

	/**
	 * Reconstruct what happened, from the row's own timestamps.
	 *
	 * Deliberately derived rather than stored. A separate event table would be
	 * a second source of truth about the same payment, needing its own writes,
	 * its own retention and its own chance to disagree with the record it
	 * describes — for a timeline that these six columns already imply.
	 *
	 * @param array<string, mixed> $row Payment row.
	 * @return array<int, array{label: string, when: string, state: string}>
	 */
	private function timeline( array $row ): array {
		$format = (string) get_option( 'date_format' ) . ' H:i';

		/**
		 * Format a stored datetime, or say it has not happened.
		 *
		 * @param mixed $value Datetime or null.
		 * @return string
		 */
		$at = static function ( $value ) use ( $format ): string {
			return empty( $value )
				? __( 'Not yet', 'stg-moyasar-payments' )
				: date_i18n( $format, (int) strtotime( (string) $value . ' UTC' ) );
		};

		$steps = array(
			array(
				'label' => __( 'Checkout started', 'stg-moyasar-payments' ),
				'when'  => $at( $row['created_at'] ),
				'state' => 'done',
			),
			array(
				'label' => __( 'Customer returned from Moyasar', 'stg-moyasar-payments' ),
				'when'  => $at( $row['callback_seen_at'] ),
				'state' => empty( $row['callback_seen_at'] ) ? 'todo' : 'done',
			),
			array(
				'label' => __( 'Webhook received', 'stg-moyasar-payments' ),
				'when'  => empty( $row['webhook_seen_at'] )
					? __( 'Not received — not required', 'stg-moyasar-payments' )
					: $at( $row['webhook_seen_at'] ),
				'state' => empty( $row['webhook_seen_at'] ) ? 'todo' : 'done',
			),
			array(
				'label' => __( 'Verified with Moyasar', 'stg-moyasar-payments' ),
				'when'  => $at( $row['verified_at'] ),
				'state' => empty( $row['verified_at'] ) ? 'todo' : 'done',
			),
		);

		$status = (string) $row['status'];

		if ( Status::PAID === $status ) {
			$steps[] = array(
				'label' => __( 'Payment confirmed', 'stg-moyasar-payments' ),
				'when'  => $at( $row['paid_at'] ),
				'state' => 'done',
			);

			$steps[] = array(
				'label' => empty( $row['subscription_id'] )
					? __( 'Subscription not granted', 'stg-moyasar-payments' )
					: __( 'Subscription activated', 'stg-moyasar-payments' ),
				'when'  => empty( $row['subscription_id'] )
					? __( 'Needs attention', 'stg-moyasar-payments' )
					: $at( $row['paid_at'] ),
				'state' => empty( $row['subscription_id'] ) ? 'bad' : 'done',
			);
		} elseif ( Status::FLAGGED === $status ) {
			$steps[] = array(
				'label' => __( 'Stopped for review', 'stg-moyasar-payments' ),
				'when'  => $at( $row['updated_at'] ),
				'state' => 'bad',
			);
		} elseif ( in_array( $status, array( Status::REFUNDED, Status::VOIDED ), true ) ) {
			$steps[] = array(
				'label' => __( 'Payment confirmed', 'stg-moyasar-payments' ),
				'when'  => $at( $row['paid_at'] ),
				'state' => 'done',
			);

			$steps[] = array(
				'label' => Status::REFUNDED === $status
					? __( 'Money returned, plan ended', 'stg-moyasar-payments' )
					: __( 'Charge reversed, plan ended', 'stg-moyasar-payments' ),
				'when'  => $at( $row['refunded_at'] ?? $row['updated_at'] ),
				'state' => 'bad',
			);
		} elseif ( Status::FAILED === $status ) {
			$steps[] = array(
				'label' => __( 'Declined by the bank', 'stg-moyasar-payments' ),
				'when'  => $at( $row['updated_at'] ),
				'state' => 'bad',
			);
		} elseif ( Status::EXPIRED === $status ) {
			$steps[] = array(
				'label' => __( 'Checkout expired unused', 'stg-moyasar-payments' ),
				'when'  => $at( $row['updated_at'] ),
				'state' => 'todo',
			);
		}

		return $steps;
	}

	/**
	 * Ask Moyasar again about one payment.
	 *
	 * @return void
	 */
	public function recheck(): void {
		$this->require_permission();

		$id = isset( $_POST['payment'] ) ? (int) $_POST['payment'] : 0;

		check_admin_referer( 'stg_moyasar_recheck_' . $id );

		$row = Repository::find( $id );

		if ( $row && ! empty( $row['provider_payment_id'] ) ) {
			// The same function the webhook calls. A reconciliation tool using
			// different logic from production would eventually disagree with
			// it, at the worst possible moment.
			Settlement::settle(
				(string) $row['provider_payment_id'],
				(string) $row['public_ref'],
				'admin'
			);
		}

		$this->redirect_back(
			'rechecked',
			'',
			$row ? array( 'payment' => (string) $row['public_ref'] ) : array()
		);
	}

	/**
	 * Check a payment id copied from the Moyasar dashboard against a checkout.
	 *
	 * For the customer who says they paid and whose checkout never heard about
	 * it. Nothing is written from the form: the id goes to the same settlement
	 * the webhook uses, which asks Moyasar, checks the amount and the
	 * environment, and refuses a payment that already belongs elsewhere.
	 *
	 * @return void
	 */
	public function attach(): void {
		$this->require_permission();

		$id = isset( $_POST['payment'] ) ? (int) $_POST['payment'] : 0;

		check_admin_referer( 'stg_moyasar_attach_' . $id );

		$row = Repository::find( $id );

		if ( ! $row || ! empty( $row['provider_payment_id'] ) ) {
			$this->redirect_back( 'not_attached', '', $row ? array( 'payment' => (string) $row['public_ref'] ) : array() );

			return;
		}

		$back        = array( 'payment' => (string) $row['public_ref'] );
		$provider_id = isset( $_POST['provider_payment_id'] )
			? trim( sanitize_text_field( wp_unslash( (string) $_POST['provider_payment_id'] ) ) )
			: '';

		if ( ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $provider_id ) ) {
			$this->redirect_back( 'bad_id', '', $back );

			return;
		}

		Settlement::settle( $provider_id, (string) $row['public_ref'], 'admin' );

		$after    = Repository::find( $id );
		$attached = $after && (string) ( $after['provider_payment_id'] ?? '' ) === $provider_id;

		Log::info(
			$attached
				? 'A member of staff attached a provider payment to a checkout.'
				: 'A member of staff tried to attach a provider payment to a checkout; it was not attached.',
			array(
				'ref'   => (string) $row['public_ref'],
				'actor' => get_current_user_id(),
			)
		);

		$this->redirect_back( $attached ? 'attached' : 'not_attached', '', $back );
	}

	/**
	 * Record that a flagged payment has been dealt with.
	 *
	 * @return void
	 */
	public function review(): void {
		$this->require_permission();

		$id = isset( $_POST['payment'] ) ? (int) $_POST['payment'] : 0;

		check_admin_referer( 'stg_moyasar_review_' . $id );

		$row  = Repository::find( $id );
		$back = $row ? array( 'payment' => (string) $row['public_ref'] ) : array();
		$note = isset( $_POST['review_note'] )
			? trim( sanitize_textarea_field( wp_unslash( (string) $_POST['review_note'] ) ) )
			: '';

		if ( ! $row || '' === $note ) {
			$this->redirect_back( 'note_needed', '', $back );

			return;
		}

		if ( Repository::mark_reviewed( $id, get_current_user_id(), mb_substr( $note, 0, 255 ) ) ) {
			Log::info(
				'A flagged payment was marked as dealt with.',
				array(
					'ref'   => (string) $row['public_ref'],
					'actor' => get_current_user_id(),
				)
			);
		}

		$this->redirect_back( 'reviewed', '', $back );
	}
}
