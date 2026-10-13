<?php
/**
 * Non-Destructive Backup & Restoration Engine.
 *
 * Manages pristine original backups in `ofio-backups/`, individual and bulk
 * restore operations, orphan file cleanup, and attachment deletion hooks.
 *
 * @package OptiForge_Image_Optimizer
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound
namespace Optiforge_Image_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Backup
 */
class Backup {

	/**
	 * Absolute path to the backup directory.
	 *
	 * @var string
	 */
	private string $backup_dir;

	/**
	 * Flag set to true while a restore is in progress to prevent auto-optimize
	 * from firing on the regenerated metadata.
	 *
	 * @var bool
	 */
	public static bool $is_restoring = false;

	/**
	 * Backup constructor.
	 */
	public function __construct() {
		$upload_dir       = wp_upload_dir();
		$this->backup_dir = trailingslashit( $upload_dir['basedir'] ) . 'ofio-backups';
		$this->maybe_create_dir();
	}

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

	/**
	 * Create a backup copy of an attachment's main file before any modification.
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

		// Do not overwrite an existing pristine backup.
		if ( file_exists( $backup_path ) ) {
			return true;
		}

		$ok = copy( $file_path, $backup_path );
		if ( $ok ) {
			update_post_meta( $attachment_id, '_ofio_backup_path', $backup_path );
		}
		return $ok;
	}

	/**
	 * Restore an attachment from its backup file to pristine original.
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

		if ( ! file_exists( (string) $backup_path ) ) {
			delete_post_meta( $attachment_id, '_ofio_backup_path' );
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

		// Replace current file with backup.
		if ( ! copy( (string) $backup_path, $file ) ) {
			return array(
				'success' => false,
				'message' => __( 'Could not overwrite file with backup copy.', 'optiforge-image-optimizer' ),
			);
		}

		// Delete individual backup file after successful restore.
		wp_delete_file( (string) $backup_path );
		delete_post_meta( $attachment_id, '_ofio_backup_path' );

		// Clean up generated sidecars and optimization meta.
		$this->delete_sidecar_files( $attachment_id );
		delete_post_meta( $attachment_id, '_ofio_meta' );

		wp_cache_delete( 'ofio_count_restorable', 'ofio' );

		// Regenerate responsive sizes without triggering re-optimization.
		self::$is_restoring = true;
		try {
			if ( ! function_exists( 'wp_generate_attachment_metadata' ) && file_exists( ABSPATH . 'wp-admin/includes/image.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}
			if ( function_exists( 'wp_generate_attachment_metadata' ) ) {
				$metadata = wp_generate_attachment_metadata( $attachment_id, $file );
				wp_update_attachment_metadata( $attachment_id, $metadata );
			}
		} finally {
			self::$is_restoring = false;
		}

		$stats = new Stats();
		$stats->record_restore( $attachment_id );

		if ( class_exists( '\OFIO_Cache' ) ) {
			\OFIO_Cache::purge_all();
			\OFIO_Cache::purge_attachment( $attachment_id );
		}

		return array(
			'success' => true,
			'message' => __( 'Restored to original successfully.', 'optiforge-image-optimizer' ),
		);
	}

	/**
	 * Hooked to delete_attachment: clean up backup file and sidecars when media item is deleted.
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
		wp_cache_delete( 'ofio_count_restorable', 'ofio' );
	}

	/**
	 * Delete all .webp and .avif sidecars for master image and all thumbnail sizes.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return void
	 */
	public function delete_sidecar_files( int $attachment_id ): void {
		$file = get_attached_file( $attachment_id );
		if ( ! $file ) {
			return;
		}

		$this->unlink_sidecars_for_path( $file );

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
	 * Unlink sidecars for a given source path.
	 *
	 * @param string $source_path File path.
	 * @return void
	 */
	private function unlink_sidecars_for_path( string $source_path ): void {
		$info = pathinfo( $source_path );

		foreach ( array( 'webp', 'avif' ) as $ext ) {
			$sidecar_appended = $source_path . '.' . $ext;
			if ( file_exists( $sidecar_appended ) ) {
				wp_delete_file( $sidecar_appended );
			}

			$sidecar_replaced = $info['dirname'] . DIRECTORY_SEPARATOR . $info['filename'] . '.' . $ext;
			if ( $sidecar_replaced !== $source_path && file_exists( $sidecar_replaced ) ) {
				wp_delete_file( $sidecar_replaced );
			}
		}
	}

	/**
	 * Count restorable images in the database.
	 *
	 * @return int
	 */
	public function count_restorable_images(): int {
		global $wpdb;
		wp_cache_delete( 'ofio_count_restorable', 'ofio' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_ofio_backup_path'
			)
		);

		return (int) $count;
	}

	/**
	 * Retrieve attachment IDs with backup records.
	 *
	 * @param int $limit Maximum IDs to fetch.
	 * @return int[]
	 */
	public function get_restorable_ids( int $limit = 500 ): array {
		global $wpdb;

		if ( $limit > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
					 WHERE meta_key = %s
					 ORDER BY post_id DESC
					 LIMIT %d",
					'_ofio_backup_path',
					$limit
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
					 WHERE meta_key = %s
					 ORDER BY post_id DESC",
					'_ofio_backup_path'
				)
			);
		}

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * Check whether a physical backup file exists for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function has_backup( int $attachment_id ): bool {
		$path = get_post_meta( $attachment_id, '_ofio_backup_path', true );
		return ! empty( $path ) && file_exists( (string) $path );
	}

	/**
	 * Get total size and count of the backup directory.
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
	 * Permanently delete all backup files from disk and remove all backup postmeta records.
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
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_ofio_backup_path'
			)
		);

		wp_cache_delete( 'ofio_count_restorable', 'ofio' );

		return array(
			'success' => true,
			'deleted' => $deleted,
			'message' => sprintf(
				/* translators: %d: count */
				__( 'Purged %d backup files.', 'optiforge-image-optimizer' ),
				$deleted
			),
		);
	}

	/**
	 * Build backup file path for an attachment.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $source_file   Source file path.
	 * @return string
	 */
	private function get_backup_path( int $attachment_id, string $source_file ): string {
		$ext = strtolower( (string) pathinfo( $source_file, PATHINFO_EXTENSION ) );
		return trailingslashit( $this->backup_dir ) . 'ofio-' . $attachment_id . '.' . $ext;
	}
}

// Backwards-compatible class aliases.
if ( ! class_exists( 'OFIO_Backup', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Backup', 'OFIO_Backup' );
}
if ( ! class_exists( '\OptiForge\ImageOptimizer\Backup', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Backup', '\OptiForge\ImageOptimizer\Backup' );
}
if ( ! class_exists( '\OptiForge_Image_Optimizer\Backup', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Backup', '\OptiForge_Image_Optimizer\Backup' );
}