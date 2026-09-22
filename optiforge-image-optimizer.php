<?php
/**
 * Plugin Name:       OptiForge Image Optimizer
 * Plugin URI:        https://github.com/mjahanxeb/optiforge-image-optimizer
 * Description:       Enterprise-grade image optimization: AVIF/WebP generation, Max dimension resizing, Time-budgeted bulk optimizer, One-click bulk restore, Live media inline optimization, and WP-CLI commands.
 * Version:           1.3.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
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

// PHP version check.
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	ofio_deactivate_with_notice(
		sprintf(
			/* translators: 1: current PHP version, 2: required PHP version */
			__( 'Your server is running PHP %1$s. OptiForge Image Optimizer requires PHP %2$s or higher. Please upgrade PHP or contact your host.', 'optiforge-image-optimizer' ),
			PHP_VERSION,
			'7.4'
		)
	);
	return;
}

// WordPress version check.
global $wp_version;
if ( version_compare( $wp_version, '5.8', '<' ) ) {
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

define( 'OFIO_VERSION',  '1.3.1' );
define( 'OFIO_PATH',     plugin_dir_path( __FILE__ ) );
define( 'OFIO_URL',      plugin_dir_url( __FILE__ ) );
define( 'OFIO_BASENAME', plugin_basename( __FILE__ ) );
define( 'OFIO_INCLUDES', OFIO_PATH . 'includes/' );

/**
 * Maximum file size (bytes) that will be loaded into GD/Imagick.
 * Files larger than this threshold are skipped to prevent memory exhaustion.
 * Default: 50 MB.
 */
if ( ! defined( 'OFIO_MAX_FILE_BYTES' ) ) {
	define( 'OFIO_MAX_FILE_BYTES', 52428800 ); // 50 MB
}

// ---------------------------------------------------------------------------
// 3. Hardened PSR-4-style Autoloader.
// ---------------------------------------------------------------------------

spl_autoload_register(
	static function ( string $class_name ): void {
		// Only handle OFIO_ prefixed classes.
		if ( 0 !== strpos( $class_name, 'OFIO_' ) ) {
			return;
		}

		// Validate class name: only word chars allowed after prefix.
		if ( ! preg_match( '/^OFIO_[A-Za-z0-9_]+$/', $class_name ) ) {
			return;
		}

		// Build expected filename: OFIO_Custom_Folders → class-custom-folders.php
		$slug = strtolower(
			str_replace(
				array( 'OFIO_', '_' ),
				array( '',      '-' ),
				$class_name
			)
		);

		$file = OFIO_INCLUDES . 'class-' . $slug . '.php';

		/*
		 * Prevent path-traversal on case-sensitive Linux file systems.
		 * realpath() returns false if the file does not exist, so we
		 * check that the resolved path is strictly inside OFIO_INCLUDES.
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
 * Plugin activation: seed default options, create backup directory, schedule cron.
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
	delete_transient( 'ofio_stats_cache' );
	delete_transient( 'ofio_unoptimized_ids' );
}

// ---------------------------------------------------------------------------
// 5. Bootstrap.
// ---------------------------------------------------------------------------

add_action( 'plugins_loaded', 'ofio_init' );

/**
 * Initialize plugin after all plugins are loaded.
 * Note: load_plugin_textdomain() is intentionally omitted — WordPress 4.6+
 * automatically loads translations from the /languages directory.
 *
 * @return void
 */
function ofio_init(): void {
	OFIO_Core::get_instance();

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::add_command( 'ofio', 'OFIO_CLI' );
	}
}