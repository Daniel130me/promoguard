<?php
/**
 * Full administration REST controller.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Api;

use DomainException;
use InvalidArgumentException;
use PromoGuard\Administration\AdministrationRepository;
use PromoGuard\Administration\AdministrationStore;
use PromoGuard\Support\Capabilities;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/** Registers report-capability-protected operational administration endpoints. */
final class AdministrationController {
	private const REST_NAMESPACE = 'promoguard/v1';

	/**
	 * Configure administration dependencies.
	 *
	 * @param AdministrationStore     $store     Bounded read model.
	 * @param AdministrationPresenter $presenter Stable response mapper.
	 */
	public function __construct(
		private readonly AdministrationStore $store,
		private readonly AdministrationPresenter $presenter
	) {}

	/** Build the controller for the active WordPress site. */
	public static function from_wordpress(): self {
		return new self(
			AdministrationRepository::from_wordpress(),
			new AdministrationPresenter()
		);
	}

	/** Register Phase 9 read-only administration routes. */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/administration/overview',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'overview' ),
				'permission_callback' => array( $this, 'can_view_reports' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/administration/usages',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'usages' ),
				'permission_callback' => array( $this, 'can_view_reports' ),
				'args'                => AdministrationRouteSchema::usages(),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/administration/decisions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'decisions' ),
				'permission_callback' => array( $this, 'can_view_reports' ),
				'args'                => AdministrationRouteSchema::decisions(),
			)
		);
	}

	/** Require the explicit reports capability. */
	public function can_view_reports(): bool {
		return current_user_can( Capabilities::VIEW_REPORTS );
	}

	/** Return lightweight dashboard totals. */
	public function overview(): WP_REST_Response|WP_Error {
		return $this->respond(
			fn (): WP_REST_Response => new WP_REST_Response(
				$this->presenter->overview( $this->store->overview() )
			)
		);
	}

	/**
	 * Return one bounded usage-history page.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function usages( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond(
			fn (): WP_REST_Response => new WP_REST_Response(
				$this->presenter->usages(
					$this->store->usages(
						(int) $request->get_param( 'page' ),
						(int) $request->get_param( 'per_page' ),
						$this->optional_id( $request, 'campaign_id' ),
						$this->optional_id( $request, 'order_id' ),
						$this->optional_string( $request, 'status' )
					)
				)
			)
		);
	}

	/**
	 * Return one bounded decision-history page.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function decisions( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond(
			fn (): WP_REST_Response => new WP_REST_Response(
				$this->presenter->decisions(
					$this->store->decisions(
						(int) $request->get_param( 'page' ),
						(int) $request->get_param( 'per_page' ),
						$this->optional_id( $request, 'campaign_id' ),
						$this->optional_id( $request, 'order_id' ),
						$this->optional_string( $request, 'reason' )
					)
				)
			)
		);
	}

	/**
	 * Return one optional integer without coercing an omitted value to zero.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @param string          $key     Argument key.
	 */
	private function optional_id( WP_REST_Request $request, string $key ): ?int {
		$value = $request->get_param( $key );

		return null === $value ? null : (int) $value;
	}

	/**
	 * Return one optional non-empty string.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @param string          $key     Argument key.
	 */
	private function optional_string( WP_REST_Request $request, string $key ): ?string {
		$value = $request->get_param( $key );

		return is_string( $value ) && '' !== $value ? $value : null;
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
