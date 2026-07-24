<?php
/**
 * Administration settings and historical-indexing REST controller.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Api;

use DomainException;
use InvalidArgumentException;
use PromoGuard\Administration\AdministrationSettingsStore;
use PromoGuard\Administration\WordPressAdministrationSettingsStore;
use PromoGuard\Indexing\WooCommerceHistoricalIndexing;
use PromoGuard\Support\Capabilities;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/** Registers capability-separated settings and safe indexing-tool routes. */
final class AdministrationToolController {
	private const REST_NAMESPACE = 'promoguard/v1';

	/**
	 * Configure tool dependencies.
	 *
	 * @param AdministrationSettingsStore   $settings  Implemented settings persistence.
	 * @param WooCommerceHistoricalIndexing $indexing Shared historical-indexing orchestrator.
	 * @param AdministrationToolPresenter   $presenter Stable response mapper.
	 */
	public function __construct(
		private readonly AdministrationSettingsStore $settings,
		private readonly WooCommerceHistoricalIndexing $indexing,
		private readonly AdministrationToolPresenter $presenter
	) {}

	/**
	 * Build the controller for the active WordPress site.
	 *
	 * @param WooCommerceHistoricalIndexing $indexing Registered site indexer.
	 */
	public static function from_wordpress( WooCommerceHistoricalIndexing $indexing ): self {
		return new self(
			new WordPressAdministrationSettingsStore(),
			$indexing,
			new AdministrationToolPresenter()
		);
	}

	/** Register settings and historical-indexing routes. */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/administration/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'settings' ),
					'permission_callback' => array( $this, 'can_manage_settings' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'can_manage_settings' ),
					'args'                => AdministrationToolRouteSchema::settings(),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/administration/indexing',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'indexing' ),
				'permission_callback' => array( $this, 'can_run_tools' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/administration/indexing/start',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start_indexing' ),
				'permission_callback' => array( $this, 'can_run_tools' ),
				'args'                => AdministrationToolRouteSchema::indexing_start(),
			)
		);

		foreach ( array( 'pause', 'resume', 'retry', 'restart' ) as $action ) {
			register_rest_route(
				self::REST_NAMESPACE,
				'/administration/indexing/' . $action,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $action . '_indexing' ),
					'permission_callback' => array( $this, 'can_run_tools' ),
				)
			);
		}
	}

	/** Require the administrator-only settings capability. */
	public function can_manage_settings(): bool {
		return current_user_can( Capabilities::MANAGE_SETTINGS );
	}

	/** Require the explicit safe-tools capability. */
	public function can_run_tools(): bool {
		return current_user_can( Capabilities::RUN_TOOLS );
	}

	/** Return implemented settings and read-only storage health. */
	public function settings(): WP_REST_Response|WP_Error {
		return $this->respond(
			fn (): WP_REST_Response => new WP_REST_Response(
				$this->presenter->settings( $this->settings->current() )
			)
		);
	}

	/**
	 * Save explicit uninstall-cleanup consent.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond(
			function () use ( $request ): WP_REST_Response {
				$this->settings->save( (bool) $request->get_param( 'delete_data_on_uninstall' ) );

				return new WP_REST_Response(
					$this->presenter->settings( $this->settings->current() )
				);
			}
		);
	}

	/** Return current or most recent indexing progress. */
	public function indexing(): WP_REST_Response|WP_Error {
		return $this->respond(
			fn (): WP_REST_Response => new WP_REST_Response(
				$this->presenter->indexing( $this->indexing->current() )
			)
		);
	}

	/**
	 * Start full or targeted historical indexing.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function start_indexing( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond(
			function () use ( $request ): WP_REST_Response {
				$targets = $request->get_param( 'order_ids' );
				$targets = is_array( $targets ) ? array_map( 'intval', $targets ) : array();

				return new WP_REST_Response(
					$this->presenter->indexing(
						$this->indexing->start(
							$targets,
							(int) $request->get_param( 'batch_size' )
						)
					),
					202
				);
			}
		);
	}

	/** Pause the active indexing job. */
	public function pause_indexing(): WP_REST_Response|WP_Error {
		return $this->indexing_transition( 'pause' );
	}

	/** Resume the paused indexing job. */
	public function resume_indexing(): WP_REST_Response|WP_Error {
		return $this->indexing_transition( 'resume' );
	}

	/** Retry a failed job or completed job errors. */
	public function retry_indexing(): WP_REST_Response|WP_Error {
		return $this->indexing_transition( 'retry' );
	}

	/** Restart the current terminal or paused job. */
	public function restart_indexing(): WP_REST_Response|WP_Error {
		return $this->indexing_transition( 'restart' );
	}

	/**
	 * Run one known indexing state transition.
	 *
	 * @param string $action Valid transition method.
	 */
	private function indexing_transition( string $action ): WP_REST_Response|WP_Error {
		return $this->respond(
			function () use ( $action ): WP_REST_Response {
				$job = match ( $action ) {
					'pause' => $this->indexing->pause(),
					'resume' => $this->indexing->resume(),
					'retry' => $this->indexing->retry(),
					'restart' => $this->indexing->restart(),
					default => throw new InvalidArgumentException( 'Unsupported indexing action.' ),
				};

				return new WP_REST_Response( $this->presenter->indexing( $job ) );
			}
		);
	}

	/**
	 * Execute a route operation while exposing only safe errors.
	 *
	 * @param callable(): WP_REST_Response $operation Route operation.
	 */
	private function respond( callable $operation ): WP_REST_Response|WP_Error {
		try {
			return $operation();
		} catch ( DomainException | InvalidArgumentException $exception ) {
			return new WP_Error(
				'promoguard_invalid_request',
				$exception->getMessage(),
				array( 'status' => 400 )
			);
		} catch ( Throwable $exception ) {
			do_action( 'promoguard_rest_error', $exception );

			return new WP_Error(
				'promoguard_server_error',
				__( 'PromoGuard could not complete the request.', 'promoguard-for-woocommerce' ),
				array( 'status' => 500 )
			);
		}
	}
}
