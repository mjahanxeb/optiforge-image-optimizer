<?php
/**
 * Plugin Name:       OptiForge Image Optimizer
 * Plugin URI:        https://github.com/mjahanxeb/optiforge-image-optimizer
 * Description:       Enterprise-grade background image optimization: Browser-independent queue processing, AVIF/WebP generation, Max dimension resizing, One-click bulk restore, Live media inline optimization, and WP-CLI commands.
 * Version:           1.4.0
 * Requires at least: 5.8
 * Requires PHP:      8.1
 * Author:            Muhammad Jahanzaib
 * Author URI:        https://github.com/mjahanxeb
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       optiforge-image-optimizer
 * Domain Path:       /languages
 *
 * @package OptiForge_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// 1. Compatibility Gate — must run before anything else loads.
// ---------------------------------------------------------------------------

/**
 * Display an admin notice and deactivate the plugin when requirements are unmet.
 *
 * @param string $message Human-readable explanation.
 * @return void
 */
function ofio_deactivate_with_notice( string $message ): void {
	add_action(
		'admin_notices',
		static function () use ( $message ): void {
			printf(
				'<div class="notice notice-error"><p><strong>OptiForge Image Optimizer:</strong> %s</p></div>',
				esc_html( $message )
			);
		}
	);

	// Deactivate cleanly — no fatal.
	add_action(
		'admin_init',
		static function (): void {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['activate'] ) ) {
				unset( $_GET['activate'] );
			}
		}
	);
}

// PHP version check (PHP 8.1+ required).
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	ofio_deactivate_with_notice(
		sprintf(
			/* translators: 1: current PHP version, 2: required PHP version */
			__( 'Your server is running PHP %1$s. OptiForge Image Optimizer requires PHP %2$s or higher. Please upgrade PHP or contact your host.', 'optiforge-image-optimizer' ),
			PHP_VERSION,
			'8.1'
		)
	);
	return;
}

// WordPress version check (WP 5.8+ required).
global $wp_version;
if ( isset( $wp_version ) && version_compare( $wp_version, '5.8', '<' ) ) {
	ofio_deactivate_with_notice(
		sprintf(
			/* translators: 1: current WP version, 2: required WP version */
			__( 'Your site is running WordPress %1$s. OptiForge Image Optimizer requires WordPress %2$s or higher.', 'optiforge-image-optimizer' ),
			$wp_version,
			'5.8'
		)
	);
	return;
}

// ---------------------------------------------------------------------------
// 2. Constants.
// ---------------------------------------------------------------------------

define( 'OFIO_VERSION',         '1.4.0' );
define( 'OFIO_PATH',            plugin_dir_path( __FILE__ ) );
define( 'OFIO_URL',             plugin_dir_url( __FILE__ ) );
define( 'OFIO_BASENAME',        plugin_basename( __FILE__ ) );
define( 'OFIO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'OFIO_INCLUDES',        OFIO_PATH . 'includes/' );

/**
 * Maximum file size (bytes) that will be loaded into GD/Imagick.
 * Files larger than this threshold are skipped to prevent memory exhaustion.
 * Default: 50 MB.
 */
if ( ! defined( 'OFIO_MAX_FILE_BYTES' ) ) {
	define( 'OFIO_MAX_FILE_BYTES', 52428800 ); // 50 MB
}

// ---------------------------------------------------------------------------
// 3. Dual PSR-4 & Legacy Autoloader.
// ---------------------------------------------------------------------------

spl_autoload_register(
	static function ( string $class_name ): void {
		$class_name = ltrim( $class_name, '\\' );
		$file       = '';

		// 1. Handle Optiforge_Image_Optimizer / OptiForge_Image_Optimizer namespace.
		if ( 0 === stripos( $class_name, 'Optiforge_Image_Optimizer\\' ) ) {
			$relative = substr( $class_name, strlen( 'Optiforge_Image_Optimizer\\' ) );
			$slug     = ( 'cli' === strtolower( $relative ) )
				? 'cli'
				: strtolower( (string) preg_replace( '/(?<!^)[A-Z]/', '-$0', $relative ) );
			$file     = OFIO_INCLUDES . 'class-' . $slug . '.php';
		}
		// 2. Handle legacy OptiForge\ImageOptimizer namespace.
		elseif ( 0 === stripos( $class_name, 'OptiForge\\ImageOptimizer\\' ) ) {
			$relative = substr( $class_name, strlen( 'OptiForge\\ImageOptimizer\\' ) );
			$slug     = ( 'cli' === strtolower( $relative ) )
				? 'cli'
				: strtolower( (string) preg_replace( '/(?<!^)[A-Z]/', '-$0', $relative ) );
			$file     = OFIO_INCLUDES . 'class-' . $slug . '.php';
		}
		// 3. Handle legacy OFIO_ prefix.
		elseif ( 0 === strpos( $class_name, 'OFIO_' ) ) {
			if ( ! preg_match( '/^OFIO_[A-Za-z0-9_]+$/', $class_name ) ) {
				return;
			}
			$slug = strtolower(
				str_replace(
					array( 'OFIO_', '_' ),
					array( '',      '-' ),
					$class_name
				)
			);
			$file = OFIO_INCLUDES . 'class-' . $slug . '.php';
		} else {
			return;
		}

		if ( empty( $file ) ) {
			return;
		}

		/*
		 * Prevent path-traversal on case-sensitive Linux file systems.
		 */
		$real_includes = realpath( OFIO_INCLUDES );
		$real_file     = realpath( $file );

		if (
			false === $real_file
			|| false === $real_includes
			|| 0 !== strpos( $real_file, $real_includes )
		) {
			return;
		}

		require_once $real_file;
	}
);

// ---------------------------------------------------------------------------
// 4. Activation / Deactivation Hooks.
// ---------------------------------------------------------------------------

register_activation_hook( __FILE__, 'ofio_activate' );

/**
 * Plugin activation: seed default options, create backup directory, create queue table, schedule cron.
 *
 * @return void
 */
function ofio_activate(): void {
	$defaults = array(
		'auto_optimize'       => 1,
		'compression_quality' => 82,
		'webp_conversion'     => 1,
		'avif_conversion'     => 1,
		'backup_enabled'      => 1,
		'preserve_exif'       => 0,
		'compression_mode'    => 'lossy',
		'batch_size_mode'     => 'auto',
		'batch_size'          => 5,
		'serve_webp'          => 1,
		'resize_enabled'      => 1,
		'max_width'           => 2048,
		'max_height'          => 2048,
	);

	foreach ( $defaults as $key => $value ) {
		if ( false === get_option( 'ofio_' . $key ) ) {
			update_option( 'ofio_' . $key, $value, false );
		}
	}

	if ( false === get_option( 'ofio_stats' ) ) {
		update_option(
			'ofio_stats',
			array(
				'total_images'     => 0,
				'optimized_images' => 0,
				'original_size'    => 0,
				'new_size'         => 0,
				'saved_bytes'      => 0,
			),
			false
		);
	}

	// Create backup directory with access protection.
	$upload_dir = wp_upload_dir();
	$backup_dir = trailingslashit( $upload_dir['basedir'] ) . 'ofio-backups';

	if ( ! file_exists( $backup_dir ) ) {
		wp_mkdir_p( $backup_dir );
	}

	// Apache/LiteSpeed: deny direct access.
	$htaccess = $backup_dir . '/.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		$htaccess_content  = "# Apache 2.4+\n<IfModule authz_core_module>\n    Require all denied\n</IfModule>\n";
		$htaccess_content .= "# Apache 2.2\n<IfModule !authz_core_module>\n    Deny from all\n</IfModule>\n";
		file_put_contents( $htaccess, $htaccess_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	if ( ! file_exists( $backup_dir . '/index.php' ) ) {
		file_put_contents( $backup_dir . '/index.php', '<?php // Silence is golden.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	// Create queue database table.
	if ( class_exists( 'Optiforge_Image_Optimizer\Queue' ) ) {
		$optimizer = new \Optiforge_Image_Optimizer\Optimizer();
		$backup    = new \Optiforge_Image_Optimizer\Backup();
		$stats     = new \Optiforge_Image_Optimizer\Stats();
		$queue     = new \Optiforge_Image_Optimizer\Queue( $optimizer, $backup, $stats );
		$queue->maybe_create_table();
	}

	// Schedule hourly optimization cron.
	if ( ! wp_next_scheduled( 'ofio_scheduled_optimization' ) ) {
		wp_schedule_event( time(), 'hourly', 'ofio_scheduled_optimization' );
	}
}

register_deactivation_hook( __FILE__, 'ofio_deactivate' );

/**
 * Plugin deactivation: unschedule cron, flush transients.
 *
 * @return void
 */
function ofio_deactivate(): void {
	$timestamp = wp_next_scheduled( 'ofio_scheduled_optimization' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'ofio_scheduled_optimization' );
	}

	$watchdog_timestamp = wp_next_scheduled( 'ofio_queue_watchdog' );
	if ( $watchdog_timestamp ) {
		wp_unschedule_event( $watchdog_timestamp, 'ofio_queue_watchdog' );
	}

	delete_transient( 'ofio_stats_cache' );
	delete_transient( 'ofio_unoptimized_ids' );
	delete_transient( 'ofio_queue_lock' );
	delete_transient( 'ofio_queue_token' );
	delete_transient( 'ofio_queue_recent_log' );
}

// ---------------------------------------------------------------------------
// 5. Bootstrap.
// ---------------------------------------------------------------------------

add_action( 'plugins_loaded', 'ofio_init' );

/**
 * Initialize plugin after all plugins are loaded.
 *
 * @return void
 */
function ofio_init(): void {
	\Optiforge_Image_Optimizer\Core::get_instance();

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::add_command( 'ofio', '\Optiforge_Image_Optimizer\CLI' );
	}
}