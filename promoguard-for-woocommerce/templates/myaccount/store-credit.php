<?php
/**
 * Customer store-credit balances and ledger.
 *
 * @package PromoGuard
 * @var array<int,array{currency:string,balance:string,reserved:string,available:string}> $balances Currency balances.
 * @var array<int,array{id:int,type:string,source:string,reference:string,amount:string,balance:string,currency:string,description:string,created_at_gmt:string}> $entries Ledger entries.
 * @var int    $page Current page.
 * @var bool   $has_previous Whether an earlier page exists.
 * @var bool   $has_next Whether a later page exists.
 * @var string $endpoint_url Account endpoint URL.
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="promoguard-credit-account">
	<h2><?php esc_html_e( 'Store credit', 'promoguard-for-woocommerce' ); ?></h2>
	<p><?php esc_html_e( 'Store credit can be used on eligible purchases and cannot be exchanged for cash.', 'promoguard-for-woocommerce' ); ?></p>

	<h3><?php esc_html_e( 'Balances', 'promoguard-for-woocommerce' ); ?></h3>
	<?php if ( array() === $balances ) : ?>
		<p><?php esc_html_e( 'You do not have a store-credit balance yet.', 'promoguard-for-woocommerce' ); ?></p>
	<?php else : ?>
		<table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Currency', 'promoguard-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Available', 'promoguard-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Reserved', 'promoguard-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Balance', 'promoguard-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $balances as $balance ) : ?>
					<tr>
						<td data-title="<?php esc_attr_e( 'Currency', 'promoguard-for-woocommerce' ); ?>"><?php echo esc_html( $balance['currency'] ); ?></td>
						<td data-title="<?php esc_attr_e( 'Available', 'promoguard-for-woocommerce' ); ?>"><?php echo wp_kses_post( wc_price( (float) $balance['available'], array( 'currency' => $balance['currency'] ) ) ); ?></td>
						<td data-title="<?php esc_attr_e( 'Reserved', 'promoguard-for-woocommerce' ); ?>"><?php echo wp_kses_post( wc_price( (float) $balance['reserved'], array( 'currency' => $balance['currency'] ) ) ); ?></td>
						<td data-title="<?php esc_attr_e( 'Balance', 'promoguard-for-woocommerce' ); ?>"><?php echo wp_kses_post( wc_price( (float) $balance['balance'], array( 'currency' => $balance['currency'] ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Activity', 'promoguard-for-woocommerce' ); ?></h3>
	<?php if ( array() === $entries ) : ?>
		<p><?php esc_html_e( 'No store-credit activity was found.', 'promoguard-for-woocommerce' ); ?></p>
	<?php else : ?>
		<table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'promoguard-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Description', 'promoguard-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Amount', 'promoguard-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Balance', 'promoguard-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<tr>
						<td data-title="<?php esc_attr_e( 'Date', 'promoguard-for-woocommerce' ); ?>">
							<?php echo esc_html( wc_format_datetime( wc_string_to_datetime( $entry['created_at_gmt'] ) ) ); ?>
						</td>
						<td data-title="<?php esc_attr_e( 'Description', 'promoguard-for-woocommerce' ); ?>">
							<?php echo esc_html( '' !== $entry['description'] ? $entry['description'] : ucwords( str_replace( '_', ' ', $entry['type'] ) ) ); ?>
						</td>
						<td data-title="<?php esc_attr_e( 'Amount', 'promoguard-for-woocommerce' ); ?>"><?php echo wp_kses_post( wc_price( (float) $entry['amount'], array( 'currency' => $entry['currency'] ) ) ); ?></td>
						<td data-title="<?php esc_attr_e( 'Balance', 'promoguard-for-woocommerce' ); ?>"><?php echo wp_kses_post( wc_price( (float) $entry['balance'], array( 'currency' => $entry['currency'] ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( $has_previous || $has_next ) : ?>
		<nav class="woocommerce-pagination woocommerce-pagination--without-numbers woocommerce-Pagination">
			<?php if ( $has_previous ) : ?>
				<a class="woocommerce-button woocommerce-button--previous button" href="<?php echo esc_url( add_query_arg( 'credit-page', $page - 1, $endpoint_url ) ); ?>"><?php esc_html_e( 'Previous', 'promoguard-for-woocommerce' ); ?></a>
			<?php endif; ?>
			<?php if ( $has_next ) : ?>
				<a class="woocommerce-button woocommerce-button--next button" href="<?php echo esc_url( add_query_arg( 'credit-page', $page + 1, $endpoint_url ) ); ?>"><?php esc_html_e( 'Next', 'promoguard-for-woocommerce' ); ?></a>
			<?php endif; ?>
		</nav>
	<?php endif; ?>
</section>
