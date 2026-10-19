<?php
/**
 * Page heading, actions and tab bar.
 *
 * ## Why `wp-heading-inline` and `wp-header-end`
 *
 * WordPress relocates admin notices to sit directly after a screen's first
 * heading — `common.js` looks for `.wp-header-end`, and failing that, inserts
 * after the first `h1` it finds inside `.wrap`. The first version of this
 * partial wrapped the `h1` and the action button together in a flex row, so
 * core injected every notice *into the middle of that row*: the heading stayed
 * put, the notices appeared underneath it, and the subtitle and button were
 * pushed below them and left stranded on their own line.
 *
 * The fix is to use core's own markers rather than to fight them.
 * `h1.wp-heading-inline` followed by `.page-title-action` is the same pattern
 * every core list screen uses for "Posts + Add New", and the explicit
 * `<hr class="wp-header-end">` tells WordPress exactly where notices belong —
 * after the header block, before the tabs.
 *
 * The tabs use core's `.nav-tab-wrapper`, so they get the same keyboard
 * behaviour, focus ring and responsive collapse as every other WordPress
 * screen, and inherit future changes for nothing.
 *
 * @package STG\Moyasar
 *
 * @var string                            $title    Page title.
 * @var string                            $subtitle One-line description.
 * @var string                            $actions  Pre-escaped action markup.
 * @var string                            $current  Current tab key.
 * @var array<string, array{label:string, url:string}> $tabs Tab key => label and URL.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>

<?php if ( '' !== $actions ) : ?>
	<?php echo wp_kses_post( $actions ); ?>
<?php endif; ?>

<?php if ( '' !== $subtitle ) : ?>
	<p class="stg-pay__subtitle"><?php echo esc_html( $subtitle ); ?></p>
<?php endif; ?>

<?php
/*
 * Everything above is the header; notices go below this line. Without it,
 * WordPress falls back to "after the first h1", which lands inside whatever
 * markup happens to surround the heading.
 */
?>
<hr class="wp-header-end">

<?php if ( $tabs ) : ?>
	<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Payment settings sections', 'stg-moyasar-payments' ); ?>">
		<?php foreach ( $tabs as $key => $tab ) : ?>
			<a href="<?php echo esc_url( $tab['url'] ); ?>"
				class="nav-tab<?php echo $key === $current ? ' nav-tab-active' : ''; ?>"
				<?php echo $key === $current ? 'aria-current="page"' : ''; ?>>
				<?php echo esc_html( $tab['label'] ); ?>
			</a>
		<?php endforeach; ?>
	</nav>
<?php endif; ?>
