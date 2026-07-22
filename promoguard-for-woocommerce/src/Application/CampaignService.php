<?php
/**
 * Campaign administration application service.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Application;

use DateTimeImmutable;
use DomainException;
use PromoGuard\Campaign\Campaign;
use PromoGuard\Campaign\CampaignBuilder;
use PromoGuard\Campaign\CampaignStore;
use PromoGuard\Promotion\CampaignPromotion;
use PromoGuard\Promotion\CampaignPromotionStore;
use PromoGuard\Promotion\CouponDraft;
use PromoGuard\Promotion\Promotion;
use PromoGuard\Promotion\PromotionSource;

/** Coordinates campaign use cases without transport or database details. */
final class CampaignService {
	/**
	 * Validated campaign input builder.
	 *
	 * @var CampaignBuilder
	 */
	private CampaignBuilder $builder;
	/**
	 * Configure campaign administration dependencies.
	 *
	 * @param CampaignStore          $campaigns  Campaign persistence.
	 * @param CampaignPromotionStore $assignments Assignment persistence.
	 * @param PromotionSource        $promotions Native promotion source.
	 * @param CampaignBuilder|null   $builder    Optional input builder.
	 */
	public function __construct(
		private CampaignStore $campaigns,
		private CampaignPromotionStore $assignments,
		private PromotionSource $promotions,
		?CampaignBuilder $builder = null
	) {
		$this->builder = $builder ?? new CampaignBuilder();
	}

	/**
	 * Return a bounded campaign page.
	 *
	 * @param int         $page     One-based page number.
	 * @param int         $per_page Requested page size.
	 * @param string|null $status   Optional stored status.
	 * @return array{items: Campaign[], total: int, page: int, per_page: int}
	 */
	public function campaigns( int $page, int $per_page, ?string $status = null ): array {
		return $this->campaigns->page( $page, $per_page, $status );
	}

	/**
	 * Find one campaign.
	 *
	 * @param int $campaign_id Campaign primary key.
	 */
	public function campaign( int $campaign_id ): ?Campaign {
		return $this->campaigns->find( $campaign_id );
	}

	/**
	 * Create and return one persisted campaign.
	 *
	 * @param array<string,mixed> $input      Campaign input.
	 * @param string              $uuid       Generated UUID.
	 * @param int|null            $created_by Creator user ID.
	 * @param DateTimeImmutable   $now_gmt    Current GMT time.
	 */
	public function create_campaign(
		array $input,
		string $uuid,
		?int $created_by,
		DateTimeImmutable $now_gmt
	): Campaign {
		$campaign = $this->builder->create( $input, $uuid, $created_by, $now_gmt );
		$id       = $this->campaigns->create( $campaign );

		return $this->with_id( $campaign, $id );
	}

	/**
	 * Apply a partial update using one campaign lookup.
	 *
	 * @param int                 $campaign_id Campaign primary key.
	 * @param array<string,mixed> $input       Changed fields.
	 * @param DateTimeImmutable   $now_gmt     Current GMT time.
	 */
	public function update_campaign(
		int $campaign_id,
		array $input,
		DateTimeImmutable $now_gmt
	): ?Campaign {
		$current = $this->campaigns->find( $campaign_id );

		if ( null === $current ) {
			return null;
		}

		$updated = $this->builder->update( $current, $input, $now_gmt );

		return $this->campaigns->update( $updated, $current ) ? $updated : null;
	}

	/**
	 * Archive one campaign while preserving history.
	 *
	 * @param int $campaign_id Campaign primary key.
	 */
	public function archive_campaign( int $campaign_id ): bool {
		return $this->campaigns->archive( $campaign_id );
	}

	/**
	 * Delete only an unused Draft campaign.
	 *
	 * @param int $campaign_id Campaign primary key.
	 */
	public function delete_unused_draft( int $campaign_id ): bool {
		return $this->campaigns->delete_unused_draft( $campaign_id );
	}

	/**
	 * Search the configured promotion source with its bounded implementation.
	 *
	 * @param string $term  Search term.
	 * @param int    $limit Requested result cap.
	 * @return Promotion[]
	 */
	public function search_promotions( string $term, int $limit ): array {
		return $this->promotions->search( $term, $limit );
	}

	/**
	 * Create a native promotion through the configured source.
	 *
	 * @param CouponDraft $draft Validated native coupon input.
	 */
	public function create_promotion( CouponDraft $draft ): Promotion {
		return $this->promotions->create( $draft );
	}

	/**
	 * Resolve and attach or explicitly reassign one source promotion.
	 *
	 * @param int                 $campaign_id       Destination campaign ID.
	 * @param string              $external_id       Source promotion ID.
	 * @param bool                $allow_reassignment Whether ownership may change.
	 * @param string|null         $channel           Optional channel.
	 * @param string|null         $label             Optional label.
	 * @param int                 $sort_order        Administrative order.
	 * @param array<string,mixed> $settings          Assignment settings.
	 * @throws DomainException When the source promotion does not exist.
	 */
	public function assign_promotion(
		int $campaign_id,
		string $external_id,
		bool $allow_reassignment = false,
		?string $channel = null,
		?string $label = null,
		int $sort_order = 0,
		array $settings = array()
	): CampaignPromotion {
		$promotion = $this->promotions->resolve( $external_id );

		if ( null === $promotion ) {
			throw new DomainException( 'Promotion does not exist.' );
		}

		return $this->assignments->assign(
			$campaign_id,
			$promotion,
			$allow_reassignment,
			$channel,
			$label,
			$sort_order,
			$settings
		);
	}

	/**
	 * Return a bounded assignment list.
	 *
	 * @param int $campaign_id Campaign primary key.
	 * @param int $limit       Requested result cap.
	 * @return CampaignPromotion[]
	 */
	public function campaign_promotions( int $campaign_id, int $limit ): array {
		return $this->assignments->for_campaign( $campaign_id, $limit );
	}

	/**
	 * Detach one assignment without touching its source promotion.
	 *
	 * @param int $assignment_id Assignment primary key.
	 * @param int $campaign_id   Owning campaign ID.
	 */
	public function detach_promotion( int $assignment_id, int $campaign_id ): bool {
		return $this->assignments->detach( $assignment_id, $campaign_id );
	}

	/**
	 * Return a persisted copy without another database read.
	 *
	 * @param Campaign $campaign Newly created campaign.
	 * @param int      $id       Generated primary key.
	 */
	private function with_id( Campaign $campaign, int $id ): Campaign {
		return new Campaign(
			id: $id,
			uuid: $campaign->uuid,
			name: $campaign->name,
			slug: $campaign->slug,
			description: $campaign->description,
			goal: $campaign->goal,
			status: $campaign->status,
			priority: $campaign->priority,
			starts_at_gmt: $campaign->starts_at_gmt,
			ends_at_gmt: $campaign->ends_at_gmt,
			configuration: $campaign->configuration,
			created_by: $campaign->created_by,
			created_at_gmt: $campaign->created_at_gmt,
			updated_at_gmt: $campaign->updated_at_gmt
		);
	}
}
