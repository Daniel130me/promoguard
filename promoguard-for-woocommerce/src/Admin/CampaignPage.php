<?php
/**
 * Campaign administration page.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Admin;

use PromoGuard\Support\Capabilities;

/** Registers and renders the capability-protected campaign workspace. */
final class CampaignPage {
	private const MENU_SLUG    = 'promoguard-campaigns';
	private const ASSET_HANDLE = 'promoguard-admin';
	private const ROOT_ID      = 'promoguard-admin';

	/**
	 * WordPress hook suffix assigned to this page.
	 *
	 * @var string|null
	 */
	private ?string $hook_suffix = null;

	/** Register the page lifecycle hooks. */
	public static function register(): void {
		$page = new self();

		add_action( 'admin_menu', array( $page, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $page, 'enqueue_assets' ) );
	}

	/** Add the top-level PromoGuard campaign menu. */
	public function add_menu(): void {
		$this->hook_suffix = add_menu_page(
			__( 'PromoGuard Campaigns', 'promoguard-for-woocommerce' ),
			__( 'PromoGuard', 'promoguard-for-woocommerce' ),
			Capabilities::MANAGE_CAMPAIGNS,
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-shield-alt',
			56
		);
	}

	/**
	 * Load the application only on the PromoGuard page.
	 *
	 * @param string $hook_suffix Current administration page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( null === $this->hook_suffix || $this->hook_suffix !== $hook_suffix ) {
			return;
		}

		$asset    = $this->asset_metadata();
		$base_url = plugin_dir_url( PROMOGUARD_PLUGIN_FILE ) . 'assets/build/';

		wp_enqueue_style( self::ASSET_HANDLE, $base_url . 'index.css', array(), $asset['version'] );
		wp_enqueue_script(
			self::ASSET_HANDLE,
			$base_url . 'index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_add_inline_script(
			self::ASSET_HANDLE,
			'window.PromoGuardAdmin = ' . wp_json_encode(
				array(
					'rootId'  => self::ROOT_ID,
					'restUrl' => untrailingslashit( rest_url( 'promoguard/v1' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
				)
			) . ';',
			'before'
		);
	}

	/** Render the progressively enhanced campaign workspace. */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CAMPAIGNS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage PromoGuard campaigns.', 'promoguard-for-woocommerce' ) );
		}
		?>
		<div class="wrap promoguard-admin" id="<?php echo esc_attr( self::ROOT_ID ); ?>">
			<header class="promoguard-admin__header">
				<div>
					<h1><?php esc_html_e( 'Campaigns', 'promoguard-for-woocommerce' ); ?></h1>
					<p><?php esc_html_e( 'Organize native WooCommerce coupons without changing coupon ownership or order history.', 'promoguard-for-woocommerce' ); ?></p>
				</div>
				<button class="button button-primary" id="promoguard-create-toggle" type="button" aria-expanded="false" aria-controls="promoguard-create-panel"><?php esc_html_e( 'Add campaign', 'promoguard-for-woocommerce' ); ?></button>
			</header>

			<div class="notice inline promoguard-admin__notice" id="promoguard-notice" role="status" aria-live="polite" hidden></div>

			<section class="promoguard-admin__panel" id="promoguard-create-panel" aria-labelledby="promoguard-create-title" hidden>
				<h2 id="promoguard-create-title"><?php esc_html_e( 'Create a Draft campaign', 'promoguard-for-woocommerce' ); ?></h2>
				<p><?php esc_html_e( 'Start with the campaign identity. Rules and coupons can be added after creation.', 'promoguard-for-woocommerce' ); ?></p>
				<form id="promoguard-create-form">
					<div class="promoguard-admin__form-grid">
						<div class="promoguard-admin__field">
							<label for="promoguard-name"><?php esc_html_e( 'Name', 'promoguard-for-woocommerce' ); ?> <span aria-hidden="true">*</span></label>
							<input class="regular-text" id="promoguard-name" name="name" type="text" maxlength="190" required>
						</div>
						<div class="promoguard-admin__field">
							<label for="promoguard-slug"><?php esc_html_e( 'Slug', 'promoguard-for-woocommerce' ); ?> <span aria-hidden="true">*</span></label>
							<input class="regular-text" id="promoguard-slug" name="slug" type="text" maxlength="190" aria-describedby="promoguard-slug-help" required>
							<p class="description" id="promoguard-slug-help"><?php esc_html_e( 'Used as a stable internal identifier. Lowercase letters, numbers, and hyphens work best.', 'promoguard-for-woocommerce' ); ?></p>
						</div>
						<div class="promoguard-admin__field promoguard-admin__field--wide">
							<label for="promoguard-description"><?php esc_html_e( 'Description', 'promoguard-for-woocommerce' ); ?></label>
							<textarea class="large-text" id="promoguard-description" name="description" rows="3"></textarea>
						</div>
					</div>
					<div class="promoguard-admin__actions">
						<button class="button button-primary" type="submit"><?php esc_html_e( 'Create campaign', 'promoguard-for-woocommerce' ); ?></button>
						<button class="button" id="promoguard-create-cancel" type="button"><?php esc_html_e( 'Cancel', 'promoguard-for-woocommerce' ); ?></button>
					</div>
					<p class="promoguard-admin__form-error" id="promoguard-form-error" role="alert" hidden></p>
				</form>
			</section>

			<section class="promoguard-admin__panel" id="promoguard-edit-panel" aria-labelledby="promoguard-edit-title" hidden>
				<h2 id="promoguard-edit-title"><?php esc_html_e( 'Manage campaign', 'promoguard-for-woocommerce' ); ?></h2>
				<p id="promoguard-edit-summary"></p>
				<form id="promoguard-edit-form">
					<input id="promoguard-edit-id" name="campaign_id" type="hidden">
					<div class="promoguard-admin__form-grid">
						<div class="promoguard-admin__field">
							<label for="promoguard-edit-name"><?php esc_html_e( 'Name', 'promoguard-for-woocommerce' ); ?> <span aria-hidden="true">*</span></label>
							<input class="regular-text" id="promoguard-edit-name" name="name" type="text" maxlength="190" required>
						</div>
						<div class="promoguard-admin__field">
							<label for="promoguard-edit-slug"><?php esc_html_e( 'Slug', 'promoguard-for-woocommerce' ); ?> <span aria-hidden="true">*</span></label>
							<input class="regular-text" id="promoguard-edit-slug" name="slug" type="text" maxlength="190" required>
						</div>
						<div class="promoguard-admin__field promoguard-admin__field--wide">
							<label for="promoguard-edit-description"><?php esc_html_e( 'Description', 'promoguard-for-woocommerce' ); ?></label>
							<textarea class="large-text" id="promoguard-edit-description" name="description" rows="3"></textarea>
						</div>
						<div class="promoguard-admin__field">
							<label for="promoguard-edit-goal"><?php esc_html_e( 'Goal', 'promoguard-for-woocommerce' ); ?></label>
							<input class="regular-text" id="promoguard-edit-goal" name="goal" type="text" maxlength="190">
						</div>
						<div class="promoguard-admin__field">
							<label for="promoguard-edit-priority"><?php esc_html_e( 'Priority', 'promoguard-for-woocommerce' ); ?></label>
							<input id="promoguard-edit-priority" name="priority" type="number" step="1" required>
						</div>
						<div class="promoguard-admin__field">
							<label for="promoguard-edit-status"><?php esc_html_e( 'Lifecycle status', 'promoguard-for-woocommerce' ); ?></label>
							<select id="promoguard-edit-status" name="status">
								<option value="draft"><?php esc_html_e( 'Draft', 'promoguard-for-woocommerce' ); ?></option>
								<option value="active"><?php esc_html_e( 'Active', 'promoguard-for-woocommerce' ); ?></option>
								<option value="paused"><?php esc_html_e( 'Paused', 'promoguard-for-woocommerce' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Scheduling changes how an Active campaign is displayed without changing its stored lifecycle status.', 'promoguard-for-woocommerce' ); ?></p>
						</div>
						<div class="promoguard-admin__field promoguard-admin__field--wide promoguard-admin__schedule-fields">
							<div>
								<label for="promoguard-edit-starts"><?php esc_html_e( 'Starts at (UTC)', 'promoguard-for-woocommerce' ); ?></label>
								<input id="promoguard-edit-starts" name="starts_at_gmt" type="datetime-local">
							</div>
							<div>
								<label for="promoguard-edit-ends"><?php esc_html_e( 'Ends at (UTC)', 'promoguard-for-woocommerce' ); ?></label>
								<input id="promoguard-edit-ends" name="ends_at_gmt" type="datetime-local">
							</div>
						</div>
					</div>
					<p class="promoguard-admin__readonly" id="promoguard-edit-readonly" hidden><?php esc_html_e( 'Archived campaigns are read-only and remain available for historical reporting.', 'promoguard-for-woocommerce' ); ?></p>
					<div class="promoguard-admin__actions promoguard-admin__actions--spread">
						<div class="promoguard-admin__actions">
							<button class="button button-primary" id="promoguard-edit-save" type="submit"><?php esc_html_e( 'Save changes', 'promoguard-for-woocommerce' ); ?></button>
							<button class="button" id="promoguard-edit-cancel" type="button"><?php esc_html_e( 'Close', 'promoguard-for-woocommerce' ); ?></button>
						</div>
						<div class="promoguard-admin__actions">
							<button class="button" id="promoguard-edit-archive" type="button"><?php esc_html_e( 'Archive campaign', 'promoguard-for-woocommerce' ); ?></button>
							<button class="button promoguard-admin__danger" id="promoguard-edit-delete" type="button"><?php esc_html_e( 'Delete permanently', 'promoguard-for-woocommerce' ); ?></button>
						</div>
					</div>
					<p class="promoguard-admin__form-error" id="promoguard-edit-error" role="alert" hidden></p>
				</form>
			</section>
			<section class="promoguard-admin__panel" aria-labelledby="promoguard-list-title">
				<div class="promoguard-admin__toolbar">
					<div>
						<h2 id="promoguard-list-title"><?php esc_html_e( 'All campaigns', 'promoguard-for-woocommerce' ); ?></h2>
						<p id="promoguard-result-count"><?php esc_html_e( 'Loading campaigns…', 'promoguard-for-woocommerce' ); ?></p>
					</div>
					<div class="promoguard-admin__filter">
						<label for="promoguard-status-filter"><?php esc_html_e( 'Status', 'promoguard-for-woocommerce' ); ?></label>
						<select id="promoguard-status-filter">
							<option value=""><?php esc_html_e( 'All statuses', 'promoguard-for-woocommerce' ); ?></option>
							<option value="draft"><?php esc_html_e( 'Draft', 'promoguard-for-woocommerce' ); ?></option>
							<option value="active"><?php esc_html_e( 'Active', 'promoguard-for-woocommerce' ); ?></option>
							<option value="paused"><?php esc_html_e( 'Paused', 'promoguard-for-woocommerce' ); ?></option>
							<option value="archived"><?php esc_html_e( 'Archived', 'promoguard-for-woocommerce' ); ?></option>
						</select>
						<button class="button" id="promoguard-refresh" type="button"><?php esc_html_e( 'Refresh', 'promoguard-for-woocommerce' ); ?></button>
					</div>
				</div>

				<div class="promoguard-admin__table-wrap">
					<table class="widefat striped" id="promoguard-campaign-table">
						<thead><tr>
							<th scope="col"><?php esc_html_e( 'Campaign', 'promoguard-for-woocommerce' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'promoguard-for-woocommerce' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Schedule', 'promoguard-for-woocommerce' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Priority', 'promoguard-for-woocommerce' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'promoguard-for-woocommerce' ); ?></th>
						</tr></thead>
						<tbody id="promoguard-campaign-rows"></tbody>
					</table>
				</div>
				<div class="promoguard-admin__empty" id="promoguard-empty" hidden>
					<h3><?php esc_html_e( 'No campaigns found', 'promoguard-for-woocommerce' ); ?></h3>
					<p><?php esc_html_e( 'Create a Draft campaign or choose a different status filter.', 'promoguard-for-woocommerce' ); ?></p>
				</div>
				<nav class="promoguard-admin__pagination" aria-label="<?php esc_attr_e( 'Campaign pages', 'promoguard-for-woocommerce' ); ?>">
					<button class="button" id="promoguard-previous" type="button" disabled><?php esc_html_e( 'Previous', 'promoguard-for-woocommerce' ); ?></button>
					<span id="promoguard-page-status"></span>
					<button class="button" id="promoguard-next" type="button" disabled><?php esc_html_e( 'Next', 'promoguard-for-woocommerce' ); ?></button>
				</nav>
			</section>
		</div>
		<?php
	}

	/**
	 * Return validated generated-asset metadata.
	 *
	 * @return array{dependencies: string[], version: string}
	 */
	private function asset_metadata(): array {
		$path = plugin_dir_path( PROMOGUARD_PLUGIN_FILE ) . 'assets/build/index.asset.php';

		if ( ! is_readable( $path ) ) {
			return array(
				'dependencies' => array(),
				'version'      => PROMOGUARD_VERSION,
			);
		}

		$metadata     = require $path;
		$dependencies = is_array( $metadata ) && isset( $metadata['dependencies'] ) && is_array( $metadata['dependencies'] )
			? array_values( array_filter( $metadata['dependencies'], 'is_string' ) )
			: array();
		$version      = is_array( $metadata ) && isset( $metadata['version'] ) && is_string( $metadata['version'] )
			? $metadata['version']
			: PROMOGUARD_VERSION;

		return array(
			'dependencies' => $dependencies,
			'version'      => $version,
		);
	}
}
