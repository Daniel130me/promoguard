<?php
/**
 * Campaign administration REST controller.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Api;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use PromoGuard\Application\CampaignService;

use PromoGuard\Campaign\CampaignRepository;

use PromoGuard\Promotion\CampaignPromotionRepository;
use PromoGuard\Promotion\CouponDraft;
use PromoGuard\Promotion\WooCommerceCouponSource;
use PromoGuard\Support\Capabilities;
use RuntimeException;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/** Registers capability-protected campaign and native-coupon endpoints. */
final class CampaignController {
	private const REST_NAMESPACE = 'promoguard/v1';

	private const DATE_FORMAT = 'Y-m-d H:i:s';

	/**
	 * Configure API dependencies.
	 *
	 * @param CampaignService   $service   Campaign application service.
	 * @param CampaignPresenter $presenter Response mapper.
	 */
	public function __construct(
		private CampaignService $service,
		private CampaignPresenter $presenter
	) {
	}

	/** Build the controller for the active WordPress site. */
	public static function from_wordpress(): self {
		return new self(
			new CampaignService(
				CampaignRepository::from_wordpress(),
				CampaignPromotionRepository::from_wordpress(),
				new WooCommerceCouponSource()
			),
			new CampaignPresenter()
		);
	}

	/** Register every Phase 2 administration route. */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/campaigns',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_campaigns' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => CampaignRouteSchema::campaign_collection(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_campaign' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => CampaignRouteSchema::campaign_mutation( true ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/campaigns/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_campaign' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => CampaignRouteSchema::identifiers(),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_campaign' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array_merge( CampaignRouteSchema::identifiers(), CampaignRouteSchema::campaign_mutation( false ) ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_campaign' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => CampaignRouteSchema::identifiers(),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/campaigns/(?P<id>[\d]+)/archive',
			array(
				'args'                => CampaignRouteSchema::identifiers(),
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'archive_campaign' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/campaigns/(?P<id>[\d]+)/promotions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_assignments' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array_merge(
						CampaignRouteSchema::identifiers(),
						array( 'per_page' => CampaignRouteSchema::per_page() )
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'assign_promotion' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array_merge( CampaignRouteSchema::identifiers(), CampaignRouteSchema::assignment() ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/campaigns/(?P<id>[\d]+)/promotions/(?P<assignment_id>[\d]+)',
			array(
				'args'                => array_merge(
					CampaignRouteSchema::identifiers(),
					array(
						'assignment_id' => CampaignRouteSchema::positive_integer(),
					)
				),
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'detach_promotion' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/coupons',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'search_coupons' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'search'   => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'per_page' => CampaignRouteSchema::per_page(),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_coupon' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => CampaignRouteSchema::coupon(),
				),
			)
		);
	}

	/** Require the explicit campaign-management capability. */
	public function can_manage(): bool {
		return current_user_can( Capabilities::MANAGE_CAMPAIGNS );
	}

	/**
	 * Return a bounded campaign page.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function list_campaigns( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond(
			function () use ( $request ): WP_REST_Response {
				$status = $request->get_param( 'status' );

				$page = $this->service->campaigns(
					(int) $request->get_param( 'page' ),
					(int) $request->get_param( 'per_page' ),
					is_string( $status ) && '' !== $status ? $status : null
				);

				return new WP_REST_Response(
					$this->presenter->campaign_page( $page, $this->now_gmt() )
				);
			}
		);
	}

	/**
	 * Return one campaign.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function get_campaign( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond(
			function () use ( $request ): WP_REST_Response|WP_Error {
				$campaign = $this->service->campaign( (int) $request['id'] );

				if ( null === $campaign ) {
					return $this->not_found();
				}

				return new WP_REST_Response( $this->presenter->campaign( $campaign, $this->now_gmt() ) );
			}
		);
	}

	/**
	 * Create one campaign.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function create_campaign( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond(
			function () use ( $request ): WP_REST_Response {
				$user_id  = get_current_user_id();
				$campaign = $this->service->create_campaign(
					$this->campaign_input( $request ),
					wp_generate_uuid4(),
					$user_id > 0 ? $user_id : null,
					$this->now_gmt()
				);

				return new WP_REST_Response(
					$this->presenter->campaign( $campaign, $this->now_gmt() ),
					201
				);
			}
		);
	}

	/**
	 * Update one campaign.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function update_campaign( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond(
			function () use ( $request ): WP_REST_Response|WP_Error {
				$campaign = $this->service->update_campaign(
					(int) $request['id'],
					$this->campaign_input( $request ),
					$this->now_gmt()
				);

				if ( null === $campaign ) {
					return $this->not_found();
				}

				return new WP_REST_Response( $this->presenter->campaign( $campaign, $this->now_gmt() ) );
			}
		);
	}

	/**
	 * Archive one campaign without deleting history.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function archive_campaign( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond(
			function () use ( $request ): WP_REST_Response|WP_Error {
				$campaign_id = (int) $request['id'];

				if ( null === $this->service->campaign( $campaign_id ) ) {
					return $this->not_found();
				}

				if ( ! $this->service->archive_campaign( $campaign_id ) ) {
					throw new RuntimeException( 'Campaign could not be archived.' );
				}

				return new WP_REST_Response( array( 'archived' => true ) );
			}
		);
	}

	/**
	 * Permanently delete only an unused Draft campaign.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function delete_campaign( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond(
			function () use ( $request ): WP_REST_Response|WP_Error {
				$campaign_id = (int) $request['id'];

				if ( null === $this->service->campaign( $campaign_id ) ) {
					return $this->not_found();
				}

				if ( ! $this->service->delete_unused_draft( $campaign_id ) ) {
					return new WP_Error(
						'promoguard_campaign_in_use',
						__( 'Only an unused Draft campaign can be permanently deleted. Archive this campaign instead.', 'promoguard-for-woocommerce' ),
						array( 'status' => 409 )
					);
				}

				return new WP_REST_Response( null, 204 );
			}
		);
	}

	/**
	 * Return bounded assignments for one campaign.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function list_assignments( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond(
			function () use ( $request ): WP_REST_Response {
				$assignments = $this->service->campaign_promotions(
					(int) $request['id'],
					(int) $request->get_param( 'per_page' )
				);

				return new WP_REST_Response( $this->presenter->assignments( $assignments ) );
			}
		);
	}

	/**
	 * Attach or explicitly reassign one native coupon.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function assign_promotion( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond(
			function () use ( $request ): WP_REST_Response {
				$settings = $request->get_param( 'settings' );

				$assignment = $this->service->assign_promotion(
					(int) $request['id'],
					(string) $request->get_param( 'external_id' ),
					(bool) $request->get_param( 'allow_reassignment' ),
					$this->nullable_request_string( $request, 'channel' ),
					$this->nullable_request_string( $request, 'label' ),
					(int) $request->get_param( 'sort_order' ),
					is_array( $settings ) ? $settings : array()
				);

				return new WP_REST_Response( $this->presenter->assignment( $assignment ), 201 );
			}
		);
	}

	/**
	 * Detach one assignment while leaving its source coupon untouched.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function detach_promotion( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond(
			function () use ( $request ): WP_REST_Response|WP_Error {
				$detached = $this->service->detach_promotion(
					(int) $request['assignment_id'],
					(int) $request['id']
				);

				if ( ! $detached ) {
					return $this->not_found();
				}

				return new WP_REST_Response( null, 204 );
			}
		);
	}

	/**
	 * Search native coupons through the bounded source adapter.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function search_coupons( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond(
			function () use ( $request ): WP_REST_Response {
				$promotions = $this->service->search_promotions(
					(string) $request->get_param( 'search' ),
					(int) $request->get_param( 'per_page' )
				);

				return new WP_REST_Response( $this->presenter->promotions( $promotions ) );
			}
		);
	}

	/**
	 * Create one native WooCommerce coupon without assigning it implicitly.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function create_coupon( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond(
			function () use ( $request ): WP_REST_Response {
				$promotion = $this->service->create_promotion(
					new CouponDraft(
						(string) $request->get_param( 'code' ),
						(string) $request->get_param( 'discount_type' ),
						(string) $request->get_param( 'amount' ),
						(string) $request->get_param( 'description' )
					)
				);

				return new WP_REST_Response( $this->presenter->promotion( $promotion ), 201 );
			}
		);
	}

	/**
	 * Convert supported campaign request fields to transport-neutral input.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return array<string,mixed>
	 */
	private function campaign_input( WP_REST_Request $request ): array {
		$input = array();

		foreach ( array_keys( CampaignRouteSchema::campaign_mutation( false ) ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$input[ $key ] = $request->get_param( $key );
			}
		}

		return $input;
	}

	/**
	 * Return an optional string argument without coercing null.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @param string          $key     Argument key.
	 */
	private function nullable_request_string( WP_REST_Request $request, string $key ): ?string {
		$value = $request->get_param( $key );

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Execute a route operation and expose only safe errors.
	 *
	 * @param callable(): (WP_REST_Response|WP_Error) $operation Route operation.
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

	/** Return a consistent resource-not-found response. */
	private function not_found(): WP_Error {
		return new WP_Error(
			'promoguard_not_found',
			__( 'The requested PromoGuard resource was not found.', 'promoguard-for-woocommerce' ),
			array( 'status' => 404 )
		);
	}

	/** Return the current immutable GMT timestamp. */
	private function now_gmt(): DateTimeImmutable {
		return new DateTimeImmutable(
			current_time( self::DATE_FORMAT, true ),
			new DateTimeZone( 'UTC' )
		);
	}
}
