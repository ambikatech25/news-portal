<?php
/**
 * The Report form, in a pop-up (<dialog>). Printed once per page; every Report button
 * on the page opens it and fills in what is being reported (post now, comments later).
 *
 * Arguments: reasons (slug => label), from portal_report_reasons() in portal-core.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

$portal_reasons = $args['reasons'] ?? array();
if ( ! $portal_reasons ) {
	return;
}
?>
<dialog class="portal-report" id="portal-report-dialog" aria-labelledby="portal-report-title">
	<form class="portal-report__form" method="dialog" novalidate>
		<h2 class="portal-report__title" id="portal-report-title"><?php esc_html_e( 'Report this', 'portal' ); ?></h2>
		<p class="portal-report__intro"><?php esc_html_e( 'Tell us what is wrong. Our team reviews every report. Your name is not shown to the writer.', 'portal' ); ?></p>

		<input type="hidden" name="object_type" value="post">
		<input type="hidden" name="object_id" value="">

		<fieldset class="portal-report__reasons">
			<legend><?php esc_html_e( 'Reason', 'portal' ); ?></legend>
			<?php foreach ( $portal_reasons as $portal_slug => $portal_label ) : ?>
				<label>
					<input type="radio" name="reason" value="<?php echo esc_attr( $portal_slug ); ?>">
					<?php echo esc_html( $portal_label ); ?>
				</label>
			<?php endforeach; ?>
		</fieldset>

		<label class="portal-report__details">
			<?php esc_html_e( 'More details (optional)', 'portal' ); ?>
			<textarea name="details" rows="3" maxlength="1000"></textarea>
		</label>

		<p class="portal-report__status" role="status" aria-live="polite"></p>

		<div class="portal-report__buttons">
			<button type="button" class="portal-report__cancel" value="cancel"><?php esc_html_e( 'Cancel', 'portal' ); ?></button>
			<button type="submit" class="portal-report__submit"><?php esc_html_e( 'Send report', 'portal' ); ?></button>
		</div>
	</form>
</dialog>
