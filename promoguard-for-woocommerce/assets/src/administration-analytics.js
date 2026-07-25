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
	const state = { loaded: false, loading: false };

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

	function query() {
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
		const suffix = parameters.toString();
		return `/analytics/summary${ suffix ? `?${ suffix }` : '' }`;
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

	function renderEmptyRow( body, columns, message ) {
		const row = document.createElement( 'tr' );
		const cell = document.createElement( 'td' );
		cell.colSpan = columns;
		cell.textContent = message;
		row.append( cell );
		body.replaceChildren( row );
	}

	function render( data ) {
		Object.entries( data.totals ).forEach( ( [ key, value ] ) => {
			const metric = root.querySelector( `[data-promoguard-analytics-metric="${ key }"]` );
			if ( metric ) {
				metric.textContent = new Intl.NumberFormat().format( value );
			}
		} );

		const currencyBody = byId( 'promoguard-analytics-currency-rows' );
		if ( 0 === data.currencies.length ) {
			renderEmptyRow( currencyBody, 3, __( 'No consumed or restored discounts in this period.', 'promoguard-for-woocommerce' ) );
		} else {
			const fragment = document.createDocumentFragment();
			data.currencies.forEach( ( item ) => {
				const row = document.createElement( 'tr' );
				row.append(
					createCell( __( 'Currency', 'promoguard-for-woocommerce' ), item.currency ),
					createCell( __( 'Consumed discount', 'promoguard-for-woocommerce' ), formatMoney( item.discount_amount, item.currency ), 'promoguard-admin__number' ),
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
					createCell( __( 'Denied attempts', 'promoguard-for-woocommerce' ), new Intl.NumberFormat().format( item.count ), 'promoguard-admin__number' )
				);
				fragment.append( row );
			} );
			denialBody.replaceChildren( fragment );
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
		];
		state.loading = true;
		metrics.setAttribute( 'aria-busy', 'true' );
		tables.forEach( ( table ) => table.setAttribute( 'aria-busy', 'true' ) );
		status.textContent = __( 'Loading analyticsâ€¦', 'promoguard-for-woocommerce' );
		try {
			const data = await request( query() );
			render( data );
			state.loaded = true;
			status.textContent = sprintf(
				__( '%1$d redemptions across %2$d unique orders.', 'promoguard-for-woocommerce' ),
				data.totals.redemptions,
				data.totals.global_orders
			);
		} catch ( error ) {
			status.textContent = error.message;
		} finally {
			state.loading = false;
			metrics.setAttribute( 'aria-busy', 'false' );
			tables.forEach( ( table ) => table.setAttribute( 'aria-busy', 'false' ) );
		}
	}

	const form = byId( 'promoguard-analytics-filters' );
	form?.addEventListener( 'submit', ( event ) => {
		event.preventDefault();
		state.loaded = false;
		load( true );
	} );
	form?.addEventListener( 'reset', () => {
		window.setTimeout( () => {
			state.loaded = false;
			load( true );
		}, 0 );
	} );
	byId( 'promoguard-analytics-refresh' )?.addEventListener( 'click', () => {
		state.loaded = false;
		load( true );
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
