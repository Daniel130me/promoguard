/** PromoGuard settings and historical-indexing controls. */
( function () {
	'use strict';

	const config = window.PromoGuardAdmin;
	const root = config ? document.getElementById( config.rootId ) : null;
	if ( ! root || ! window.wp?.i18n ) {
		return;
	}

	const { __, sprintf } = window.wp.i18n;
	const byId = ( id ) => document.getElementById( id );
	const settingsForm = byId( 'promoguard-settings-form' );
	const indexingForm = byId( 'promoguard-indexing-start-form' );
	const loading = { settings: false, tools: false };

	function buildRequestUrl( path ) {
		const url = new URL( config.restUrl, window.location.origin );
		const restRoute = url.searchParams.get( 'rest_route' );
		if ( null !== restRoute ) {
			url.searchParams.set( 'rest_route', `${ restRoute.replace( /\/$/, '' ) }${ path }` );
		} else {
			url.pathname = `${ url.pathname.replace( /\/$/, '' ) }${ path }`;
		}
		return url.toString();
	}

	async function request( path, method = 'GET', body = null ) {
		const options = {
			credentials: 'same-origin',
			headers: { Accept: 'application/json', 'X-WP-Nonce': config.nonce },
			method,
		};
		if ( null !== body ) {
			options.body = JSON.stringify( body );
			options.headers[ 'Content-Type' ] = 'application/json';
		}

		const response = await window.fetch( buildRequestUrl( path ), options );
		const data = await response.json();
		if ( ! response.ok ) {
			throw new Error( data?.message || __( 'PromoGuard could not complete the request.', 'promoguard-for-woocommerce' ) );
		}
		return data;
	}

	function formatDate( value ) {
		if ( ! value ) {
			return __( 'Not recorded', 'promoguard-for-woocommerce' );
		}
		return new Intl.DateTimeFormat( undefined, {
			dateStyle: 'medium',
			timeStyle: 'short',
			timeZone: 'UTC',
		} ).format( new Date( value ) );
	}

	async function loadSettings( force = false ) {
		if ( ! settingsForm || loading.settings || ( settingsForm.dataset.loaded && ! force ) ) {
			return;
		}
		const status = byId( 'promoguard-settings-status' );
		loading.settings = true;
		settingsForm.setAttribute( 'aria-busy', 'true' );
		status.textContent = __( 'Loading settings…', 'promoguard-for-woocommerce' );
		try {
			renderSettings( await request( '/administration/settings' ) );
			settingsForm.dataset.loaded = 'true';
			status.textContent = __( 'Settings loaded.', 'promoguard-for-woocommerce' );
		} catch ( error ) {
			status.textContent = error.message;
		} finally {
			loading.settings = false;
			settingsForm.setAttribute( 'aria-busy', 'false' );
		}
	}

	function renderSettings( settings ) {
		byId( 'promoguard-delete-data' ).checked = settings.delete_data_on_uninstall;
		byId( 'promoguard-customer-bonus-event' ).value = settings.signup_bonus.customer_event;
		byId( 'promoguard-customer-bonus-amount' ).value = settings.signup_bonus.customer_amount;
		byId( 'promoguard-vendor-bonus-event' ).value = settings.signup_bonus.vendor_event;
		byId( 'promoguard-vendor-bonus-amount' ).value = settings.signup_bonus.vendor_amount;
		const storageStatus = byId( 'promoguard-storage-status' );
		storageStatus.className = 'promoguard-status';
		if ( true === settings.storage_engine_supported ) {
			storageStatus.textContent = __( 'Compatible', 'promoguard-for-woocommerce' );
			storageStatus.classList.add( 'promoguard-status--completed' );
		} else if ( false === settings.storage_engine_supported ) {
			storageStatus.textContent = __( 'Unsupported', 'promoguard-for-woocommerce' );
			storageStatus.classList.add( 'promoguard-status--failed' );
		} else {
			storageStatus.textContent = __( 'Unknown', 'promoguard-for-woocommerce' );
		}
		byId( 'promoguard-storage-checked' ).textContent = settings.storage_engine_checked_at_gmt
			? sprintf( __( 'Last checked %s UTC.', 'promoguard-for-woocommerce' ), formatDate( settings.storage_engine_checked_at_gmt ) )
			: __( 'No storage compatibility check has been recorded.', 'promoguard-for-woocommerce' );
	}

	function parseOrderIds( value ) {
		const normalized = value.trim();
		if ( ! normalized ) {
			return [];
		}
		const values = normalized.split( ',' ).map( ( item ) => Number( item.trim() ) );
		const valid = values.every( ( item ) => Number.isSafeInteger( item ) && item > 0 );
		if ( ! valid || values.length > 100 || new Set( values ).size !== values.length ) {
			throw new Error( __( 'Enter up to 100 unique positive order IDs separated by commas.', 'promoguard-for-woocommerce' ) );
		}
		return values.sort( ( first, second ) => first - second );
	}

	function renderIndexing( job ) {
		const container = byId( 'promoguard-indexing-job' );
		const startButton = byId( 'promoguard-indexing-start' );
		if ( ! job ) {
			container.hidden = true;
			startButton.disabled = false;
			byId( 'promoguard-indexing-status-text' ).textContent = __( 'No historical indexing job exists.', 'promoguard-for-woocommerce' );
			return;
		}

		container.hidden = false;
		const active = [ 'queued', 'running', 'paused' ].includes( job.status );
		startButton.disabled = active;
		root.querySelectorAll( '[data-promoguard-job-metric]' ).forEach( ( metric ) => {
			metric.textContent = new Intl.NumberFormat().format( job[ metric.dataset.promoguardJobMetric ] );
		} );

		const statusBadge = byId( 'promoguard-indexing-status' );
		statusBadge.className = `promoguard-status promoguard-status--${ job.status }`;
		statusBadge.textContent = job.status;
		byId( 'promoguard-indexing-status-text' ).textContent = sprintf(
			__( 'Indexing job is %s.', 'promoguard-for-woocommerce' ),
			job.status
		);
		const scope = job.targeted
			? sprintf( __( '%d targeted orders', 'promoguard-for-woocommerce' ), job.target_order_ids.length )
			: __( 'all eligible historical orders', 'promoguard-for-woocommerce' );
		byId( 'promoguard-indexing-meta' ).textContent = sprintf(
			__( 'Batch size %1$d · Page %2$d · Scope: %3$s · Updated %4$s UTC', 'promoguard-for-woocommerce' ),
			job.batch_size,
			job.page,
			scope,
			formatDate( job.updated_at_gmt )
		);

		const allowed = {
			pause: [ 'queued', 'running' ].includes( job.status ),
			restart: [ 'paused', 'completed', 'failed' ].includes( job.status ),
			resume: 'paused' === job.status,
			retry: 'failed' === job.status || ( 'completed' === job.status && job.failed > 0 ),
		};
		root.querySelectorAll( '[data-promoguard-indexing-action]' ).forEach( ( button ) => {
			button.hidden = ! allowed[ button.dataset.promoguardIndexingAction ];
			button.disabled = false;
		} );
		renderIndexingErrors( job.errors );
	}

	function renderIndexingErrors( errors ) {
		const container = byId( 'promoguard-indexing-errors' );
		container.hidden = 0 === errors.length;
		const fragment = document.createDocumentFragment();
		errors.forEach( ( error ) => {
			const row = document.createElement( 'tr' );
			const order = document.createElement( 'td' );
			order.dataset.label = __( 'Order', 'promoguard-for-woocommerce' );
			order.textContent = error.order_id > 0 ? `#${ error.order_id }` : '—';
			const message = document.createElement( 'td' );
			message.dataset.label = __( 'Message', 'promoguard-for-woocommerce' );
			message.textContent = error.message;
			row.append( order, message );
			fragment.append( row );
		} );
		byId( 'promoguard-indexing-error-rows' ).replaceChildren( fragment );
	}

	async function loadIndexing( force = false ) {
		if ( ! indexingForm || loading.tools || ( indexingForm.dataset.loaded && ! force ) ) {
			return;
		}
		const status = byId( 'promoguard-indexing-status-text' );
		const refresh = byId( 'promoguard-indexing-refresh' );
		loading.tools = true;
		refresh.disabled = true;
		indexingForm.setAttribute( 'aria-busy', 'true' );
		status.textContent = __( 'Loading indexing status…', 'promoguard-for-woocommerce' );
		try {
			renderIndexing( await request( '/administration/indexing' ) );
			indexingForm.dataset.loaded = 'true';
		} catch ( error ) {
			status.textContent = error.message;
		} finally {
			loading.tools = false;
			refresh.disabled = false;
			indexingForm.setAttribute( 'aria-busy', 'false' );
		}
	}

	async function runIndexingAction( action, body = null ) {
		const status = byId( 'promoguard-indexing-status-text' );
		const refresh = byId( 'promoguard-indexing-refresh' );
		const start = byId( 'promoguard-indexing-start' );
		if ( loading.tools ) {
			return;
		}
		let updated = false;
		loading.tools = true;
		refresh.disabled = true;
		start.disabled = true;
		indexingForm.setAttribute( 'aria-busy', 'true' );
		status.textContent = __( 'Updating indexing job…', 'promoguard-for-woocommerce' );
		root.querySelectorAll( '[data-promoguard-indexing-action]' ).forEach( ( button ) => {
			button.disabled = true;
		} );
		try {
			const job = await request( `/administration/indexing/${ action }`, 'POST', body );
			renderIndexing( job );
			updated = true;
			indexingForm.dataset.loaded = 'true';
			status.textContent = __( 'Indexing job updated.', 'promoguard-for-woocommerce' );
		} catch ( error ) {
			status.textContent = error.message;
		} finally {
			loading.tools = false;
			refresh.disabled = false;
			if ( ! updated ) {
				start.disabled = false;
				root.querySelectorAll( '[data-promoguard-indexing-action]' ).forEach( ( button ) => {
					button.disabled = false;
				} );
			}
			indexingForm.setAttribute( 'aria-busy', 'false' );
		}
	}

	settingsForm?.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		if ( loading.settings ) {
			return;
		}
		const status = byId( 'promoguard-settings-status' );
		const save = byId( 'promoguard-settings-save' );
		loading.settings = true;
		save.disabled = true;
		status.textContent = __( 'Saving settings…', 'promoguard-for-woocommerce' );
		try {
			const settings = await request( '/administration/settings', 'PATCH', {
				delete_data_on_uninstall: byId( 'promoguard-delete-data' ).checked,
				signup_bonus: {
					customer_event: byId( 'promoguard-customer-bonus-event' ).value,
					customer_amount: byId( 'promoguard-customer-bonus-amount' ).value,
					vendor_event: byId( 'promoguard-vendor-bonus-event' ).value,
					vendor_amount: byId( 'promoguard-vendor-bonus-amount' ).value,
				},
			} );
			renderSettings( settings );
			status.textContent = __( 'Settings saved.', 'promoguard-for-woocommerce' );
		} catch ( error ) {
			status.textContent = error.message;
		} finally {
			loading.settings = false;
			save.disabled = false;
		}
	} );

	indexingForm?.addEventListener( 'submit', ( event ) => {
		event.preventDefault();
		try {
			const orderIds = parseOrderIds( byId( 'promoguard-indexing-order-ids' ).value );
			if ( 0 === orderIds.length && ! window.confirm( __( 'Start a full historical order scan?', 'promoguard-for-woocommerce' ) ) ) {
				return;
			}
			runIndexingAction( 'start', {
				batch_size: Number( byId( 'promoguard-indexing-batch-size' ).value ),
				order_ids: orderIds,
			} );
		} catch ( error ) {
			byId( 'promoguard-indexing-status-text' ).textContent = error.message;
		}
	} );

	byId( 'promoguard-indexing-refresh' )?.addEventListener( 'click', () => loadIndexing( true ) );
	root.querySelectorAll( '[data-promoguard-indexing-action]' ).forEach( ( button ) => {
		button.addEventListener( 'click', () => {
			const action = button.dataset.promoguardIndexingAction;
			if ( 'restart' !== action || window.confirm( __( 'Restart this job and reset its current progress?', 'promoguard-for-woocommerce' ) ) ) {
				runIndexingAction( action );
			}
		} );
	} );

	root.addEventListener( 'promoguard:view-change', ( event ) => {
		if ( 'settings' === event.detail.view ) {
			loadSettings();
		} else if ( 'tools' === event.detail.view ) {
			loadIndexing();
		}
	} );

	if ( 'settings' === window.location.hash.slice( 1 ) ) {
		loadSettings();
	} else if ( 'tools' === window.location.hash.slice( 1 ) ) {
		loadIndexing();
	}
}() );
