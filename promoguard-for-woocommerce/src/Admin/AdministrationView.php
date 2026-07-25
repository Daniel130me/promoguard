<?php
/**
 * Full administration workspace markup.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Admin;

use PromoGuard\Support\Capabilities;

/** Renders capability-aware navigation and report views around the campaign editor. */
final class AdministrationView {
	/** Render the administration navigation and report panels. */
	public static function render(): void {
		$can_view_reports    = current_user_can( Capabilities::VIEW_REPORTS );
		$can_manage_settings = current_user_can( Capabilities::MANAGE_SETTINGS );
		$can_run_tools       = current_user_can( Capabilities::RUN_TOOLS );
		?>
		<nav class="promoguard-workspace__nav" aria-label="<?php esc_attr_e( 'PromoGuard sections', 'promoguard-for-woocommerce' ); ?>">
			<?php if ( $can_view_reports ) : ?>
				<button class="promoguard-workspace__nav-item" type="button" data-promoguard-view="dashboard"><?php esc_html_e( 'Dashboard', 'promoguard-for-woocommerce' ); ?></button>
				<button class="promoguard-workspace__nav-item" type="button" data-promoguard-view="analytics"><?php esc_html_e( 'Analytics', 'promoguard-for-woocommerce' ); ?></button>
			<?php endif; ?>
			<button class="promoguard-workspace__nav-item" type="button" data-promoguard-view="campaigns"><?php esc_html_e( 'Campaigns', 'promoguard-for-woocommerce' ); ?></button>
			<?php if ( $can_view_reports ) : ?>
				<button class="promoguard-workspace__nav-item" type="button" data-promoguard-view="usages"><?php esc_html_e( 'Usage history', 'promoguard-for-woocommerce' ); ?></button>
				<button class="promoguard-workspace__nav-item" type="button" data-promoguard-view="decisions"><?php esc_html_e( 'Decisions', 'promoguard-for-woocommerce' ); ?></button>
			<?php endif; ?>
			<?php if ( $can_manage_settings ) : ?>
				<button class="promoguard-workspace__nav-item" type="button" data-promoguard-view="settings"><?php esc_html_e( 'Settings', 'promoguard-for-woocommerce' ); ?></button>
			<?php endif; ?>
			<?php if ( $can_run_tools ) : ?>
				<button class="promoguard-workspace__nav-item" type="button" data-promoguard-view="tools"><?php esc_html_e( 'Tools', 'promoguard-for-woocommerce' ); ?></button>
			<?php endif; ?>
		</nav>
		<?php

		if ( $can_view_reports ) {
			self::render_dashboard();
			AnalyticsView::render();
			self::render_usage_history();
			self::render_decisions();
		}

		AdministrationToolsView::render( $can_manage_settings, $can_run_tools );
	}

	/** Render the lightweight operational dashboard. */
	private static function render_dashboard(): void {
		?>
		<section class="promoguard-workspace__view" id="promoguard-view-dashboard" data-promoguard-panel="dashboard" aria-labelledby="promoguard-dashboard-title" hidden>
			<div class="promoguard-admin__toolbar">
				<div>
					<h2 id="promoguard-dashboard-title"><?php esc_html_e( 'Operations dashboard', 'promoguard-for-woocommerce' ); ?></h2>
					<p><?php esc_html_e( 'A lightweight snapshot of campaign and usage lifecycle records.', 'promoguard-for-woocommerce' ); ?></p>
				</div>
				<button class="button" id="promoguard-dashboard-refresh" type="button"><?php esc_html_e( 'Refresh dashboard', 'promoguard-for-woocommerce' ); ?></button>
			</div>
			<p class="promoguard-workspace__status" id="promoguard-dashboard-status" role="status" aria-live="polite"><?php esc_html_e( 'Dashboard data has not been loaded.', 'promoguard-for-woocommerce' ); ?></p>
			<div class="promoguard-workspace__metrics" id="promoguard-dashboard-metrics" aria-busy="false">
				<?php
				foreach (
					array(
						'campaign-active'   => __( 'Active campaigns', 'promoguard-for-woocommerce' ),
						'campaign-draft'    => __( 'Draft campaigns', 'promoguard-for-woocommerce' ),
						'campaign-paused'   => __( 'Paused campaigns', 'promoguard-for-woocommerce' ),
						'campaign-archived' => __( 'Archived campaigns', 'promoguard-for-woocommerce' ),
						'usage-pending'     => __( 'Pending usages', 'promoguard-for-woocommerce' ),
						'usage-consumed'    => __( 'Consumed usages', 'promoguard-for-woocommerce' ),
						'usage-released'    => __( 'Released usages', 'promoguard-for-woocommerce' ),
						'usage-restored'    => __( 'Restored usages', 'promoguard-for-woocommerce' ),
					) as $key => $label
				) :
					?>
					<article class="promoguard-workspace__metric">
						<span><?php echo esc_html( $label ); ?></span>
						<strong class="promoguard-admin__number" data-promoguard-metric="<?php echo esc_attr( $key ); ?>">—</strong>
					</article>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	/** Render bounded usage history controls and table. */
	private static function render_usage_history(): void {
		?>
		<section class="promoguard-workspace__view" id="promoguard-view-usages" data-promoguard-panel="usages" aria-labelledby="promoguard-usages-title" hidden>
			<div class="promoguard-admin__toolbar">
				<div>
					<h2 id="promoguard-usages-title"><?php esc_html_e( 'Usage history', 'promoguard-for-woocommerce' ); ?></h2>
					<p><?php esc_html_e( 'Review reservation, consumption, release, and restoration records.', 'promoguard-for-woocommerce' ); ?></p>
				</div>
			</div>
			<form class="promoguard-workspace__filters" id="promoguard-usage-filters">
				<div class="promoguard-admin__field">
					<label for="promoguard-usage-status"><?php esc_html_e( 'Usage status', 'promoguard-for-woocommerce' ); ?></label>
					<select id="promoguard-usage-status" name="status">
						<option value=""><?php esc_html_e( 'All statuses', 'promoguard-for-woocommerce' ); ?></option>
						<option value="pending"><?php esc_html_e( 'Pending', 'promoguard-for-woocommerce' ); ?></option>
						<option value="consumed"><?php esc_html_e( 'Consumed', 'promoguard-for-woocommerce' ); ?></option>
						<option value="released"><?php esc_html_e( 'Released', 'promoguard-for-woocommerce' ); ?></option>
						<option value="restored"><?php esc_html_e( 'Restored', 'promoguard-for-woocommerce' ); ?></option>
					</select>
				</div>
				<?php self::render_id_filter( 'usage-campaign', 'campaign_id', __( 'Campaign ID', 'promoguard-for-woocommerce' ) ); ?>
				<?php self::render_id_filter( 'usage-order', 'order_id', __( 'Order ID', 'promoguard-for-woocommerce' ) ); ?>
				<div class="promoguard-workspace__filter-actions">
					<button class="button button-primary" type="submit"><?php esc_html_e( 'Apply filters', 'promoguard-for-woocommerce' ); ?></button>
					<button class="button" type="reset"><?php esc_html_e( 'Clear', 'promoguard-for-woocommerce' ); ?></button>
				</div>
			</form>
			<p class="promoguard-workspace__status" id="promoguard-usage-status-text" role="status" aria-live="polite"><?php esc_html_e( 'Usage history has not been loaded.', 'promoguard-for-woocommerce' ); ?></p>
			<div class="promoguard-admin__table-wrap">
				<table class="widefat striped" id="promoguard-usage-table" aria-busy="false">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Campaign', 'promoguard-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Order', 'promoguard-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Customer', 'promoguard-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Coupon', 'promoguard-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'State', 'promoguard-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Discount', 'promoguard-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Recorded', 'promoguard-for-woocommerce' ); ?></th>
					</tr></thead>
					<tbody id="promoguard-usage-rows"></tbody>
				</table>
			</div>
			<?php self::render_pagination( 'usage', __( 'Usage history pages', 'promoguard-for-woocommerce' ) ); ?>
		</section>
		<?php
	}

	/** Render bounded denial decision history controls and table. */
	private static function render_decisions(): void {
		?>
		<section class="promoguard-workspace__view" id="promoguard-view-decisions" data-promoguard-panel="decisions" aria-labelledby="promoguard-decisions-title" hidden>
			<div class="promoguard-admin__toolbar">
				<div>
					<h2 id="promoguard-decisions-title"><?php esc_html_e( 'Eligibility decisions', 'promoguard-for-woocommerce' ); ?></h2>
					<p><?php esc_html_e( 'Inspect persisted denials without exposing private identifier hashes or diagnostic payloads.', 'promoguard-for-woocommerce' ); ?></p>
				</div>
			</div>
			<form class="promoguard-workspace__filters" id="promoguard-decision-filters">
				<div class="promoguard-admin__field">
					<label for="promoguard-decision-reason"><?php esc_html_e( 'Reason code', 'promoguard-for-woocommerce' ); ?></label>
					<input id="promoguard-decision-reason" name="reason" type="text" maxlength="64">
				</div>
				<?php self::render_id_filter( 'decision-campaign', 'campaign_id', __( 'Campaign ID', 'promoguard-for-woocommerce' ) ); ?>
				<?php self::render_id_filter( 'decision-order', 'order_id', __( 'Order ID', 'promoguard-for-woocommerce' ) ); ?>
				<div class="promoguard-workspace__filter-actions">
					<button class="button button-primary" type="submit"><?php esc_html_e( 'Apply filters', 'promoguard-for-woocommerce' ); ?></button>
					<button class="button" type="reset"><?php esc_html_e( 'Clear', 'promoguard-for-woocommerce' ); ?></button>
				</div>
			</form>
			<p class="promoguard-workspace__status" id="promoguard-decision-status-text" role="status" aria-live="polite"><?php esc_html_e( 'Decision history has not been loaded.', 'promoguard-for-woocommerce' ); ?></p>
			<div class="promoguard-admin__table-wrap">
				<table class="widefat striped" id="promoguard-decision-table" aria-busy="false">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Campaign', 'promoguard-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Order', 'promoguard-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Customer', 'promoguard-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Coupon', 'promoguard-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Context', 'promoguard-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Reason', 'promoguard-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Explanation', 'promoguard-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Recorded', 'promoguard-for-woocommerce' ); ?></th>
					</tr></thead>
					<tbody id="promoguard-decision-rows"></tbody>
				</table>
			</div>
			<?php self::render_pagination( 'decision', __( 'Decision history pages', 'promoguard-for-woocommerce' ) ); ?>
		</section>
		<?php
	}

	/**
	 * Render one positive numeric exact filter.
	 *
	 * @param string $id    Unique field identifier suffix.
	 * @param string $name  REST query parameter name.
	 * @param string $label Visible field label.
	 */
	private static function render_id_filter( string $id, string $name, string $label ): void {
		?>
		<div class="promoguard-admin__field">
			<label for="promoguard-<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<input id="promoguard-<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" type="number" min="1" step="1" inputmode="numeric">
		</div>
		<?php
	}

	/**
	 * Render shared bounded-list pagination controls.
	 *
	 * @param string $prefix Unique report identifier prefix.
	 * @param string $label  Accessible pagination label.
	 */
	private static function render_pagination( string $prefix, string $label ): void {
		?>
		<nav class="promoguard-admin__pagination" aria-label="<?php echo esc_attr( $label ); ?>">
			<button class="button" id="promoguard-<?php echo esc_attr( $prefix ); ?>-previous" type="button" disabled><?php esc_html_e( 'Previous', 'promoguard-for-woocommerce' ); ?></button>
			<span id="promoguard-<?php echo esc_attr( $prefix ); ?>-page-status"></span>
			<button class="button" id="promoguard-<?php echo esc_attr( $prefix ); ?>-next" type="button" disabled><?php esc_html_e( 'Next', 'promoguard-for-woocommerce' ); ?></button>
		</nav>
		<?php
	}
}
