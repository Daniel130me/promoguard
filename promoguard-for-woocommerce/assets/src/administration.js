/** PromoGuard full administration navigation and report views. */
( function () {
	'use strict';

	const config = window.PromoGuardAdmin;
	const root = config ? document.getElementById( config.rootId ) : null;
	if ( ! root || ! window.wp?.i18n ) {
		return;
	}

	const { __, sprintf } = window.wp.i18n;
	const byId = ( id ) => document.getElementById( id );
	const navigation = [ ...root.querySelectorAll( '[data-promoguard-view]' ) ];
	const reportStates = {
		decisions: { loaded: false, loading: false, page: 1, pages: 1, perPage: 20 },
		usages: { loaded: false, loading: false, page: 1, pages: 1, perPage: 20 },
	};
	const viewCopy = {
		campaigns: {
			description: __( 'Organize native WooCommerce coupons without changing coupon ownership or order history.', 'promoguard-for-woocommerce' ),
			title: __( 'Campaigns', 'promoguard-for-woocommerce' ),
		},
		dashboard: {
			description: __( 'Monitor campaign and usage lifecycle records without running expensive order scans.', 'promoguard-for-woocommerce' ),
			title: __( 'Dashboard', 'promoguard-for-woocommerce' ),
		},
		decisions: {
			description: __( 'Review safe, persisted explanations for denied promotion attempts.', 'promoguard-for-woocommerce' ),
			title: __( 'Decisions', 'promoguard-for-woocommerce' ),
		},
		usages: {
			description: __( 'Trace reservation and redemption outcomes from the PromoGuard usage ledger.', 'promoguard-for-woocommerce' ),
			title: __( 'Usage history', 'promoguard-for-woocommerce' ),
		},
	};
	const campaignPanels = [
		byId( 'promoguard-create-panel' ),
		byId( 'promoguard-edit-panel' ),
		byId( 'promoguard-campaign-list-panel' ),
	].filter( Boolean );
	const headerTitle = byId( 'promoguard-page-title' );
	const headerDescription = byId( 'promoguard-page-description' );
	const createCampaign = byId( 'promoguard-create-toggle' );


	function buildRequestUrl( path ) {
		const url = new URL( config.restUrl, window.location.origin );
		const [ routePath, query = '' ] = path.split( '?' );
		const restRoute = url.searchParams.get( 'rest_route' );
		if ( null !== restRoute ) {
			url.searchParams.set( 'rest_route', `${ restRoute.replace( /\/$/, '' ) }${ routePath }` );
		} else {
			url.pathname = `${ url.pathname.replace( /\/$/, '' ) }${ routePath }`;
		}
		new URLSearchParams( query ).forEach( ( value, key ) => url.searchParams.set( key, value ) );
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

	function setView( requestedView, updateHash = true ) {
		const available = navigation.some( ( item ) => item.dataset.promoguardView === requestedView );
		const view = available ? requestedView : 'campaigns';

		navigation.forEach( ( item ) => {
			const active = item.dataset.promoguardView === view;
			item.classList.toggle( 'is-active', active );
			if ( active ) {
				item.setAttribute( 'aria-current', 'page' );
			} else {
				item.removeAttribute( 'aria-current' );
			}
		} );
		root.querySelectorAll( '[data-promoguard-panel]' ).forEach( ( panel ) => {
			panel.hidden = panel.dataset.promoguardPanel !== view;
		} );
		campaignPanels.forEach( ( panel ) => {
			if ( 'campaigns' !== view ) {
				panel.hidden = true;
			} else if ( 'promoguard-campaign-list-panel' === panel.id ) {
				panel.hidden = false;
			}
		} );
		createCampaign.hidden = 'campaigns' !== view;
		headerTitle.textContent = viewCopy[ view ].title;
		headerDescription.textContent = viewCopy[ view ].description;

		if ( updateHash ) {
			window.history.replaceState( null, '', `#${ view }` );
		}
		loadView( view );
	}

	function loadView( view ) {
		if ( 'dashboard' === view ) {
			loadDashboard();
		} else if ( 'usages' === view || 'decisions' === view ) {
			loadReport( view );
		}
	}

	async function loadDashboard() {
		const status = byId( 'promoguard-dashboard-status' );
		const metrics = byId( 'promoguard-dashboard-metrics' );
		if ( ! status || 'true' === metrics.dataset.loaded ) {
			return;
		}
		metrics.setAttribute( 'aria-busy', 'true' );
		status.textContent = __( 'Loading dashboard…', 'promoguard-for-woocommerce' );
		try {
			const data = await request( '/administration/overview' );
			Object.entries( data.campaigns ).forEach( ( [ key, value ] ) => {
				const metric = root.querySelector( `[data-promoguard-metric="campaign-${ key }"]` );
				if ( metric ) {
					metric.textContent = new Intl.NumberFormat().format( value );
				}
			} );
			Object.entries( data.usages ).forEach( ( [ key, value ] ) => {
				const metric = root.querySelector( `[data-promoguard-metric="usage-${ key }"]` );
				if ( metric ) {
					metric.textContent = new Intl.NumberFormat().format( value );
				}
			} );
			metrics.dataset.loaded = 'true';
			status.textContent = sprintf(
				__( '%1$d campaigns and %2$d usage records.', 'promoguard-for-woocommerce' ),
				data.totals.campaigns,
				data.totals.usages
			);
		} catch ( error ) {
			status.textContent = error.message;
		} finally {
			metrics.setAttribute( 'aria-busy', 'false' );
		}
	}

	function createCell( label, value, className = '' ) {
		const cell = document.createElement( 'td' );
		cell.dataset.label = label;
		cell.className = className;
		cell.textContent = value ?? '—';
		return cell;
	}

	function formatDate( value ) {
		if ( ! value ) {
			return '—';
		}
		return new Intl.DateTimeFormat( undefined, {
			dateStyle: 'medium',
			timeStyle: 'short',
			timeZone: 'UTC',
		} ).format( new Date( value ) );
	}

	function formatIdentity( prefix, value ) {
		return value ? `${ prefix } #${ new Intl.NumberFormat().format( value ) }` : '—';
	}

	function renderUsages( items ) {
		const fragment = document.createDocumentFragment();
		items.forEach( ( item ) => {
			const row = document.createElement( 'tr' );
			const stateCell = createCell( __( 'State', 'promoguard-for-woocommerce' ), '' );
			const badge = document.createElement( 'span' );
			badge.className = `promoguard-status promoguard-status--${ item.status }`;
			badge.textContent = item.status;
			stateCell.append( badge );
			row.append(
				createCell( __( 'Campaign', 'promoguard-for-woocommerce' ), item.campaign_name || formatIdentity( __( 'Campaign', 'promoguard-for-woocommerce' ), item.campaign_id ) ),
				createCell( __( 'Order', 'promoguard-for-woocommerce' ), formatIdentity( __( 'Order', 'promoguard-for-woocommerce' ), item.order_id ), 'promoguard-admin__number' ),
				createCell( __( 'Customer', 'promoguard-for-woocommerce' ), formatIdentity( __( 'Customer', 'promoguard-for-woocommerce' ), item.customer_id ), 'promoguard-admin__number' ),
				createCell( __( 'Coupon', 'promoguard-for-woocommerce' ), item.coupon_code ),
				stateCell,
				createCell( __( 'Discount', 'promoguard-for-woocommerce' ), `${ item.currency } ${ item.discount_amount }`, 'promoguard-admin__number' ),
				createCell( __( 'Recorded', 'promoguard-for-woocommerce' ), formatDate( item.created_at_gmt ) )
			);
			fragment.append( row );
		} );
		byId( 'promoguard-usage-rows' ).replaceChildren( fragment );
	}

	function renderDecisions( items ) {
		const fragment = document.createDocumentFragment();
		items.forEach( ( item ) => {
			const row = document.createElement( 'tr' );
			row.append(
				createCell( __( 'Campaign', 'promoguard-for-woocommerce' ), item.campaign_name || formatIdentity( __( 'Campaign', 'promoguard-for-woocommerce' ), item.campaign_id ) ),
				createCell( __( 'Order', 'promoguard-for-woocommerce' ), formatIdentity( __( 'Order', 'promoguard-for-woocommerce' ), item.order_id ), 'promoguard-admin__number' ),
				createCell( __( 'Customer', 'promoguard-for-woocommerce' ), formatIdentity( __( 'Customer', 'promoguard-for-woocommerce' ), item.customer_id ), 'promoguard-admin__number' ),
				createCell( __( 'Coupon', 'promoguard-for-woocommerce' ), item.coupon_code ),
				createCell( __( 'Context', 'promoguard-for-woocommerce' ), item.context ),
				createCell( __( 'Reason', 'promoguard-for-woocommerce' ), item.reason ),
				createCell( __( 'Explanation', 'promoguard-for-woocommerce' ), item.admin_explanation ),
				createCell( __( 'Recorded', 'promoguard-for-woocommerce' ), formatDate( item.created_at_gmt ) )
			);
			fragment.append( row );
		} );
		byId( 'promoguard-decision-rows' ).replaceChildren( fragment );
	}

	function reportQuery( view ) {
		const state = reportStates[ view ];
		const form = byId( `promoguard-${ 'usages' === view ? 'usage' : 'decision' }-filters` );
		const query = new URLSearchParams( {
			page: String( state.page ),
			per_page: String( state.perPage ),
		} );
		new window.FormData( form ).forEach( ( value, key ) => {
			const normalized = String( value ).trim();
			if ( normalized ) {
				query.set( key, normalized );
			}
		} );
		return query;
	}

	async function loadReport( view, force = false ) {
		const state = reportStates[ view ];
		if ( state.loading || ( state.loaded && ! force ) ) {
			return;
		}
		const prefix = 'usages' === view ? 'usage' : 'decision';
		const table = byId( `promoguard-${ prefix }-table` );
		const status = byId( `promoguard-${ prefix }-status-text` );
		state.loading = true;
		table.setAttribute( 'aria-busy', 'true' );
		status.textContent = __( 'Loading records…', 'promoguard-for-woocommerce' );
		try {
			const data = await request( `/administration/${ view }?${ reportQuery( view ).toString() }` );
			if ( 'usages' === view ) {
				renderUsages( data.items );
			} else {
				renderDecisions( data.items );
			}
			state.pages = Math.max( 1, Math.ceil( data.total / data.per_page ) );
			state.loaded = true;
			status.textContent = 0 === data.total
				? __( 'No matching records.', 'promoguard-for-woocommerce' )
				: sprintf( __( '%d matching records.', 'promoguard-for-woocommerce' ), data.total );
			byId( `promoguard-${ prefix }-page-status` ).textContent = sprintf(
				__( 'Page %1$d of %2$d', 'promoguard-for-woocommerce' ),
				state.page,
				state.pages
			);
		} catch ( error ) {
			status.textContent = error.message;
		} finally {
			state.loading = false;
			table.setAttribute( 'aria-busy', 'false' );
			updateReportPagination( view );
		}
	}

	function updateReportPagination( view ) {
		const state = reportStates[ view ];
		const prefix = 'usages' === view ? 'usage' : 'decision';
		byId( `promoguard-${ prefix }-previous` ).disabled = state.loading || state.page <= 1;
		byId( `promoguard-${ prefix }-next` ).disabled = state.loading || state.page >= state.pages;
	}

	function bindReport( view ) {
		const state = reportStates[ view ];
		const prefix = 'usages' === view ? 'usage' : 'decision';
		const form = byId( `promoguard-${ prefix }-filters` );
		form.addEventListener( 'submit', ( event ) => {
			event.preventDefault();
			state.page = 1;
			state.loaded = false;
			loadReport( view, true );
		} );
		form.addEventListener( 'reset', () => {
			window.setTimeout( () => {
				state.page = 1;
				state.loaded = false;
				loadReport( view, true );
			}, 0 );
		} );
		byId( `promoguard-${ prefix }-previous` ).addEventListener( 'click', () => {
			if ( ! state.loading && state.page > 1 ) {
				state.page -= 1;
				state.loaded = false;
				loadReport( view, true );
			}
		} );
		byId( `promoguard-${ prefix }-next` ).addEventListener( 'click', () => {
			if ( ! state.loading && state.page < state.pages ) {
				state.page += 1;
				state.loaded = false;
				loadReport( view, true );
			}
		} );
	}

	navigation.forEach( ( item ) => {
		item.addEventListener( 'click', () => setView( item.dataset.promoguardView ) );
	} );
	byId( 'promoguard-dashboard-refresh' )?.addEventListener( 'click', () => {
		byId( 'promoguard-dashboard-metrics' ).dataset.loaded = '';
		loadDashboard();
	} );
	if ( byId( 'promoguard-usage-filters' ) ) {
		bindReport( 'usages' );
		bindReport( 'decisions' );
	}

	window.addEventListener( 'hashchange', () => setView( window.location.hash.slice( 1 ), false ) );
	const requested = window.location.hash.slice( 1 );
	const defaultView = navigation.some( ( item ) => 'dashboard' === item.dataset.promoguardView )
		? 'dashboard'
		: 'campaigns';
	setView( requested || defaultView, ! requested );
}() );
