<?php
/**
 * Background Scheduled Optimization Manager.
 *
 * @package OptiForge_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OFIO_Cron {

	private OFIO_Optimizer $optimizer;
	private OFIO_Stats $stats;

	const CRON_BATCH = 10;

	public function __construct( OFIO_Optimizer $optimizer, OFIO_Stats $stats ) {
		$this->optimizer = $optimizer;
		$this->stats     = $stats;

		add_action( 'ofio_scheduled_optimization', array( $this, 'run_scheduled_optimization' ) );
	}

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
		}

		delete_transient( 'ofio_unoptimized_ids' );
		delete_transient( 'ofio_stats_cache' );
	}

	public function server_healthy(): bool {
		$memory_limit = $this->parse_memory_limit( (string) ini_get( 'memory_limit' ) );
		$memory_used  = memory_get_usage( true );

		if ( $memory_limit > 0 && ( $memory_limit - $memory_used ) < 33554432 ) {
			return false;
		}

		$max_exec = (int) ini_get( 'max_execution_time' );
		if ( $max_exec > 0 && $max_exec < 30 ) {
			return false;
		}

		return true;
	}

	public function parse_memory_limit( string $val ): int {
		$val = trim( $val );
		if ( '' === $val || '-1' === $val ) {
			return -1;
		}
		$unit  = strtolower( $val[ strlen( $val ) - 1 ] );
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