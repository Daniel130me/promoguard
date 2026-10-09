<?php
/**
 * Administration settings and historical-indexing tool markup.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Admin;

/** Renders capability-separated settings and operational tool views. */
final class AdministrationToolsView {
	/**
	 * Render the views authorized for the current user.
	 *
	 * @param bool $can_manage_settings Whether settings may be read and changed.
	 * @param bool $can_run_tools       Whether historical indexing may be operated.
	 */
	public static function render( bool $can_manage_settings, bool $can_run_tools ): void {
		if ( $can_manage_settings ) {
			self::render_settings();
		}

		if ( $can_run_tools ) {
			self::render_indexing();
		}
	}

	/** Render implemented settings and read-only storage health. */
	private static function render_settings(): void {
		?>
		<section class="promoguard-workspace__view" id="promoguard-view-settings" data-promoguard-panel="settings" aria-labelledby="promoguard-settings-title" hidden>
			<div class="promoguard-admin__toolbar">
				<div>
					<h2 id="promoguard-settings-title"><?php esc_html_e( 'Settings', 'promoguard-for-woocommerce' ); ?></h2>
					<p><?php esc_html_e( 'Configure standalone signup credit, control data-removal consent, and review storage compatibility.', 'promoguard-for-woocommerce' ); ?></p>
				</div>
			</div>
			<div class="promoguard-tools__health" aria-labelledby="promoguard-storage-title">
				<div>
					<h3 id="promoguard-storage-title"><?php esc_html_e( 'Storage engine', 'promoguard-for-woocommerce' ); ?></h3>
					<p id="promoguard-storage-checked"><?php esc_html_e( 'Compatibility has not been loaded.', 'promoguard-for-woocommerce' ); ?></p>
				</div>
				<span class="promoguard-status" id="promoguard-storage-status"><?php esc_html_e( 'Unknown', 'promoguard-for-woocommerce' ); ?></span>
			</div>
			<form id="promoguard-settings-form">
				<fieldset class="promoguard-tools__fieldset">
					<legend><?php esc_html_e( 'Store credit redemption', 'promoguard-for-woocommerce' ); ?></legend>
					<label class="promoguard-tools__checkbox" for="promoguard-credit-redemption-enabled">
						<input id="promoguard-credit-redemption-enabled" name="redemption_enabled" type="checkbox">
						<span>
							<strong><?php esc_html_e( 'Allow signed-in customers to apply store credit', 'promoguard-for-woocommerce' ); ?></strong>
							<small><?php esc_html_e( 'Credit covers merchandise and merchandise tax only. Shipping, guests, and currency mismatches remain ineligible.', 'promoguard-for-woocommerce' ); ?></small>
						</span>
					</label>
					<p class="description"><?php esc_html_e( 'Store-credit redemption is independent of coupon and signup campaigns.', 'promoguard-for-woocommerce' ); ?></p>
				</fieldset>
				<fieldset class="promoguard-tools__fieldset">
					<legend><?php esc_html_e( 'Signup bonus', 'promoguard-for-woocommerce' ); ?></legend>
					<p class="description"><?php esc_html_e( 'These standalone store-credit rules do not use or modify coupon campaigns. Amounts use the current WooCommerce store currency.', 'promoguard-for-woocommerce' ); ?></p>
					<div class="promoguard-admin__field">
						<label for="promoguard-customer-bonus-event"><?php esc_html_e( 'Customer award timing', 'promoguard-for-woocommerce' ); ?></label>
						<select id="promoguard-customer-bonus-event" name="customer_event">
							<option value="registration"><?php esc_html_e( 'On registration', 'promoguard-for-woocommerce' ); ?></option>
							<option value="disabled"><?php esc_html_e( 'Disabled', 'promoguard-for-woocommerce' ); ?></option>
						</select>
					</div>
					<div class="promoguard-admin__field">
						<label for="promoguard-customer-bonus-amount"><?php esc_html_e( 'Customer credit amount', 'promoguard-for-woocommerce' ); ?></label>
						<input id="promoguard-customer-bonus-amount" name="customer_amount" type="number" min="0" step="any" required>
					</div>
					<div class="promoguard-admin__field">
						<label for="promoguard-vendor-bonus-event"><?php esc_html_e( 'Dokan vendor award timing', 'promoguard-for-woocommerce' ); ?></label>
						<select id="promoguard-vendor-bonus-event" name="vendor_event">
							<option value="approval"><?php esc_html_e( 'After approval', 'promoguard-for-woocommerce' ); ?></option>
							<option value="registration"><?php esc_html_e( 'On registration', 'promoguard-for-woocommerce' ); ?></option>
							<option value="disabled"><?php esc_html_e( 'Disabled', 'promoguard-for-woocommerce' ); ?></option>
						</select>
					</div>
					<div class="promoguard-admin__field">
						<label for="promoguard-vendor-bonus-amount"><?php esc_html_e( 'Vendor credit amount', 'promoguard-for-woocommerce' ); ?></label>
						<input id="promoguard-vendor-bonus-amount" name="vendor_amount" type="number" min="0" step="any" required>
					</div>
				</fieldset>
				<fieldset class="promoguard-tools__fieldset">
					<legend><?php esc_html_e( 'Uninstall cleanup', 'promoguard-for-woocommerce' ); ?></legend>
					<label class="promoguard-tools__checkbox" for="promoguard-delete-data">
						<input id="promoguard-delete-data" name="delete_data_on_uninstall" type="checkbox">
						<span>
							<strong><?php esc_html_e( 'Delete PromoGuard data when the plugin is uninstalled', 'promoguard-for-woocommerce' ); ?></strong>
							<small><?php esc_html_e( 'This is explicit consent for uninstall only. Deactivation never removes campaign, usage, customer, or decision data.', 'promoguard-for-woocommerce' ); ?></small>
						</span>
					</label>
				</fieldset>
				<div class="promoguard-tools__form-footer">
					<button class="button button-primary" id="promoguard-settings-save" type="submit"><?php esc_html_e( 'Save settings', 'promoguard-for-woocommerce' ); ?></button>
					<p class="promoguard-workspace__status" id="promoguard-settings-status" role="status" aria-live="polite"><?php esc_html_e( 'Settings have not been loaded.', 'promoguard-for-woocommerce' ); ?></p>
				</div>
			</form>
		</section>
		<?php
	}

	/** Render bounded historical-indexing controls and progress. */
	private static function render_indexing(): void {
		?>
		<section class="promoguard-workspace__view" id="promoguard-view-tools" data-promoguard-panel="tools" aria-labelledby="promoguard-tools-title" hidden>
			<div class="promoguard-admin__toolbar">
				<div>
					<h2 id="promoguard-tools-title"><?php esc_html_e( 'Historical indexing', 'promoguard-for-woocommerce' ); ?></h2>
					<p><?php esc_html_e( 'Build PromoGuard usage history from existing WooCommerce orders in bounded background batches.', 'promoguard-for-woocommerce' ); ?></p>
				</div>
				<button class="button" id="promoguard-indexing-refresh" type="button"><?php esc_html_e( 'Refresh status', 'promoguard-for-woocommerce' ); ?></button>
			</div>
			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'A blank order list scans all eligible historical orders. Use targeted order IDs for a smaller recovery or verification job.', 'promoguard-for-woocommerce' ); ?></p>
			</div>
			<form class="promoguard-tools__start-form" id="promoguard-indexing-start-form">
				<div class="promoguard-admin__field">
					<label for="promoguard-indexing-order-ids"><?php esc_html_e( 'Order IDs', 'promoguard-for-woocommerce' ); ?></label>
					<input id="promoguard-indexing-order-ids" name="order_ids" type="text" inputmode="numeric" aria-describedby="promoguard-indexing-order-help">
					<p class="description" id="promoguard-indexing-order-help"><?php esc_html_e( 'Optional. Enter up to 100 positive IDs separated by commas.', 'promoguard-for-woocommerce' ); ?></p>
				</div>
				<div class="promoguard-admin__field">
					<label for="promoguard-indexing-batch-size"><?php esc_html_e( 'Batch size', 'promoguard-for-woocommerce' ); ?></label>
					<input id="promoguard-indexing-batch-size" name="batch_size" type="number" min="1" max="100" step="1" value="50" inputmode="numeric">
				</div>
				<div class="promoguard-tools__start-action">
					<button class="button button-primary" id="promoguard-indexing-start" type="submit"><?php esc_html_e( 'Start indexing', 'promoguard-for-woocommerce' ); ?></button>
				</div>
			</form>
			<p class="promoguard-workspace__status" id="promoguard-indexing-status-text" role="status" aria-live="polite"><?php esc_html_e( 'Indexing status has not been loaded.', 'promoguard-for-woocommerce' ); ?></p>
			<div id="promoguard-indexing-job" hidden>
				<div class="promoguard-tools__job-heading">
					<div>
						<h3><?php esc_html_e( 'Current job', 'promoguard-for-woocommerce' ); ?></h3>
						<p id="promoguard-indexing-meta"></p>
					</div>
					<span class="promoguard-status" id="promoguard-indexing-status"></span>
				</div>
				<div class="promoguard-workspace__metrics promoguard-tools__metrics">
					<?php
					foreach (
						array(
							'processed' => __( 'Processed', 'promoguard-for-woocommerce' ),
							'imported'  => __( 'Imported', 'promoguard-for-woocommerce' ),
							'skipped'   => __( 'Skipped', 'promoguard-for-woocommerce' ),
							'failed'    => __( 'Failed', 'promoguard-for-woocommerce' ),
						) as $key => $label
					) :
						?>
						<article class="promoguard-workspace__metric">
							<span><?php echo esc_html( $label ); ?></span>
							<strong class="promoguard-admin__number" data-promoguard-job-metric="<?php echo esc_attr( $key ); ?>">0</strong>
						</article>
					<?php endforeach; ?>
				</div>
				<div class="promoguard-tools__actions" aria-label="<?php esc_attr_e( 'Indexing job actions', 'promoguard-for-woocommerce' ); ?>">
					<button class="button" data-promoguard-indexing-action="pause" type="button" hidden><?php esc_html_e( 'Pause', 'promoguard-for-woocommerce' ); ?></button>
					<button class="button button-primary" data-promoguard-indexing-action="resume" type="button" hidden><?php esc_html_e( 'Resume', 'promoguard-for-woocommerce' ); ?></button>
					<button class="button" data-promoguard-indexing-action="retry" type="button" hidden><?php esc_html_e( 'Retry failures', 'promoguard-for-woocommerce' ); ?></button>
					<button class="button" data-promoguard-indexing-action="restart" type="button" hidden><?php esc_html_e( 'Restart job', 'promoguard-for-woocommerce' ); ?></button>
				</div>
				<div class="promoguard-tools__errors" id="promoguard-indexing-errors" hidden>
					<h3><?php esc_html_e( 'Recent errors', 'promoguard-for-woocommerce' ); ?></h3>
					<div class="promoguard-admin__table-wrap">
						<table class="widefat striped">
							<thead><tr>
								<th scope="col"><?php esc_html_e( 'Order', 'promoguard-for-woocommerce' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Message', 'promoguard-for-woocommerce' ); ?></th>
							</tr></thead>
							<tbody id="promoguard-indexing-error-rows"></tbody>
						</table>
					</div>
				</div>
			</div>
		</section>
		<?php
	}
}
