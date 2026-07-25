<?php
/**
 * Analytics administration markup.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Admin;

/** Renders the accessible analytics summary and breakdown controls. */
final class AnalyticsView {
	/** Render the lazy-loaded analytics panel. */
	public static function render(): void {
		?>
		<section class="promoguard-workspace__view" id="promoguard-view-analytics" data-promoguard-panel="analytics" aria-labelledby="promoguard-analytics-title" hidden>
			<div class="promoguard-admin__toolbar">
				<div>
					<h2 id="promoguard-analytics-title"><?php esc_html_e( 'Campaign analytics', 'promoguard-for-woocommerce' ); ?></h2>
					<p><?php esc_html_e( 'Compare redemption, customer, order, discount, refund, and denial facts without implying promotion causation.', 'promoguard-for-woocommerce' ); ?></p>
				</div>
				<button class="button" id="promoguard-analytics-refresh" type="button"><?php esc_html_e( 'Refresh analytics', 'promoguard-for-woocommerce' ); ?></button>
			</div>
			<form class="promoguard-workspace__filters" id="promoguard-analytics-filters">
				<div class="promoguard-admin__field">
					<label for="promoguard-analytics-start"><?php esc_html_e( 'Start (UTC)', 'promoguard-for-woocommerce' ); ?></label>
					<input id="promoguard-analytics-start" name="starts_at_gmt" type="datetime-local">
				</div>
				<div class="promoguard-admin__field">
					<label for="promoguard-analytics-end"><?php esc_html_e( 'End (UTC)', 'promoguard-for-woocommerce' ); ?></label>
					<input id="promoguard-analytics-end" name="ends_at_gmt" type="datetime-local">
				</div>
				<div class="promoguard-admin__field">
					<label for="promoguard-analytics-campaign"><?php esc_html_e( 'Campaign ID', 'promoguard-for-woocommerce' ); ?></label>
					<input id="promoguard-analytics-campaign" name="campaign_id" type="number" min="1" step="1" inputmode="numeric">
				</div>
				<div class="promoguard-workspace__filter-actions">
					<button class="button button-primary" type="submit"><?php esc_html_e( 'Apply filters', 'promoguard-for-woocommerce' ); ?></button>
					<button class="button" type="reset"><?php esc_html_e( 'Clear', 'promoguard-for-woocommerce' ); ?></button>
				</div>
			</form>
			<p class="description"><?php esc_html_e( 'Leave dates empty for the latest 30 days. Reports are limited to 366 days and timestamps use UTC.', 'promoguard-for-woocommerce' ); ?></p>
			<p class="promoguard-workspace__status" id="promoguard-analytics-status" role="status" aria-live="polite"><?php esc_html_e( 'Analytics have not been loaded.', 'promoguard-for-woocommerce' ); ?></p>
			<div class="promoguard-workspace__metrics" id="promoguard-analytics-metrics" aria-busy="false">
				<?php
				foreach (
					array(
						'redemptions'      => __( 'Redemptions', 'promoguard-for-woocommerce' ),
						'unique_customers' => __( 'Unique customers', 'promoguard-for-woocommerce' ),
						'campaign_orders'  => __( 'Campaign orders', 'promoguard-for-woocommerce' ),
						'global_orders'    => __( 'Unique orders', 'promoguard-for-woocommerce' ),
						'refunds'          => __( 'Restored usages', 'promoguard-for-woocommerce' ),
						'denials'          => __( 'Denied attempts', 'promoguard-for-woocommerce' ),
					) as $key => $label
				) :
					?>
					<article class="promoguard-workspace__metric">
						<span><?php echo esc_html( $label ); ?></span>
						<strong class="promoguard-admin__number" data-promoguard-analytics-metric="<?php echo esc_attr( $key ); ?>">â€”</strong>
					</article>
				<?php endforeach; ?>
			</div>
			<div class="promoguard-analytics__breakdowns">
				<section aria-labelledby="promoguard-currency-title">
					<h3 id="promoguard-currency-title"><?php esc_html_e( 'Discounts by currency', 'promoguard-for-woocommerce' ); ?></h3>
					<p><?php esc_html_e( 'Currencies remain separate; PromoGuard does not perform conversion.', 'promoguard-for-woocommerce' ); ?></p>
					<div class="promoguard-admin__table-wrap">
						<table class="widefat striped" id="promoguard-analytics-currency-table">
							<thead><tr>
								<th scope="col"><?php esc_html_e( 'Currency', 'promoguard-for-woocommerce' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Consumed discount', 'promoguard-for-woocommerce' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Restored discount', 'promoguard-for-woocommerce' ); ?></th>
							</tr></thead>
							<tbody id="promoguard-analytics-currency-rows"></tbody>
						</table>
					</div>
				</section>
				<section aria-labelledby="promoguard-denial-title">
					<h3 id="promoguard-denial-title"><?php esc_html_e( 'Denials by reason', 'promoguard-for-woocommerce' ); ?></h3>
					<p><?php esc_html_e( 'The most frequent persisted denial reasons in the selected period.', 'promoguard-for-woocommerce' ); ?></p>
					<div class="promoguard-admin__table-wrap">
						<table class="widefat striped" id="promoguard-analytics-denial-table">
							<thead><tr>
								<th scope="col"><?php esc_html_e( 'Reason', 'promoguard-for-woocommerce' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Denied attempts', 'promoguard-for-woocommerce' ); ?></th>
							</tr></thead>
							<tbody id="promoguard-analytics-denial-rows"></tbody>
						</table>
					</div>
				</section>
			</div>
		</section>
		<?php
	}
}
