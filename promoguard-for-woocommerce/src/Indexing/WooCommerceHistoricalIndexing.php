<?php
/**
 * Action Scheduler historical indexing orchestration.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Indexing;

use ActionScheduler;
use DateTimeImmutable;
use DateTimeZone;
use PromoGuard\Customer\IdentityResolver;
use PromoGuard\Support\TableNames;
use RuntimeException;
use Throwable;

/** Starts and chains bounded historical import actions. */
final class WooCommerceHistoricalIndexing {
	private const ACTION = 'promoguard_index_historical_orders';
	private const GROUP  = 'promoguard';

	/**
	 * Configure historical job orchestration.
	 *
	 * @param IndexingService   $jobs      Persisted job lifecycle.
	 * @param IndexingProcessor $processor Bounded historical processor.
	 */
	public function __construct(
		private readonly IndexingService $jobs,
		private readonly IndexingProcessor $processor
	) {}

	/** Build the production historical indexer for the active site. */
	public static function from_wordpress(): self {
		$tables     = TableNames::from_wordpress();
		$repository = new HistoricalImportRepository( $tables );

		return new self(
			new IndexingService( new WordPressIndexingJobStore() ),
			new HistoricalIndexingProcessor(
				new WooCommerceHistoricalOrderSource(),
				$repository,
				IdentityResolver::from_wordpress()
			)
		);
	}

	/** Register the bounded Action Scheduler worker. */
	public function register(): void {
		add_action( self::ACTION, array( $this, 'run' ), 10, 1 );
	}

	/**
	 * Start a full scan or targeted rebuild.
	 *
	 * @param int[] $order_ids Explicit order IDs, or empty for all orders.
	 * @param int   $batch_size Maximum orders per action.
	 */
	public function start( array $order_ids = array(), int $batch_size = IndexingJob::DEFAULT_BATCH_SIZE ): IndexingJob {
		$job = $this->jobs->start( wp_generate_uuid4(), $this->now(), $order_ids, $batch_size );
		$this->enqueue( $job );
		return $job;
	}

	/** Return the current or most recently completed job. */
	public function current(): ?IndexingJob {
		return $this->jobs->current();
	}

	/** Pause the current active job. */
	public function pause(): IndexingJob {
		return $this->jobs->pause( $this->now() );
	}

	/** Resume the current paused job and schedule its next page. */
	public function resume(): IndexingJob {
		$job = $this->jobs->resume( $this->now() );
		$this->enqueue( $job );
		return $job;
	}

	/** Retry a worker failure or only the order-level errors. */
	public function retry(): IndexingJob {
		$job = $this->jobs->retry( $this->now() );
		$this->enqueue( $job );
		return $job;
	}

	/** Restart a terminal or paused job from its first page. */
	public function restart(): IndexingJob {
		$job = $this->jobs->restart( $this->now() );
		$this->enqueue( $job );
		return $job;
	}

	/**
	 * Process one scheduled page and chain continuation.
	 *
	 * @param string $job_id Scheduled job UUID.
	 * @throws RuntimeException When processing fails.
	 */
	public function run( string $job_id ): void {
		$job = $this->jobs->claim( $job_id, $this->now() );
		if ( null === $job ) {
			return;
		}

		try {
			$next = $this->jobs->record_batch( $job_id, $this->processor->process( $job ), $this->now() );
			if ( IndexingStatus::QUEUED === $next->status ) {
				$this->enqueue( $next );
			}
		} catch ( Throwable $exception ) {
			$this->jobs->fail( $job_id, 'Historical indexing worker failed.', $this->now() );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception is retained for Action Scheduler diagnostics and is never rendered.
			throw new RuntimeException( 'Historical indexing worker failed.', 0, $exception );
		}
	}

	/**
	 * Enqueue one unique continuation action.
	 *
	 * @param IndexingJob $job Queued job.
	 * @throws RuntimeException When Action Scheduler is unavailable.
	 */
	private function enqueue( IndexingJob $job ): void {
		if (
			! class_exists( ActionScheduler::class )
			|| ! ActionScheduler::is_initialized()
			|| ! function_exists( 'as_enqueue_async_action' )
		) {
			throw new RuntimeException( 'Action Scheduler is unavailable for historical indexing.' );
		}

		as_enqueue_async_action( self::ACTION, array( $job->id ), self::GROUP, true );
	}

	/** Return the current GMT transition time. */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable( current_time( 'mysql', true ), new DateTimeZone( 'UTC' ) );
	}
}
