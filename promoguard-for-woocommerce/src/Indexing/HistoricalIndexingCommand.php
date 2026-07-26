<?php
/**
 * WP-CLI historical indexing controls.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Indexing;

use ReflectionMethod;
use Throwable;

/** Exposes safe indexing lifecycle controls to trusted WP-CLI operators. */
final class HistoricalIndexingCommand {
	/**
	 * Configure WP-CLI indexing controls.
	 *
	 * @param WooCommerceHistoricalIndexing $indexing Historical indexing orchestrator.
	 */
	public function __construct( private readonly WooCommerceHistoricalIndexing $indexing ) {}

	/**
	 * Register the `wp promoguard index` command.
	 *
	 * @param WooCommerceHistoricalIndexing $indexing Historical indexing orchestrator.
	 */
	public static function register( WooCommerceHistoricalIndexing $indexing ): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		( new ReflectionMethod( 'WP_CLI', 'add_command' ) )->invoke( null, 'promoguard index', new self( $indexing ) );
	}

	/**
	 * Start a full or targeted indexing job.
	 *
	 * ## OPTIONS
	 *
	 * [--orders=<ids>]
	 * : Comma-separated order IDs. Omit for a full scan.
	 *
	 * [--batch-size=<count>]
	 * : Orders per action. Defaults to 50 and cannot exceed 100.
	 *
	 * @param string[]            $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Named arguments.
	 */
	public function start( array $args, array $assoc_args ): void {
		$this->execute(
			function () use ( $assoc_args ): IndexingJob {
				$order_ids  = $this->order_ids( $assoc_args['orders'] ?? '' );
				$batch_size = isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : IndexingJob::DEFAULT_BATCH_SIZE;
				return $this->indexing->start( $order_ids, $batch_size );
			},
			'Historical indexing started.'
		);
	}

	/**
	 * Show persisted job progress and bounded error history.
	 *
	 * @param string[]            $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Named arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		$job = $this->indexing->current();
		if ( null === $job ) {
			$this->output( 'warning', 'No historical indexing job exists.' );
			return;
		}

		$this->output( 'log', (string) wp_json_encode( ( new IndexingJobCodec() )->encode( $job ), JSON_PRETTY_PRINT ) );
	}

	/**
	 * Pause the current active job.
	 *
	 * @param string[]            $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Named arguments.
	 */
	public function pause( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		$this->execute( fn(): IndexingJob => $this->indexing->pause(), 'Historical indexing paused.' );
	}

	/**
	 * Resume the current paused job.
	 *
	 * @param string[]            $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Named arguments.
	 */
	public function resume( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		$this->execute( fn(): IndexingJob => $this->indexing->resume(), 'Historical indexing resumed.' );
	}

	/**
	 * Retry a failed worker or completed per-order errors.
	 *
	 * @param string[]            $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Named arguments.
	 */
	public function retry( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		$this->execute( fn(): IndexingJob => $this->indexing->retry(), 'Historical indexing retry queued.' );
	}

	/**
	 * Restart the current terminal or paused job.
	 *
	 * @param string[]            $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Named arguments.
	 */
	public function restart( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		$this->execute( fn(): IndexingJob => $this->indexing->restart(), 'Historical indexing restarted.' );
	}

	/**
	 * Execute one command transition and report a safe outcome.
	 *
	 * @param callable():IndexingJob $operation Transition callback.
	 * @param string                 $success   Success message.
	 */
	private function execute( callable $operation, string $success ): void {
		try {
			$job = $operation();
			$this->output( 'success', $success . ' Job: ' . $job->id );
		} catch ( Throwable $exception ) {
			do_action( 'promoguard_cli_error', $exception );
			$this->output( 'error', 'PromoGuard could not complete the indexing command.' );
		}
	}

	/**
	 * Parse and normalize a bounded comma-separated target set.
	 *
	 * @param mixed $value CLI option value.
	 * @return int[]
	 */
	private function order_ids( mixed $value ): array {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}

		$ids = array_map(
			'intval',
			array_filter(
				array_map( 'trim', explode( ',', $value ) ),
				static fn( string $id ): bool => '' !== $id
			)
		);
		$ids = array_values( array_unique( $ids ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	/**
	 * Call one WP-CLI output method without a hard package dependency.
	 *
	 * @param string $method  WP-CLI output method.
	 * @param string $message Safe output text.
	 */
	private function output( string $method, string $message ): void {
		( new ReflectionMethod( 'WP_CLI', $method ) )->invoke( null, $message );
	}
}
