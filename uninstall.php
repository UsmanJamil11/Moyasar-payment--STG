<?php
/**
 * Uninstall routine.
 *
 * Removes this plugin's options - **including the credentials**, so no API key
 * is left behind in a database that is about to be handed to somebody else or
 * restored somewhere new.
 *
 * It deliberately does NOT drop `wp_stg_payments`, and it does not touch a
 * single subscription. Payment records are what a chargeback, a tax question or
 * a billing dispute is settled with, and a plugin that destroys them because
 * somebody clicked Delete would be a plugin nobody dares remove. Dropping the
 * table is an explicit, separate decision - the same stance
 * `stg-core/uninstall.php` takes about marketplace records.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'stg_moyasar_settings' );
delete_option( 'stg_moyasar_credentials' );
delete_option( 'stg_moyasar_schema_version' );
delete_option( 'stg_moyasar_version' );
delete_option( 'stg_moyasar_last_api_call' );
delete_option( 'stg_moyasar_last_webhook' );

wp_clear_scheduled_hook( 'stg_moyasar_reconcile' );

/*
 * The capability is left on the administrator role.
 *
 * Removing it would be tidier and would also strip it from a site that still
 * has STG Core installed, where `Roles::admin_caps()` grants it too. Leaving a
 * capability that nothing checks is harmless; removing one that something else
 * still grants is a support ticket.
 */
