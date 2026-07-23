<?php
/**
 * Reservation customer-limit failure.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reservation;

use RuntimeException;

/** Signals that locked consumed and reserved usage already meets the limit. */
final class ReservationLimitException extends RuntimeException {}
