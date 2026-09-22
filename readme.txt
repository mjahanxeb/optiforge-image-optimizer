=== OptiForge Image Optimizer ===
Contributors: xmaszcomm, jahanzaib
Tags: image optimizer, webp, avif, compression, performance
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 1.3.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast local image optimizer with WebP and AVIF conversion, bulk compression, one-click restore, and rewrite delivery for faster sites.

== Description ==

OptiForge Image Optimizer is a production-grade image optimization plugin built to handle real-world websites — from small blogs to large media libraries with thousands of images — without server timeouts, memory crashes, or data loss.

= Key Features =

**Dual Imaging Engine**
Uses Imagick (preferred) or GD Library automatically. No manual configuration needed.

**Next-Gen Format Generation**
Automatically creates lightweight WebP and AVIF sidecar files from JPG, PNG, and WebP sources. Both formats are generated in parallel where supported.

**Memory-Safe Large Image Processing**
Raises PHP memory limits before processing, enforces a configurable file-size cap (default 50 MB) to prevent out-of-memory crashes on 4K/8K uploads, and calls gc_collect_cycles() after every thumbnail.

**Transparent PNG Support**
Fully preserves alpha channel transparency when converting PNG images to WebP and AVIF — no black backgrounds.

**Animated GIF Protection**
Automatically detects animated GIFs and skips them rather than flattening all frames into a single frozen image.

**Time-Budgeted Bulk Optimizer**
Processes images in safe 14-second AJAX batches to prevent 504 Gateway Timeouts on libraries with hundreds or thousands of images. Supports pause, resume, and stop controls.

**Force Re-optimize Mode**
One checkbox to re-compress and convert all existing images — including previously optimized JPGs, PNGs, and WebPs — to AVIF and WebP.

**One-Click Bulk Restore**
Reverts every image back to its untouched original with live chunked progress. The restore queue is protected against infinite loops and stale record lockouts.

**Automatic Backup & Cleanup**
Creates a secure backup copy of each original before any changes. Backups and sidecar files (.webp, .avif) are automatically cleaned up when an image is permanently deleted from the Media Library.

== Installation ==

1. Upload the `optiforge-image-optimizer` folder to your `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Settings > OptiForge Optimizer** to configure your options.

== Frequently Asked Questions ==

= Does this plugin modify the original images permanently? =
No. When backup is enabled, the plugin creates a copy in `wp-content/uploads/ofio-backups/` before making changes. You can restore originals anytime.

= What happens if I delete an image from the Media Library? =
The plugin automatically cleans up the corresponding backup and all generated WebP/AVIF sidecars.

== Changelog ==

= 1.3.1 =
* Initial public release with full WordPress.org directory compliance.
* Feature: Parallel WebP and AVIF generation directly on upload.
* Feature: Safe 14-second time-budgeted bulk optimization preventing 504 Gateway Timeouts.
* Feature: One-click bulk restore with automated queue cleanup and loop protection.
* Security: Hardened database queries with $wpdb->prepare() and WP_Filesystem API integration.
* Compatibility: Fully tested and compatible up to WordPress 7.1.

== Upgrade Notice ==

= 1.3.1 =
Production-ready stable release featuring next-gen AVIF/WebP compression, bulk optimization, and full WordPress 7.1 support.