<?php
/**
 * Analytics REST controller.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Api;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PromoGuard\Analytics\AnalyticsFilter;
use PromoGuard\Analytics\AnalyticsRepository;
use PromoGuard\Analytics\AnalyticsService;
use PromoGuard\Analytics\AnalyticsStore;
use PromoGuard\Analytics\CachedAnalyticsStore;
use PromoGuard\Analytics\OrderRevenueCalculator;
use PromoGuard\Analytics\WooCommerceOrderProvider;
use PromoGuard\Support\Capabilities;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/** Exposes capability-protected, bounded analytics summaries. */
final class AnalyticsController {
	private const DEFAULT_DAYS   = 30;
	private const REST_NAMESPACE = 'promoguard/v1';

	/**
	 * Configure the analytics read model.
	 *
	 * @param AnalyticsStore $store Cached analytics store.
	 */
	public function __construct( private readonly AnalyticsStore $store ) {}

	/** Build the controller for the active WordPress site. */
	public static function from_wordpress(): self {
		$repository = AnalyticsRepository::from_wordpress();

		return new self(
			new CachedAnalyticsStore(
				new AnalyticsService(
					$repository,
					new OrderRevenueCalculator( $repository, new WooCommerceOrderProvider() )
				)
			)
		);
	}

	/** Register the summary endpoint. */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/analytics/summary',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'summary' ),
				'permission_callback' => array( $this, 'can_view_reports' ),
				'args'                => AnalyticsRouteSchema::summary(),
			)
		);
	}

	/** Require the explicit reporting capability. */
	public function can_view_reports(): bool {
		return current_user_can( Capabilities::VIEW_REPORTS );
	}

	/**
	 * Return one cached analytics summary.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function summary( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$filter  = $this->filter( $request );
			$summary = $this->store->summary( $filter );

			return new WP_REST_Response(
				array_merge(
					array(
						'period' => array(
							'starts_at_gmt' => $filter->starts_at_gmt->format( DATE_ATOM ),
							'ends_at_gmt'   => $filter->ends_at_gmt->format( DATE_ATOM ),
							'campaign_id'   => $filter->campaign_id,
						),
					),
					$summary
				)
			);
		} catch ( DomainException $exception ) {
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

	/**
	 * Build a bounded filter, defaulting to the latest 30 complete UTC days.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @throws DomainException When a supplied date cannot be parsed.
	 */
	private function filter( WP_REST_Request $request ): AnalyticsFilter {
		$gmt      = new DateTimeZone( 'UTC' );
		$end      = $this->date( $request->get_param( 'ends_at_gmt' ) )
			?? new DateTimeImmutable( 'now', $gmt );
		$start    = $this->date( $request->get_param( 'starts_at_gmt' ) )
			?? $end->modify( '-' . self::DEFAULT_DAYS . ' days' );
		$campaign = $request->get_param( 'campaign_id' );

		return new AnalyticsFilter(
			$start,
			$end,
			null === $campaign ? null : (int) $campaign
		);
	}

	/**
	 * Parse one optional ISO date.
	 *
	 * @param mixed $value Request value.
	 * @throws DomainException When the date cannot be parsed.
	 */
	private function date( mixed $value ): ?DateTimeImmutable {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( ! is_string( $value ) ) {
			throw new DomainException( 'Analytics dates must be ISO 8601 strings.' );
		}

		try {
			return new DateTimeImmutable( $value );
		} catch ( \Exception $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The domain exception is mapped to a safe REST error.
			throw new DomainException( 'Analytics dates must be valid ISO 8601 values.', 0, $exception );
		}
	}
}
