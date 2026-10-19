<?php
/**
 * The status card grid.
 *
 * Each card carries its state in words. The 3px coloured edge is redundant
 * reinforcement so the grid can be scanned, never the only signal - a reader
 * who cannot distinguish the colours loses nothing.
 *
 * @package STG\Moyasar
 *
 * @var array<int, array{label:string, value:string, meta:string, state:string, badge?:string}> $cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="stg-pay-cards">
	<?php foreach ( $cards as $card ) : ?>
		<div class="stg-pay-card stg-pay-card--<?php echo esc_attr( $card['state'] ); ?>">
			<span class="stg-pay-card__label"><?php echo esc_html( $card['label'] ); ?></span>
			<span class="stg-pay-card__value">
				<?php
				if ( ! empty( $card['badge'] ) ) {
					// Built by View::mode_badge(), which escapes its own input.
					echo wp_kses_post( $card['badge'] );
				} else {
					echo esc_html( $card['value'] );
				}
				?>
			</span>
			<?php if ( '' !== $card['meta'] ) : ?>
				<span class="stg-pay-card__meta"><?php echo esc_html( $card['meta'] ); ?></span>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</div>
