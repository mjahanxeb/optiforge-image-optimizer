<?php
/**
 * Background Scheduled Optimization Manager.
 *
 * @package OptiForge_Image_Optimizer
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound
namespace Optiforge_Image_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Cron
 */
class Cron {

	/**
	 * Shared optimizer instance.
	 *
	 * @var Optimizer
	 */
	private Optimizer $optimizer;

	/**
	 * Shared stats instance.
	 *
	 * @var Stats
	 */
	private Stats $stats;

	/**
	 * Hourly cron batch size.
	 */
	const CRON_BATCH = 10;

	/**
	 * Cron constructor.
	 *
	 * @param Optimizer $optimizer Shared optimizer.
	 * @param Stats     $stats     Shared stats.
	 */
	public function __construct( Optimizer $optimizer, Stats $stats ) {
		$this->optimizer = $optimizer;
		$this->stats     = $stats;

		add_action( 'ofio_scheduled_optimization', array( $this, 'run_scheduled_optimization' ) );
	}

	/**
	 * Run scheduled hourly optimization.
	 *
	 * @return void
	 */
	public function run_scheduled_optimization(): void {
		if ( ! get_option( 'ofio_auto_optimize', 1 ) ) {
			return;
		}

		if ( ! $this->server_healthy() ) {
			return;
		}

		$ids = $this->stats->get_unoptimized_ids( self::CRON_BATCH );
		if ( empty( $ids ) ) {
			return;
		}

		foreach ( $ids as $attachment_id ) {
			$result = $this->optimizer->optimize_attachment( (int) $attachment_id );
			$this->stats->record( (int) $attachment_id, $result );

			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		delete_transient( 'ofio_unoptimized_ids' );
		delete_transient( 'ofio_stats_cache' );
	}

	/**
	 * Check if server resources are healthy enough for background tasks.
	 *
	 * @return bool True if healthy.
	 */
	public function server_healthy(): bool {
		$memory_limit = $this->parse_memory_limit( (string) ini_get( 'memory_limit' ) );
		$memory_used  = memory_get_usage( true );

		if ( $memory_limit > 0 && ( $memory_limit - $memory_used ) < 33554432 ) {
			return false;
		}

		$max_exec = (int) ini_get( 'max_execution_time' );
		if ( $max_exec > 0 && $max_exec < 20 ) {
			return false;
		}

		return true;
	}

	/**
	 * Parse memory limit string to bytes.
	 *
	 * @param string $val Value.
	 * @return int Bytes or -1.
	 */
	public function parse_memory_limit( string $val ): int {
		$val = trim( $val );
		if ( '' === $val || '-1' === $val ) {
			return -1;
		}
		$unit  = strtolower( substr( $val, -1 ) );
		$bytes = (int) $val;
		switch ( $unit ) {
			case 'g':
				$bytes *= 1073741824;
				break;
			case 'm':
				$bytes *= 1048576;
				break;
			case 'k':
				$bytes *= 1024;
				break;
		}
		return $bytes;
	}
}

// Backwards-compatible class aliases.
if ( ! class_exists( 'OFIO_Cron', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Cron', 'OFIO_Cron' );
}
if ( ! class_exists( '\OptiForge\ImageOptimizer\Cron', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Cron', '\OptiForge\ImageOptimizer\Cron' );
}
if ( ! class_exists( '\OptiForge_Image_Optimizer\Cron', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Cron', '\OptiForge_Image_Optimizer\Cron' );
}