<?php
/**
 * Image Optimization Engine.
 *
 * Supports JPEG, PNG, WebP compression and AVIF/WebP sidecar generation via
 * Imagick (preferred) or GD. Handles animated GIF detection, memory-safe
 * large-file processing, and proper transparency preservation.
 *
 * @package OptiForge_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OFIO_Optimizer
 */
class OFIO_Optimizer {

	/**
	 * Shared OFIO_Backup instance injected by OFIO_Core.
	 *
	 * @var OFIO_Backup|null
	 */
	private ?OFIO_Backup $backup = null;

	public function set_backup( OFIO_Backup $backup ): void {
		$this->backup = $backup;
	}

	public function get_engine(): string {
		if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
			return 'imagick';
		}
		if ( extension_loaded( 'gd' ) && function_exists( 'imagecreatefromjpeg' ) ) {
			return 'gd';
		}
		return 'none';
	}

	public function supports_avif(): bool {
		$engine = $this->get_engine();

		if ( 'imagick' === $engine ) {
			try {
				$formats = \Imagick::queryFormats( 'AVIF' );
				return is_array( $formats ) && ! empty( $formats );
			} catch ( \Throwable $e ) {
				return false;
			}
		}

		if ( 'gd' === $engine ) {
			return function_exists( 'imageavif' )
				&& defined( 'IMG_AVIF' )
				&& ( imagetypes() & IMG_AVIF );
		}

		return false;
	}

	public static function is_animated_gif( string $path ): bool {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = file_get_contents( $path, false, null, 0, 1048576 );
		if ( false === $data ) {
			return false;
		}

		$frame_count = substr_count( $data, "\x00\x21\xF9\x04" );
		return $frame_count > 1;
	}

	public function optimize_attachment( int $attachment_id, array $metadata = array() ): array {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		if ( empty( $metadata ) ) {
			$metadata = (array) wp_get_attachment_metadata( $attachment_id );
		}

		$upload_dir   = wp_upload_dir();
		$base_dir     = trailingslashit( $upload_dir['basedir'] );
		$file         = get_attached_file( $attachment_id );
		$quality      = (int) get_option( 'ofio_compression_quality', 82 );
		$mode         = (string) get_option( 'ofio_compression_mode', 'lossy' );
		$webp_enabled = (bool) get_option( 'ofio_webp_conversion', 1 );
		$avif_enabled = (bool) get_option( 'ofio_avif_conversion', 1 ) && $this->supports_avif();
		$backup_on    = (bool) get_option( 'ofio_backup_enabled', 1 );

		if ( ! $file || ! file_exists( $file ) ) {
			return $this->error_result( __( 'Source file not found.', 'optiforge-image-optimizer' ) );
		}

		$mime = $this->detect_mime( $file );

		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			return $this->error_result(
				sprintf(
					/* translators: %s: MIME type string */
					__( 'Unsupported file type: %s', 'optiforge-image-optimizer' ),
					$mime
				)
			);
		}

		// Invariant 1: Backup & Measure true original size BEFORE resizing.
		$backup = $this->backup ?? new OFIO_Backup();

		if ( $backup_on ) {
			$backup->create_backup( $file, $attachment_id );
		}

		$backup->delete_sidecar_files( $attachment_id );

		$initial_main_filesize = (int) filesize( $file );

		// Scale oversized images.
		if ( get_option( 'ofio_resize_enabled', 1 ) ) {
			$max_w = (int) get_option( 'ofio_max_width', 2048 );
			$max_h = (int) get_option( 'ofio_max_height', 2048 );
			if ( $this->scale_image_dimensions( $file, $max_w, $max_h ) ) {
				$new_dims = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( $new_dims ) {
					$metadata['width']  = $new_dims[0];
					$metadata['height'] = $new_dims[1];
					wp_update_attachment_metadata( $attachment_id, $metadata );
				}
			}
		}

		$total_original = 0;
		$total_new      = 0;
		$webp_created   = false;
		$avif_created   = false;
		$errors         = array();

		// Optimize main file with pre-scaling size reference.
		$main = $this->process_file( $file, $quality, $mode, $webp_enabled, $avif_enabled, $initial_main_filesize );
		if ( $main['success'] ) {
			$total_original += $main['original_size'];
			$total_new      += $main['new_size'];
			$webp_created    = $webp_created || $main['webp_created'];
			$avif_created    = $avif_created || $main['avif_created'];
		} else {
			$errors[] = basename( $file ) . ': ' . $main['error'];
		}

		// Optimize thumbnails.
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$sub_dir = ! empty( $metadata['file'] )
				? trailingslashit( $base_dir . dirname( $metadata['file'] ) )
				: trailingslashit( dirname( $file ) );

			foreach ( $metadata['sizes'] as $size_data ) {
				if ( empty( $size_data['file'] ) ) {
					continue;
				}
				$thumb = $sub_dir . $size_data['file'];
				if ( ! file_exists( $thumb ) ) {
					continue;
				}

				$res = $this->process_file( $thumb, $quality, $mode, $webp_enabled, $avif_enabled );
				if ( $res['success'] ) {
					$total_original += $res['original_size'];
					$total_new      += $res['new_size'];
					$webp_created    = $webp_created || $res['webp_created'];
					$avif_created    = $avif_created || $res['avif_created'];
				} else {
					$errors[] = basename( $thumb ) . ': ' . $res['error'];
				}

				if ( function_exists( 'gc_collect_cycles' ) ) {
					gc_collect_cycles();
				}
			}
		}

		if ( class_exists( 'OFIO_Cache' ) ) {
			OFIO_Cache::purge_all();
		}

		return array(
			'success'        => empty( $errors ),
			'original_size'  => $total_original,
			'optimized_size' => $total_new,
			'saved_bytes'    => max( 0, $total_original - $total_new ),
			'webp_created'   => $webp_created,
			'avif_created'   => $avif_created,
			'error'          => implode( ' | ', $errors ),
		);
	}

	public function scale_image_dimensions( string $file_path, int $max_w, int $max_h ): bool {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		$size = @getimagesize( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $size ) {
			return false;
		}

		list( $orig_w, $orig_h ) = $size;
		if ( $orig_w <= $max_w && $orig_h <= $max_h ) {
			return false;
		}

		$editor = wp_get_image_editor( $file_path );
		if ( is_wp_error( $editor ) ) {
			return false;
		}

		$result = $editor->resize( $max_w, $max_h, false );
		if ( is_wp_error( $result ) ) {
			return false;
		}

		$saved = $editor->save( $file_path );
		unset( $editor );

		return ! is_wp_error( $saved );
	}

	public function process_file(
		string $file_path,
		int $quality,
		string $mode,
		bool $webp_enabled,
		bool $avif_enabled = false,
		int $pre_measured_original_size = 0
	): array {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		if ( ! file_exists( $file_path ) ) {
			return $this->error_result( __( 'File not found.', 'optiforge-image-optimizer' ) );
		}

		$actual_file_size = (int) filesize( $file_path );
		$original_size    = ( $pre_measured_original_size > 0 ) ? $pre_measured_original_size : $actual_file_size;

		if ( 0 === $actual_file_size ) {
			return $this->error_result( __( 'File is empty.', 'optiforge-image-optimizer' ) );
		}

		$max_allowed_bytes = defined( 'OFIO_MAX_FILE_BYTES' ) ? OFIO_MAX_FILE_BYTES : 52428800; // 50MB
		if ( $actual_file_size > $max_allowed_bytes ) {
			return $this->error_result(
				sprintf(
					/* translators: %s: human-readable file size */
					__( 'File too large to process safely (%s).', 'optiforge-image-optimizer' ),
					size_format( $actual_file_size )
				)
			);
		}

		$mime = $this->detect_mime( $file_path );

		if ( 'image/gif' === $mime && self::is_animated_gif( $file_path ) ) {
			return $this->error_result( __( 'Animated GIF skipped to preserve animation frames.', 'optiforge-image-optimizer' ) );
		}

		$engine       = $this->get_engine();
		$webp_created = false;
		$avif_created = false;
		$temp_path    = $file_path . '.ofio_tmp';

		if ( ! copy( $file_path, $temp_path ) ) {
			return $this->error_result( __( 'Could not create temp file.', 'optiforge-image-optimizer' ) );
		}

		if ( 'imagick' === $engine ) {
			$res = $this->compress_imagick( $temp_path, $mime, $quality, $mode );
		} elseif ( 'gd' === $engine ) {
			$res = $this->compress_gd( $temp_path, $mime, $quality, $mode );
		} else {
			wp_delete_file( $temp_path );
			return $this->error_result( __( 'No imaging library available (install GD or Imagick).', 'optiforge-image-optimizer' ) );
		}

		if ( ! $res['success'] ) {
			wp_delete_file( $temp_path );
			return $res;
		}

		clearstatcache( true, $temp_path );
		$temp_size = (int) filesize( $temp_path );

		// Replace original if temp is smaller than the current file on disk.
		if ( $temp_size > 0 && $temp_size < $actual_file_size ) {
			global $wp_filesystem;
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			if ( empty( $wp_filesystem ) ) {
				WP_Filesystem();
			}

			$moved = false;
			if ( ! empty( $wp_filesystem ) ) {
				$moved = $wp_filesystem->move( $temp_path, $file_path, true );
			}

			if ( ! $moved ) {
				copy( $temp_path, $file_path );
				wp_delete_file( $temp_path );
			}
			$final_size = $temp_size;
		} else {
			wp_delete_file( $temp_path );
			$final_size = $actual_file_size;
		}

		// Invariant 2: Generate Sidecars matching Nginx rule (${uri}${ofio_suffix})
		if ( $webp_enabled && 'image/webp' !== $mime ) {
			if ( 'imagick' === $engine ) {
				$webp_created = $this->generate_webp_imagick( $file_path, $quality );
			} else {
				$webp_created = $this->generate_webp_gd( $file_path, $mime, $quality );
			}
		}

		if ( $avif_enabled ) {
			if ( 'imagick' === $engine ) {
				$avif_created = $this->generate_avif_imagick( $file_path, $quality );
			} else {
				$avif_created = $this->generate_avif_gd( $file_path, $mime, $quality );
			}
		}

		return array(
			'success'       => true,
			'original_size' => $original_size,
			'new_size'      => $final_size,
			'webp_created'  => $webp_created,
			'avif_created'  => $avif_created,
			'error'         => '',
		);
	}

	private function compress_imagick( string $path, string $mime, int $quality, string $mode ): array {
		$im = null;
		try {
			$im = new \Imagick( $path );

			if ( method_exists( $im, 'autoOrient' ) ) {
				$im->autoOrient();
			}

			if ( ! get_option( 'ofio_preserve_exif', 0 ) ) {
				$im->stripImage();
			}

			if ( 'image/jpeg' === $mime ) {
				$im->setImageFormat( 'JPEG' );
				$im->setInterlaceScheme( \Imagick::INTERLACE_PLANE );
				$im->setImageCompression( \Imagick::COMPRESSION_JPEG );
				$im->setImageCompressionQuality( 'lossless' === $mode ? 90 : $quality );
				$im->setOption( 'jpeg:optimize-coding', 'true' );
			} elseif ( 'image/png' === $mime ) {
				$im->setImageFormat( 'PNG' );
				$im->setImageCompression( \Imagick::COMPRESSION_ZIP );
				$im->setImageCompressionQuality( 95 );
			} elseif ( 'image/webp' === $mime ) {
				$im->setImageFormat( 'WEBP' );
				$im->setImageCompressionQuality( 'lossless' === $mode ? 100 : $quality );
			}

			$ok = $im->writeImage( $path );

			return $ok
				? array( 'success' => true, 'error' => '' )
				: $this->error_result( __( 'Imagick write failed.', 'optiforge-image-optimizer' ) );

		} catch ( \Throwable $e ) {
			return $this->error_result( 'Imagick: ' . $e->getMessage() );
		} finally {
			if ( null !== $im ) {
				$im->clear();
				$im->destroy();
			}
		}
	}

	private function compress_gd( string $path, string $mime, int $quality, string $mode ): array {
		$image = null;
		try {
			if ( 'image/jpeg' === $mime ) {
				$image = @imagecreatefromjpeg( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( $image && function_exists( 'exif_read_data' ) ) {
					$exif = @exif_read_data( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					if ( ! empty( $exif['Orientation'] ) ) {
						$image = $this->auto_rotate_gd( $image, (int) $exif['Orientation'] );
					}
				}
			} elseif ( 'image/png' === $mime ) {
				$image = @imagecreatefrompng( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( $image ) {
					imagealphablending( $image, false );
					imagesavealpha( $image, true );
				}
			} elseif ( 'image/webp' === $mime && function_exists( 'imagecreatefromwebp' ) ) {
				$image = @imagecreatefromwebp( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			if ( ! $image ) {
				return $this->error_result( __( 'GD could not open image.', 'optiforge-image-optimizer' ) );
			}

			$ok = false;
			if ( 'image/jpeg' === $mime ) {
				$ok = imagejpeg( $image, $path, 'lossless' === $mode ? 90 : $quality );
			} elseif ( 'image/png' === $mime ) {
				$ok = imagepng( $image, $path, 9 );
			} elseif ( 'image/webp' === $mime && function_exists( 'imagewebp' ) ) {
				$ok = imagewebp( $image, $path, 'lossless' === $mode ? 100 : $quality );
			}

			return $ok
				? array( 'success' => true, 'error' => '' )
				: $this->error_result( __( 'GD write failed.', 'optiforge-image-optimizer' ) );

		} finally {
			if ( null !== $image && ( is_resource( $image ) || $image instanceof \GdImage ) ) {
				imagedestroy( $image );
			}
		}
	}

	private function generate_webp_imagick( string $path, int $quality ): bool {
		$webp_dst = $path . '.webp';

		$im = null;
		try {
			$im = new \Imagick( $path );
			if ( method_exists( $im, 'autoOrient' ) ) {
				$im->autoOrient();
			}
			$im->setImageFormat( 'WEBP' );
			$im->setImageCompressionQuality( $quality );
			$im->setOption( 'webp:method', '6' );

			return (bool) $im->writeImage( $webp_dst );
		} catch ( \Throwable $e ) {
			return false;
		} finally {
			if ( null !== $im ) {
				$im->clear();
				$im->destroy();
			}
		}
	}

	private function generate_webp_gd( string $path, string $mime, int $quality ): bool {
		if ( ! function_exists( 'imagewebp' ) ) {
			return false;
		}

		$webp_dst = $path . '.webp';
		$image    = null;

		try {
			if ( 'image/jpeg' === $mime ) {
				$image = @imagecreatefromjpeg( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} elseif ( 'image/png' === $mime ) {
				$image = @imagecreatefrompng( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			if ( ! $image ) {
				return false;
			}

			if ( 'image/png' === $mime ) {
				if ( function_exists( 'imagepalettetotruecolor' ) ) {
					imagepalettetotruecolor( $image );
				}
				imagealphablending( $image, false );
				imagesavealpha( $image, true );
			}

			return (bool) imagewebp( $image, $webp_dst, $quality );
		} finally {
			if ( null !== $image ) {
				imagedestroy( $image );
			}
		}
	}

	private function generate_avif_imagick( string $path, int $quality ): bool {
		$avif_dst = $path . '.avif';

		$im = null;
		try {
			$im = new \Imagick( $path );
			if ( method_exists( $im, 'autoOrient' ) ) {
				$im->autoOrient();
			}
			$im->setImageFormat( 'AVIF' );
			$im->setImageCompressionQuality( $quality );

			return (bool) $im->writeImage( $avif_dst );
		} catch ( \Throwable $e ) {
			return false;
		} finally {
			if ( null !== $im ) {
				$im->clear();
				$im->destroy();
			}
		}
	}

	private function generate_avif_gd( string $path, string $mime, int $quality ): bool {
		if ( ! function_exists( 'imageavif' ) || ! defined( 'IMG_AVIF' ) || ! ( imagetypes() & IMG_AVIF ) ) {
			return false;
		}

		$avif_dst = $path . '.avif';
		$image    = null;

		try {
			if ( 'image/jpeg' === $mime ) {
				$image = @imagecreatefromjpeg( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} elseif ( 'image/png' === $mime ) {
				$image = @imagecreatefrompng( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} elseif ( 'image/webp' === $mime && function_exists( 'imagecreatefromwebp' ) ) {
				$image = @imagecreatefromwebp( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			if ( ! $image ) {
				return false;
			}

			if ( function_exists( 'imagepalettetotruecolor' ) ) {
				imagepalettetotruecolor( $image );
			}

			imagealphablending( $image, false );
			imagesavealpha( $image, true );

			return (bool) imageavif( $image, $avif_dst, $quality );
		} finally {
			if ( null !== $image ) {
				imagedestroy( $image );
			}
		}
	}

	private function auto_rotate_gd( $image, int $orientation ) {
		switch ( $orientation ) {
			case 3:
				$image = imagerotate( $image, 180, 0 );
				break;
			case 6:
				$image = imagerotate( $image, -90, 0 );
				break;
			case 8:
				$image = imagerotate( $image, 90, 0 );
				break;
		}
		return $image;
	}

	private function detect_mime( string $file_path ): string {
		$mime = '';
		if ( function_exists( 'mime_content_type' ) ) {
			$detected = @mime_content_type( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $detected ) {
				$mime = (string) $detected;
			}
		}
		if ( empty( $mime ) || 'application/octet-stream' === $mime ) {
			$check = wp_check_filetype( $file_path );
			$mime  = (string) $check['type'];
		}
		return $mime;
	}

	private function error_result( string $message ): array {
		return array(
			'success'        => false,
			'original_size'  => 0,
			'optimized_size' => 0,
			'new_size'       => 0,
			'saved_bytes'    => 0,
			'webp_created'   => false,
			'avif_created'   => false,
			'error'          => $message,
		);
	}
}