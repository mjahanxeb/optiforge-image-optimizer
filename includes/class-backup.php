<?php
/**
 * Backup Engine.
 *
 * Manages physical backup files in the `ofio-backups/` directory, restore
 * operations, orphan file cleanup, and attachment-deletion hooks. Includes
 * full protection against infinite restore loops and stale postmeta lockouts.
 *
 * @package OptiForge_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OFIO_Backup
 */
class OFIO_Backup {

	/**
	 * Absolute path to the backup directory.
	 *
	 * @var string
	 */
	private string $backup_dir;

	/**
	 * Flag set to true while a restore is in progress to prevent auto-optimize
	 * from firing on the regenerated metadata (which would overwrite the
	 * restored original again).
	 *
	 * @var bool
	 */
	public static bool $is_restoring = false;

	// -------------------------------------------------------------------------
	// Constructor
	// -------------------------------------------------------------------------

	/**
	 * OFIO_Backup constructor.
	 */
	public function __construct() {
		$upload_dir       = wp_upload_dir();
		$this->backup_dir = trailingslashit( $upload_dir['basedir'] ) . 'ofio-backups';
		$this->maybe_create_dir();
	}

	// -------------------------------------------------------------------------
	// Backup Directory Bootstrapping
	// -------------------------------------------------------------------------

	/**
	 * Ensure the backup directory exists and is protected from direct web access.
	 *
	 * @return void
	 */
	private function maybe_create_dir(): void {
		if ( ! file_exists( $this->backup_dir ) ) {
			wp_mkdir_p( $this->backup_dir );
		}

		$htaccess = $this->backup_dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$content  = "# Apache 2.4+\n<IfModule authz_core_module>\n    Require all denied\n</IfModule>\n";
			$content .= "# Apache 2.2\n<IfModule !authz_core_module>\n    Deny from all\n</IfModule>\n";
			file_put_contents( $htaccess, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		if ( ! file_exists( $this->backup_dir . '/index.php' ) ) {
			file_put_contents( $this->backup_dir . '/index.php', '<?php // Silence is golden.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	// -------------------------------------------------------------------------
	// Backup Creation
	// -------------------------------------------------------------------------

	/**
	 * Create a backup copy of an attachment's main file.
	 *
	 * Skips silently if a backup already exists (idempotent). Records the
	 * backup path in `_ofio_backup_path` postmeta on success.
	 *
	 * @param string $file_path     Absolute path to source file.
	 * @param int    $attachment_id Attachment post ID.
	 * @return bool True if backup exists or was created successfully.
	 */
	public function create_backup( string $file_path, int $attachment_id ): bool {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		$backup_path = $this->get_backup_path( $attachment_id, $file_path );

		// Already backed up — do not overwrite an existing original.
		if ( file_exists( $backup_path ) ) {
			return true;
		}

		$ok = copy( $file_path, $backup_path );
		if ( $ok ) {
			update_post_meta( $attachment_id, '_ofio_backup_path', $backup_path );
		}
		return $ok;
	}

	// -------------------------------------------------------------------------
	// Restore
	// -------------------------------------------------------------------------

	/**
	 * Restore an attachment from its backup file.
	 *
	 * FIX (CRITICAL INFINITE LOOP): Deletes `_ofio_backup_path` postmeta
	 * on every code-path (success AND missing-file stale-meta) so that
	 * get_restorable_ids() never returns the same ID twice.
	 *
	 * FIX (MISSING FILE / STALE META LOCKOUT): When the physical backup is
	 * absent but postmeta exists, clean up the stale meta, log, and return a
	 * graceful failure so the queue moves on.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array{success:bool, message:string}
	 */
	public function restore_image( int $attachment_id ): array {
		$backup_path = get_post_meta( $attachment_id, '_ofio_backup_path', true );

		if ( ! $backup_path ) {
			return array(
				'success' => false,
				'message' => __( 'No backup record found.', 'optiforge-image-optimizer' ),
			);
		}

		// FIX: Physical file missing but postmeta remains → stale lockout.
		if ( ! file_exists( (string) $backup_path ) ) {
			// Clean up the stale postmeta so the queue does not freeze.
			delete_post_meta( $attachment_id, '_ofio_backup_path' );
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'[OFIO] Backup file missing for attachment %d — path: %s. Postmeta cleaned.',
					$attachment_id,
					$backup_path
				)
			);
			return array(
				'success' => false,
				'message' => __( 'Backup file not found on disk. Record cleaned.', 'optiforge-image-optimizer' ),
			);
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file ) {
			delete_post_meta( $attachment_id, '_ofio_backup_path' );
			return array(
				'success' => false,
				'message' => __( 'Attached file not found in Media Library.', 'optiforge-image-optimizer' ),
			);
		}

		// Replace optimized main image with original.
		if ( ! copy( (string) $backup_path, $file ) ) {
			return array(
				'success' => false,
				'message' => __( 'Could not overwrite optimized file with backup.', 'optiforge-image-optimizer' ),
			);
		}

		// Delete physical backup file after successful restoration.
		wp_delete_file( (string) $backup_path );

		// Clean up the postmeta unconditionally so get_restorable_ids() drops this ID.
		delete_post_meta( $attachment_id, '_ofio_backup_path' );

		// Clean up generated sidecar files.
		$this->delete_sidecar_files( $attachment_id );

		// Remove optimization metadata from attachment.
		delete_post_meta( $attachment_id, '_ofio_meta' );

		// Regenerate thumbnails from restored original without re-optimizing.
		self::$is_restoring = true;
		try {
			if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}
			$metadata = wp_generate_attachment_metadata( $attachment_id, $file );
			wp_update_attachment_metadata( $attachment_id, $metadata );
		} finally {
			self::$is_restoring = false;
		}

		// Update global statistics and bust cache.
		$stats = new OFIO_Stats();
		$stats->record_restore( $attachment_id );

		if ( class_exists( 'OFIO_Cache' ) ) {
			OFIO_Cache::purge_all();
		}

		return array(
			'success' => true,
			'message' => __( 'Restored to original successfully.', 'optiforge-image-optimizer' ),
		);
	}

	// -------------------------------------------------------------------------
	// Attachment Deletion Hook
	// -------------------------------------------------------------------------

	/**
	 * Clean up backup file and sidecars when an attachment is permanently deleted.
	 *
	 * Hooked to `delete_attachment`.
	 *
	 * @param int $attachment_id Attachment post ID being deleted.
	 * @return void
	 */
	public function on_attachment_deleted( int $attachment_id ): void {
		$backup_path = get_post_meta( $attachment_id, '_ofio_backup_path', true );
		if ( $backup_path && file_exists( (string) $backup_path ) ) {
			wp_delete_file( (string) $backup_path );
		}

		$this->delete_sidecar_files( $attachment_id );

		// Clean up restorable-IDs cache.
		wp_cache_delete( 'ofio_count_restorable', 'ofio' );
	}

	// -------------------------------------------------------------------------
	// Sidecar Cleanup
	// -------------------------------------------------------------------------

	/**
	 * Delete all .webp and .avif sidecar files associated with an attachment.
	 *
	 * Cleans sidecars for both the main original file and all generated thumbnail sizes.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return void
	 */
	public function delete_sidecar_files( int $attachment_id ): void {
		$file = get_attached_file( $attachment_id );
		if ( ! $file ) {
			return;
		}

		// Delete main file sidecars.
		$this->unlink_sidecars_for_path( $file );

		// Delete thumbnail sidecars.
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return;
		}

		$base_dir = trailingslashit( wp_upload_dir()['basedir'] );
		$sub_dir  = ! empty( $metadata['file'] )
			? trailingslashit( $base_dir . dirname( $metadata['file'] ) )
			: trailingslashit( dirname( $file ) );

		foreach ( $metadata['sizes'] as $size_data ) {
			if ( empty( $size_data['file'] ) ) {
				continue;
			}
			$thumb_path = $sub_dir . $size_data['file'];
			$this->unlink_sidecars_for_path( $thumb_path );
		}
	}

	/**
	 * Delete .webp and .avif sidecars for a single source file path.
	 * Handles both appended ($path . '.webp') and replaced-extension formats.
	 *
	 * @param string $source_path Absolute path to the source image.
	 * @return void
	 */
	private function unlink_sidecars_for_path( string $source_path ): void {
		$info = pathinfo( $source_path );

		foreach ( array( 'webp', 'avif' ) as $ext ) {
			// Nginx sidecar format ($path . '.webp' / $path . '.avif')
			$sidecar_appended = $source_path . '.' . $ext;
			if ( file_exists( $sidecar_appended ) ) {
				wp_delete_file( $sidecar_appended );
			}

			// Replaced extension format
			$sidecar_replaced = $info['dirname'] . DIRECTORY_SEPARATOR . $info['filename'] . '.' . $ext;
			if ( $sidecar_replaced !== $source_path && file_exists( $sidecar_replaced ) ) {
				wp_delete_file( $sidecar_replaced );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Query Methods
	// -------------------------------------------------------------------------

	/**
	 * Count attachments that have an active backup record.
	 *
	 * @return int
	 */
	public function count_restorable_images(): int {
		global $wpdb;

		$cache_key = 'ofio_count_restorable';
		$cached    = wp_cache_get( $cache_key, 'ofio' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$count = $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
			 WHERE meta_key = '_ofio_backup_path'"
		);

		wp_cache_set( $cache_key, (int) $count, 'ofio', 60 );
		return (int) $count;
	}

	/**
	 * Retrieve attachment IDs that have a backup postmeta record.
	 *
	 * @param int $limit Maximum number of IDs to return (0 = unlimited).
	 * @return int[]
	 */
	public function get_restorable_ids( int $limit = 500 ): array {
		global $wpdb;

		$cache_key = 'ofio_restorable_ids_' . $limit;
		$cached    = wp_cache_get( $cache_key, 'ofio' );
		if ( false !== $cached ) {
			return (array) $cached;
		}

		if ( $limit > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
					 WHERE meta_key = '_ofio_backup_path'
					 ORDER BY post_id DESC
					 LIMIT %d",
					$limit
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$ids = $wpdb->get_col(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = '_ofio_backup_path'
				 ORDER BY post_id DESC"
			);
		}

		$ids = array_map( 'absint', (array) $ids );
		wp_cache_set( $cache_key, $ids, 'ofio', 30 );
		return $ids;
	}

	/**
	 * Check whether a physical backup file exists for an attachment.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool
	 */
	public function has_backup( int $attachment_id ): bool {
		$path = get_post_meta( $attachment_id, '_ofio_backup_path', true );
		return ! empty( $path ) && file_exists( (string) $path );
	}

	// -------------------------------------------------------------------------
	// Directory Utilities
	// -------------------------------------------------------------------------

	/**
	 * Calculate the total size and file count of the backup directory.
	 *
	 * @return array{count:int, size:int, formatted:string}
	 */
	public function get_backup_directory_size(): array {
		$size  = 0;
		$count = 0;

		if ( is_dir( $this->backup_dir ) ) {
			$files = scandir( $this->backup_dir );
			if ( is_array( $files ) ) {
				foreach ( $files as $f ) {
					if ( in_array( $f, array( '.', '..', '.htaccess', 'index.php' ), true ) ) {
						continue;
					}
					$path = $this->backup_dir . '/' . $f;
					if ( is_file( $path ) ) {
						$size += (int) filesize( $path );
						$count++;
					}
				}
			}
		}

		return array(
			'count'     => $count,
			'size'      => $size,
			'formatted' => size_format( $size, 2 ),
		);
	}

	/**
	 * Permanently delete all backup files from disk and remove all postmeta records.
	 *
	 * @return array{success:bool, deleted:int, message:string}
	 */
	public function purge_all_backups(): array {
		$deleted = 0;

		if ( is_dir( $this->backup_dir ) ) {
			$files = scandir( $this->backup_dir );
			if ( is_array( $files ) ) {
				foreach ( $files as $f ) {
					if ( in_array( $f, array( '.', '..', '.htaccess', 'index.php' ), true ) ) {
						continue;
					}
					$path = $this->backup_dir . '/' . $f;
					if ( is_file( $path ) ) {
						wp_delete_file( $path );
						$deleted++;
					}
				}
			}
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_ofio_backup_path'" );

		// Bust restorable-IDs cache after purge.
		wp_cache_delete( 'ofio_count_restorable', 'ofio' );

		return array(
			'success' => true,
			'deleted' => $deleted,
			'message' => sprintf(
				/* translators: %d: number of deleted backup files */
				__( 'Purged %d backup files.', 'optiforge-image-optimizer' ),
				$deleted
			),
		);
	}

	// -------------------------------------------------------------------------
	// Private Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build the expected backup file path for an attachment.
	 *
	 * Format: `ofio-backups/ofio-{ID}.{ext}`
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $source_file   Absolute path to the source file.
	 * @return string
	 */
	private function get_backup_path( int $attachment_id, string $source_file ): string {
		$ext = strtolower( pathinfo( $source_file, PATHINFO_EXTENSION ) );
		return trailingslashit( $this->backup_dir ) . 'ofio-' . $attachment_id . '.' . $ext;
	}
}