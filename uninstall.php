<?php
/**
 * Clean Uninstaller — Cleans options, transients, metadata, custom queue table, and backup files.
 *
 * @package OptiForge_Image_Optimizer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

( function () {
	// 1. Delete all plugin settings options.
	$ofio_options = array(
		'ofio_auto_optimize',
		'ofio_compression_quality',
		'ofio_webp_conversion',
		'ofio_avif_conversion',
		'ofio_backup_enabled',
		'ofio_preserve_exif',
		'ofio_compression_mode',
		'ofio_batch_size_mode',
		'ofio_batch_size',
		'ofio_serve_webp',
		'ofio_resize_enabled',
		'ofio_max_width',
		'ofio_max_height',
		'ofio_stats',
		'ofio_queue_state',
		'ofio_queue_db_version',
		'ofio_dashboard_cache',
	);

	foreach ( $ofio_options as $ofio_opt ) {
		delete_option( $ofio_opt );
	}

	// 2. Clear transients and caches.
	delete_transient( 'ofio_stats_cache' );
	delete_transient( 'ofio_total_images_count' );
	delete_transient( 'ofio_unoptimized_count' );
	delete_transient( 'ofio_unoptimized_ids' );
	delete_transient( 'ofio_queue_lock' );
	delete_transient( 'ofio_queue_token' );
	delete_transient( 'ofio_queue_recent_log' );

	// 3. Clear scheduled cron hooks.
	$ofio_timestamp = wp_next_scheduled( 'ofio_scheduled_optimization' );
	if ( $ofio_timestamp ) {
		wp_unschedule_event( $ofio_timestamp, 'ofio_scheduled_optimization' );
	}

	$watchdog_timestamp = wp_next_scheduled( 'ofio_queue_watchdog' );
	if ( $watchdog_timestamp ) {
		wp_unschedule_event( $watchdog_timestamp, 'ofio_queue_watchdog' );
	}

	// 4. Clean post metadata created by plugin across all attachments.
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s )",
			'_ofio_meta',
			'_ofio_backup_path'
		)
	);

	// 5. Drop custom queue database table.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ofio_queue" );

	// 6. Recursively delete backup folder using WP_Filesystem API only (zero unlink calls).
	$ofio_upload_dir = wp_upload_dir();
	$ofio_backup_dir = trailingslashit( $ofio_upload_dir['basedir'] ) . 'ofio-backups';

	if ( is_dir( $ofio_backup_dir ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		\WP_Filesystem();
		global $wp_filesystem;

		if ( ! empty( $wp_filesystem ) ) {
			$wp_filesystem->delete( $ofio_backup_dir, true );
		}
	}
} )();