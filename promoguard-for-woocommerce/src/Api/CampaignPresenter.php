<?php
/**
 * Campaign REST representation mapper.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Api;

use DateTimeImmutable;
use PromoGuard\Campaign\Campaign;
use PromoGuard\Promotion\CampaignPromotion;
use PromoGuard\Promotion\Promotion;

/** Maps domain records to stable, transport-safe response arrays. */
final class CampaignPresenter {
	private const DATE_FORMAT = 'Y-m-d\TH:i:s\Z';

	/**
	 * Present one campaign with its schedule-derived state.
	 *
	 * @param Campaign          $campaign Campaign record.
	 * @param DateTimeImmutable $now_gmt  Current GMT time.
	 * @return array<string,mixed>
	 */
	public function campaign( Campaign $campaign, DateTimeImmutable $now_gmt ): array {
		return array(
			'id'               => $campaign->id,
			'uuid'             => $campaign->uuid,
			'name'             => $campaign->name,
			'slug'             => $campaign->slug,
			'description'      => $campaign->description,
			'goal'             => $campaign->goal,
			'status'           => $campaign->status,
			'effective_status' => $campaign->effective_status( $now_gmt ),
			'priority'         => $campaign->priority,
			'starts_at_gmt'    => $this->date( $campaign->starts_at_gmt ),
			'ends_at_gmt'      => $this->date( $campaign->ends_at_gmt ),
			'usage_rules'      => $campaign->configuration->usage_rules(),
			'conflict_rules'   => $campaign->configuration->conflict_rules(),
			'settings'         => $campaign->configuration->settings(),
			'created_by'       => $campaign->created_by,
			'created_at_gmt'   => $this->date( $campaign->created_at_gmt ),
			'updated_at_gmt'   => $this->date( $campaign->updated_at_gmt ),
		);
	}

	/**
	 * Present one bounded campaign page.
	 *
	 * @param array{items: Campaign[], total: int, page: int, per_page: int} $page    Repository page.
	 * @param DateTimeImmutable                                              $now_gmt Current GMT time.
	 * @return array{items: array<int,array<string,mixed>>, total: int, page: int, per_page: int}
	 */
	public function campaign_page( array $page, DateTimeImmutable $now_gmt ): array {
		return array(
			'items'    => array_map(
				fn ( Campaign $campaign ): array => $this->campaign( $campaign, $now_gmt ),
				$page['items']
			),
			'total'    => $page['total'],
			'page'     => $page['page'],
			'per_page' => $page['per_page'],
		);
	}

	/**
	 * Present one source-neutral promotion.
	 *
	 * @param Promotion $promotion Source promotion.
	 * @return array<string,mixed>
	 */
	public function promotion( Promotion $promotion ): array {
		return array(
			'source'      => $promotion->source,
			'source_type' => $promotion->source_type,
			'external_id' => $promotion->external_id,
			'code'        => $promotion->code,
			'label'       => $promotion->label,
			'available'   => $promotion->available,
		);
	}

	/**
	 * Present a source promotion list.
	 *
	 * @param Promotion[] $promotions Source promotions.
	 * @return array<int,array<string,mixed>>
	 */
	public function promotions( array $promotions ): array {
		return array_map( array( $this, 'promotion' ), $promotions );
	}

	/**
	 * Present one active assignment snapshot.
	 *
	 * @param CampaignPromotion $assignment Campaign assignment.
	 * @return array<string,mixed>
	 */
	public function assignment( CampaignPromotion $assignment ): array {
		return array(
			'id'             => $assignment->id,
			'uuid'           => $assignment->uuid,
			'campaign_id'    => $assignment->campaign_id,
			'source'         => $assignment->source,
			'source_type'    => $assignment->source_type,
			'external_id'    => $assignment->external_id,
			'external_code'  => $assignment->external_code,
			'channel'        => $assignment->channel,
			'label'          => $assignment->label,
			'sort_order'     => $assignment->sort_order,
			'settings'       => $assignment->settings,
			'created_at_gmt' => $this->date( $assignment->created_at_gmt ),
			'updated_at_gmt' => $this->date( $assignment->updated_at_gmt ),
		);
	}

	/**
	 * Present an assignment list.
	 *
	 * @param CampaignPromotion[] $assignments Campaign assignments.
	 * @return array<int,array<string,mixed>>
	 */
	public function assignments( array $assignments ): array {
		return array_map( array( $this, 'assignment' ), $assignments );
	}

	/**
	 * Format one nullable GMT timestamp.
	 *
	 * @param DateTimeImmutable|null $date Timestamp.
	 */
	private function date( ?DateTimeImmutable $date ): ?string {
		return null === $date ? null : $date->format( self::DATE_FORMAT );
	}
}
