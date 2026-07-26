/** PromoGuard analytics administration view. */
( function () {
	'use strict';

	const config = window.PromoGuardAdmin;
	const root = config ? document.getElementById( config.rootId ) : null;
	if ( ! root || ! window.wp?.i18n ) {
		return;
	}

	const { __, sprintf } = window.wp.i18n;
	const byId = ( id ) => document.getElementById( id );
	const state = {
		loaded: false,
		loading: false,
		campaigns: { confirmedPage: 1, loading: false, page: 1, pages: 1, perPage: 10 },
	};

	function buildRequestUrl( path ) {
		const url = new URL( config.restUrl, window.location.origin );
		const [ routePath, queryString = '' ] = path.split( '?' );
		const restRoute = url.searchParams.get( 'rest_route' );
		if ( null !== restRoute ) {
			url.searchParams.set( 'rest_route', `${ restRoute.replace( /\/$/, '' ) }${ routePath }` );
		} else {
			url.pathname = `${ url.pathname.replace( /\/$/, '' ) }${ routePath }`;
		}
		new URLSearchParams( queryString ).forEach( ( value, key ) => url.searchParams.set( key, value ) );
		return url.toString();
	}

	async function request( path ) {
		const response = await window.fetch( buildRequestUrl( path ), {
			credentials: 'same-origin',
			headers: { Accept: 'application/json', 'X-WP-Nonce': config.nonce },
		} );
		const data = await response.json();
		if ( ! response.ok ) {
			throw new Error( data?.message || __( 'PromoGuard could not complete the request.', 'promoguard-for-woocommerce' ) );
		}
		return data;
	}

	function filterParameters() {
		const parameters = new URLSearchParams();
		new window.FormData( byId( 'promoguard-analytics-filters' ) ).forEach( ( value, key ) => {
			const normalized = String( value ).trim();
			if ( ! normalized ) {
				return;
			}
			if ( 'starts_at_gmt' === key || 'ends_at_gmt' === key ) {
				parameters.set( key, new Date( `${ normalized }Z` ).toISOString() );
			} else {
				parameters.set( key, normalized );
			}
		} );
		return parameters;
	}

	function reportPath( route, parameters ) {
		const query = parameters.toString();
		return `${ route }${ query ? `?${ query }` : '' }`;
	}

	function summaryPath() {
		return reportPath( '/analytics/summary', filterParameters() );
	}

	function campaignPath() {
		const parameters = filterParameters();
		parameters.set( 'page', String( state.campaigns.page ) );
		parameters.set( 'per_page', String( state.campaigns.perPage ) );
		return reportPath( '/analytics/campaigns', parameters );
	}

	function createCell( label, value, className = '' ) {
		const cell = document.createElement( 'td' );
		cell.dataset.label = label;
		cell.className = className;
		cell.textContent = value;
		return cell;
	}

	function formatMoney( amount, currency ) {
		try {
			return new Intl.NumberFormat( undefined, {
				currency,
				currencyDisplay: 'code',
				style: 'currency',
			} ).format( Number( amount ) );
		} catch ( error ) {
			return `${ currency } ${ amount }`;
		}
	}

	function formatCount( value ) {
		return new Intl.NumberFormat().format( value );
	}

	function renderEmptyRow( body, columns, message ) {
		const row = document.createElement( 'tr' );
		const cell = document.createElement( 'td' );
		cell.colSpan = columns;
		cell.textContent = message;
		row.append( cell );
		body.replaceChildren( row );
	}

	function renderSummary( data ) {
		Object.entries( data.totals ).forEach( ( [ key, value ] ) => {
			const metric = root.querySelector( `[data-promoguard-analytics-metric="${ key }"]` );
			if ( metric ) {
				metric.textContent = formatCount( value );
			}
		} );

		const currencyBody = byId( 'promoguard-analytics-currency-rows' );
		if ( 0 === data.currencies.length ) {
			renderEmptyRow( currencyBody, 8, __( 'No consumed or restored discounts in this period.', 'promoguard-for-woocommerce' ) );
		} else {
			const fragment = document.createDocumentFragment();
			data.currencies.forEach( ( item ) => {
				const row = document.createElement( 'tr' );
				row.append(
					createCell( __( 'Currency', 'promoguard-for-woocommerce' ), item.currency ),
					createCell( __( 'Redemptions', 'promoguard-for-woocommerce' ), formatCount( item.redemptions ), 'promoguard-admin__number' ),
					createCell( __( 'Consumed discount', 'promoguard-for-woocommerce' ), formatMoney( item.discount_amount, item.currency ), 'promoguard-admin__number' ),
					createCell( __( 'Average discount', 'promoguard-for-woocommerce' ), formatMoney( item.average_discount_amount, item.currency ), 'promoguard-admin__number' ),
					createCell( __( 'Orders', 'promoguard-for-woocommerce' ), formatCount( item.order_count ), 'promoguard-admin__number' ),
					createCell( __( 'Order revenue', 'promoguard-for-woocommerce' ), formatMoney( item.revenue_amount, item.currency ), 'promoguard-admin__number' ),
					createCell( __( 'Average order', 'promoguard-for-woocommerce' ), formatMoney( item.average_order_amount, item.currency ), 'promoguard-admin__number' ),
					createCell( __( 'Restored discount', 'promoguard-for-woocommerce' ), formatMoney( item.restored_discount_amount, item.currency ), 'promoguard-admin__number' )
				);
				fragment.append( row );
			} );
			currencyBody.replaceChildren( fragment );
		}

		const denialBody = byId( 'promoguard-analytics-denial-rows' );
		if ( 0 === data.denial_reasons.length ) {
			renderEmptyRow( denialBody, 2, __( 'No persisted denials in this period.', 'promoguard-for-woocommerce' ) );
		} else {
			const fragment = document.createDocumentFragment();
			data.denial_reasons.forEach( ( item ) => {
				const row = document.createElement( 'tr' );
				row.append(
					createCell( __( 'Reason', 'promoguard-for-woocommerce' ), item.reason ),
					createCell( __( 'Denied attempts', 'promoguard-for-woocommerce' ), formatCount( item.count ), 'promoguard-admin__number' )
				);
				fragment.append( row );
			} );
			denialBody.replaceChildren( fragment );
		}
	}

	function campaignCurrencyCell( currencies ) {
		const cell = createCell( __( 'Currency facts', 'promoguard-for-woocommerce' ), '' );
		cell.classList.add( 'promoguard-analytics__currency-list' );
		if ( 0 === currencies.length ) {
			cell.textContent = __( 'No monetary usage', 'promoguard-for-woocommerce' );
			return cell;
		}

		currencies.forEach( ( item ) => {
			const line = document.createElement( 'span' );
			line.textContent = sprintf(
				__( '%1$s: %2$s discount, %3$s average', 'promoguard-for-woocommerce' ),
				item.currency,
				formatMoney( item.discount_amount, item.currency ),
				formatMoney( item.average_discount_amount, item.currency )
			);
			cell.append( line );
		} );
		return cell;
	}

	function renderCampaigns( data ) {
		const body = byId( 'promoguard-analytics-campaign-rows' );
		if ( 0 === data.items.length ) {
			renderEmptyRow( body, 7, __( 'No campaign activity in this period.', 'promoguard-for-woocommerce' ) );
		} else {
			const fragment = document.createDocumentFragment();
			data.items.forEach( ( item ) => {
				const row = document.createElement( 'tr' );
				row.append(
					createCell( __( 'Campaign', 'promoguard-for-woocommerce' ), `${ item.campaign_name } (#${ item.campaign_id })` ),
					createCell( __( 'Redemptions', 'promoguard-for-woocommerce' ), formatCount( item.redemptions ), 'promoguard-admin__number' ),
					createCell( __( 'Customers', 'promoguard-for-woocommerce' ), formatCount( item.unique_customers ), 'promoguard-admin__number' ),
					createCell( __( 'Orders', 'promoguard-for-woocommerce' ), formatCount( item.campaign_orders ), 'promoguard-admin__number' ),
					createCell( __( 'Restored', 'promoguard-for-woocommerce' ), formatCount( item.refunds ), 'promoguard-admin__number' ),
					createCell( __( 'Denials', 'promoguard-for-woocommerce' ), formatCount( item.denials ), 'promoguard-admin__number' ),
					campaignCurrencyCell( item.currencies )
				);
				fragment.append( row );
			} );
			body.replaceChildren( fragment );
		}

		state.campaigns.pages = Math.max( 1, Math.ceil( data.total / data.per_page ) );
		state.campaigns.page = data.page;
		state.campaigns.confirmedPage = data.page;
		byId( 'promoguard-analytics-campaign-page-status' ).textContent = sprintf(
			__( 'Page %1$d of %2$d', 'promoguard-for-woocommerce' ),
			state.campaigns.page,
			state.campaigns.pages
		);
		byId( 'promoguard-analytics-campaign-status' ).textContent = 0 === data.total
			? __( 'No campaign activity in this period.', 'promoguard-for-woocommerce' )
			: sprintf( __( '%d campaigns with activity.', 'promoguard-for-woocommerce' ), data.total );
		updateCampaignPagination();
	}

	function updateCampaignPagination() {
		byId( 'promoguard-analytics-campaign-previous' ).disabled = state.campaigns.loading || state.campaigns.page <= 1;
		byId( 'promoguard-analytics-campaign-next' ).disabled = state.campaigns.loading || state.campaigns.page >= state.campaigns.pages;
	}

	async function loadCampaignPage() {
		if ( state.campaigns.loading ) {
			return;
		}
		const status = byId( 'promoguard-analytics-campaign-status' );
		const table = byId( 'promoguard-analytics-campaign-table' );
		state.campaigns.loading = true;
		table.setAttribute( 'aria-busy', 'true' );
		status.textContent = __( 'Loading campaign performance...', 'promoguard-for-woocommerce' );
		updateCampaignPagination();
		try {
			renderCampaigns( await request( campaignPath() ) );
		} catch ( error ) {
			state.campaigns.page = state.campaigns.confirmedPage;
			status.textContent = error.message;
		} finally {
			state.campaigns.loading = false;
			table.setAttribute( 'aria-busy', 'false' );
			updateCampaignPagination();
		}
	}

	async function load( force = false ) {
		if ( state.loading || ( state.loaded && ! force ) ) {
			return;
		}
		const status = byId( 'promoguard-analytics-status' );
		const metrics = byId( 'promoguard-analytics-metrics' );
		const tables = [
			byId( 'promoguard-analytics-currency-table' ),
			byId( 'promoguard-analytics-denial-table' ),
			byId( 'promoguard-analytics-campaign-table' ),
		];
		state.loading = true;
		state.campaigns.loading = true;
		metrics.setAttribute( 'aria-busy', 'true' );
		tables.forEach( ( table ) => table.setAttribute( 'aria-busy', 'true' ) );
		status.textContent = __( 'Loading analytics...', 'promoguard-for-woocommerce' );
		byId( 'promoguard-analytics-campaign-status' ).textContent = __( 'Loading campaign performance...', 'promoguard-for-woocommerce' );
		updateCampaignPagination();
		try {
			const [ summary, campaigns ] = await Promise.all( [
				request( summaryPath() ),
				request( campaignPath() ),
			] );
			renderSummary( summary );
			renderCampaigns( campaigns );
			state.loaded = true;
			status.textContent = sprintf(
				__( '%1$d redemptions across %2$d unique orders.', 'promoguard-for-woocommerce' ),
				summary.totals.redemptions,
				summary.totals.global_orders
			);
		} catch ( error ) {
			status.textContent = error.message;
			byId( 'promoguard-analytics-campaign-status' ).textContent = error.message;
		} finally {
			state.loading = false;
			state.campaigns.loading = false;
			metrics.setAttribute( 'aria-busy', 'false' );
			tables.forEach( ( table ) => table.setAttribute( 'aria-busy', 'false' ) );
			updateCampaignPagination();
		}
	}

	function reloadFromFirstPage() {
		state.loaded = false;
		state.campaigns.page = 1;
		load( true );
	}

	const form = byId( 'promoguard-analytics-filters' );
	form?.addEventListener( 'submit', ( event ) => {
		event.preventDefault();
		reloadFromFirstPage();
	} );
	form?.addEventListener( 'reset', () => window.setTimeout( reloadFromFirstPage, 0 ) );
	byId( 'promoguard-analytics-refresh' )?.addEventListener( 'click', reloadFromFirstPage );
	byId( 'promoguard-analytics-campaign-previous' )?.addEventListener( 'click', () => {
		if ( ! state.campaigns.loading && state.campaigns.page > 1 ) {
			state.campaigns.page -= 1;
			loadCampaignPage();
		}
	} );
	byId( 'promoguard-analytics-campaign-next' )?.addEventListener( 'click', () => {
		if ( ! state.campaigns.loading && state.campaigns.page < state.campaigns.pages ) {
			state.campaigns.page += 1;
			loadCampaignPage();
		}
	} );
	root.addEventListener( 'promoguard:view-change', ( event ) => {
		if ( 'analytics' === event.detail?.view ) {
			load();
		}
	} );
	if ( 'analytics' === window.location.hash.slice( 1 ) ) {
		load();
	}
}() );