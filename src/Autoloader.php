<?php
/**
 * PSR-4 autoloader.
 *
 * Deliberately self-contained rather than depending on Composer's autoloader:
 * `vendor/` is git-ignored and Composer is a development-only dependency, so the
 * plugin must run from a clean checkout without `composer install`.
 *
 * A near-copy of STG Core's, and deliberately not a call into it. Sharing one
 * autoloader would mean this plugin could not load a class until Core had
 * loaded - including the class that reports Core is missing.
 *
 * @package STG\Moyasar
 */

declare( strict_types = 1 );

namespace STG\Moyasar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps a namespace prefix to a base directory, PSR-4 style.
 */
final class Autoloader {

	/**
	 * Registered prefix => base directory pairs.
	 *
	 * @var array<string, string>
	 */
	private static array $prefixes = array();

	/**
	 * Whether the SPL handler has been registered.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Register a namespace prefix.
	 *
	 * @param string $prefix   Namespace prefix, e.g. `STG\Moyasar`.
	 * @param string $base_dir Directory that maps to the prefix.
	 * @return void
	 */
	public static function register( string $prefix, string $base_dir ): void {
		self::$prefixes[ trim( $prefix, '\\' ) . '\\' ] = rtrim( $base_dir, '/\\' ) . DIRECTORY_SEPARATOR;

		if ( ! self::$registered ) {
			spl_autoload_register( array( self::class, 'load' ) );
			self::$registered = true;
		}
	}

	/**
	 * Resolve and require a class file.
	 *
	 * WordPress naming (`class-foo-bar.php`) is not used here. This plugin follows
	 * PSR-4 file naming so the namespace maps directly onto the directory tree,
	 * which the WordPress standard explicitly permits for object-oriented code.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	private static function load( string $class_name ): void {
		foreach ( self::$prefixes as $prefix => $base_dir ) {
			if ( ! str_starts_with( $class_name, $prefix ) ) {
				continue;
			}

			$relative = substr( $class_name, strlen( $prefix ) );
			$path     = $base_dir . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';

			if ( is_readable( $path ) ) {
				require_once $path;
			}

			return;
		}
	}
}
