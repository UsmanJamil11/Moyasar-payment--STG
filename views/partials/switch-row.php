<?php
/**
 * One labelled row with a toggle switch.
 *
 * The switch is a real `<input type="checkbox">` styled with
 * `appearance: none` — not a div with a click handler. It therefore keeps
 * everything the native control already has: keyboard focus and space to
 * toggle, the correct role and checked state for a screen reader, `:disabled`,
 * and participation in the form post. No JavaScript is involved.
 *
 * The state word beside it is redundant with the switch's position on purpose.
 * "Is this on?" answered only by the location of a dot is a question a
 * colour-blind or low-vision reader has to squint at, and it is also what a
 * screen reader reads out, since the input carries no visible label text of
 * its own.
 *
 * @package STG\Moyasar
 *
 * @var string $name    Input name.
 * @var string $value   Submitted value.
 * @var string $id      Element id.
 * @var string $title   Row label.
 * @var string $desc    Supporting text.
 * @var bool   $checked Whether it is on.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="stg-pay-row">
	<div class="stg-pay-row__text">
		<label class="stg-pay-row__title" for="<?php echo esc_attr( $id ); ?>">
			<?php echo esc_html( $title ); ?>
		</label>
		<?php if ( '' !== $desc ) : ?>
			<p class="stg-pay-row__desc" id="<?php echo esc_attr( $id ); ?>-desc">
				<?php echo esc_html( $desc ); ?>
			</p>
		<?php endif; ?>
	</div>

	<div class="stg-pay-row__control">
		<input type="checkbox"
			class="stg-pay-switch"
			id="<?php echo esc_attr( $id ); ?>"
			name="<?php echo esc_attr( $name ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			<?php if ( '' !== $desc ) : ?>
				aria-describedby="<?php echo esc_attr( $id ); ?>-desc"
			<?php endif; ?>
			<?php checked( $checked ); ?>>
		<?php
		/*
		 * Both words are printed and CSS shows one.
		 *
		 * Rendering only the current word server-side would leave the label
		 * saying "Off" the moment somebody flicked the switch, because nothing
		 * re-renders until the form is saved. A sibling selector on `:checked`
		 * keeps the two in step with no JavaScript. `aria-hidden` because the
		 * input already announces its own state.
		 */
		?>
		<span class="stg-pay-switch__state" aria-hidden="true">
			<span class="stg-pay-switch__on"><?php esc_html_e( 'On', 'stg-moyasar-payments' ); ?></span>
			<span class="stg-pay-switch__off"><?php esc_html_e( 'Off', 'stg-moyasar-payments' ); ?></span>
		</span>
	</div>
</div>
