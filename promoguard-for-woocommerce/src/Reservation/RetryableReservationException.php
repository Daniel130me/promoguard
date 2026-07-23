<?php
/**
 * Retryable reservation failure.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reservation;

use RuntimeException;

/** Signals a transactional deadlock or lock timeout that may succeed on retry. */
final class RetryableReservationException extends RuntimeException {}
