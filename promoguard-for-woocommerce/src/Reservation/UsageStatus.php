<?php
/**
 * Usage lifecycle statuses.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reservation;

/** Centralizes persisted usage status values shared by lifecycle operations. */
final class UsageStatus {
	public const PENDING  = 'pending';
	public const CONSUMED = 'consumed';
	public const RELEASED = 'released';
	public const RESTORED = 'restored';

	/** This class exposes constants only. */
	private function __construct() {}
}
