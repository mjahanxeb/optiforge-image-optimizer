<?php
/**
 * Frontend Image Delivery & Server Rewrite Rule Generator.
 *
 * Injects <picture> elements with WebP/AVIF <source> alternatives while
 * preserving original responsive srcset/sizes attributes. Generates correct
 * server rewrite rules for Apache/LiteSpeed (mod_rewrite) and Nginx (map-based).
 *
 * @package OptiForge_Image_Optimizer
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound
namespace Optiforge_Image_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Delivery
 */
class Delivery {

	/**
	 * Delivery constructor.
	 */
	public function __construct() {
		if ( ! get_option( 'ofio_serve_webp', 1 ) ) {
			return;
		}

		add_filter( 'the_content',            array( $this, 'replace_images_in_content' ), 20 );
		add_filter( 'post_thumbnail_html',    array( $this, 'replace_thumbnail_html' ), 20, 5 );
		add_filter( 'wp_get_attachment_image', array( $this, 'filter_attachment_image' ), 20, 5 );
	}

	/**
	 * Replace <img> tags in post content with <picture> elements.
	 *
	 * @param string $content Post content HTML.
	 * @return string Modified content.
	 */
	public function replace_images_in_content( string $content ): string {
		if ( is_feed() || is_preview() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_admin() ) {
			return $content;
		}

		if ( empty( $content ) ) {
			return $content;
		}

		$filtered = preg_replace_callback(
			'/(<picture[^>]*>.*?<\/picture>)|(<img\s[^>]*src=["\']((?:https?:)?\/\/[^"\']+\.(jpe?g|png|gif|webp))["\'][^>]*>)/is',
			function ( array $matches ): string {
				// Already wrapped in <picture> — leave as-is.
				if ( ! empty( $matches[1] ) ) {
					return $matches[1];
				}
				return $this->maybe_wrap_in_picture( $matches[2] );
			},
			$content
		);

		return ( null !== $filtered ) ? $filtered : $content;
	}

	/**
	 * Wrap post thumbnail HTML in a <picture> element.
	 *
	 * @param string $html         Thumbnail HTML.
	 * @param int    $post_id      Post ID.
	 * @param int    $thumbnail_id Thumbnail attachment ID.
	 * @param mixed  $size         Size name or dimensions.
	 * @param mixed  $attr         Attributes array.
	 * @return string
	 */
	public function replace_thumbnail_html( string $html, int $post_id, int $thumbnail_id, $size, $attr ): string {
		if ( is_feed() || is_preview() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_admin() ) {
			return $html;
		}
		return $this->maybe_wrap_in_picture( $html );
	}

	/**
	 * Wrap wp_get_attachment_image() output in a <picture> element.
	 *
	 * @param string $html          Generated <img> HTML.
	 * @param int    $attachment_id Attachment ID.
	 * @param mixed  $size          Size.
	 * @param bool   $icon          Icon flag.
	 * @param array  $attr          Attributes.
	 * @return string
	 */
	public function filter_attachment_image( string $html, int $attachment_id, $size, bool $icon, array $attr ): string {
		if ( is_feed() || is_preview() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_admin() ) {
			return $html;
		}
		return $this->maybe_wrap_in_picture( $html );
	}

	/**
	 * Wrap an <img> tag in a <picture> element with WebP/AVIF <source> alternatives.
	 *
	 * @param string $img_html Raw <img> tag HTML.
	 * @return string Modified HTML.
	 */
	private function maybe_wrap_in_picture( string $img_html ): string {
		if ( empty( $img_html ) || false !== stripos( $img_html, '<picture' ) ) {
			return $img_html;
		}

		if ( ! preg_match( '/src=["\']((?:https?:)?\/\/[^"\']+\.(jpe?g|png|gif|webp))["\']/i', $img_html, $src_matches ) ) {
			return $img_html;
		}
		$src = $src_matches[1];

		// Verify original file exists on disk.
		$src_path = $this->url_to_path( $src );
		if ( ! $src_path || ! file_exists( $src_path ) ) {
			return $img_html;
		}

		$src_info = pathinfo( $src );
		$src_base = $src_info['dirname'] . '/' . $src_info['filename'];

		$webp_url  = $src . '.webp';
		$webp_path = $this->url_to_path( $webp_url );
		if ( ! $webp_path || ! file_exists( $webp_path ) ) {
			$webp_url  = $src_base . '.webp';
			$webp_path = $this->url_to_path( $webp_url );
			if ( ! $webp_path || ! file_exists( $webp_path ) ) {
				$webp_url  = '';
				$webp_path = null;
			}
		}

		$avif_url  = $src . '.avif';
		$avif_path = $this->url_to_path( $avif_url );
		if ( ! $avif_path || ! file_exists( $avif_path ) ) {
			$avif_url  = $src_base . '.avif';
			$avif_path = $this->url_to_path( $avif_url );
			if ( ! $avif_path || ! file_exists( $avif_path ) ) {
				$avif_url  = '';
				$avif_path = null;
			}
		}

		$srcset = '';
		$sizes  = '';
		if ( preg_match( '/\ssrcset=["\']((?:[^"\']+))["\']/i', $img_html, $srcset_matches ) ) {
			$srcset = $srcset_matches[1];
		}
		if ( preg_match( '/\ssizes=["\']((?:[^"\']+))["\']/i', $img_html, $sizes_matches ) ) {
			$sizes = $sizes_matches[1];
		}

		$sources = '';

		// Build AVIF <source> if sidecar exists on disk.
		if ( $avif_path && file_exists( $avif_path ) && ! empty( $avif_url ) ) {
			$avif_srcset = $this->build_next_gen_srcset( $srcset, 'avif' );
			$source_attr = 'srcset="' . esc_attr( $avif_url ) . '"';
			if ( ! empty( $avif_srcset ) ) {
				$source_attr = 'srcset="' . esc_attr( $avif_srcset ) . '"';
			}
			if ( ! empty( $sizes ) ) {
				$source_attr .= ' sizes="' . esc_attr( $sizes ) . '"';
			}
			$sources .= '<source ' . $source_attr . ' type="image/avif">';
		}

		// Build WebP <source> if sidecar exists on disk.
		if ( $webp_url !== $src && $webp_path && file_exists( $webp_path ) && ! empty( $webp_url ) ) {
			$webp_srcset = $this->build_next_gen_srcset( $srcset, 'webp' );
			$source_attr = 'srcset="' . esc_attr( $webp_url ) . '"';
			if ( ! empty( $webp_srcset ) ) {
				$source_attr = 'srcset="' . esc_attr( $webp_srcset ) . '"';
			}
			if ( ! empty( $sizes ) ) {
				$source_attr .= ' sizes="' . esc_attr( $sizes ) . '"';
			}
			$sources .= '<source ' . $source_attr . ' type="image/webp">';
		}

		if ( empty( $sources ) ) {
			return $img_html;
		}

		return '<picture>' . $sources . $img_html . '</picture>';
	}

	/**
	 * Rewrite responsive srcset attribute for next-gen variant.
	 *
	 * @param string $srcset    Original srcset.
	 * @param string $extension 'webp' or 'avif'.
	 * @return string Rebuilt srcset.
	 */
	private function build_next_gen_srcset( string $srcset, string $extension ): string {
		if ( empty( $srcset ) ) {
			return '';
		}

		$candidates     = array_map( 'trim', explode( ',', $srcset ) );
		$new_candidates = array();

		foreach ( $candidates as $candidate ) {
			$parts = preg_split( '/\s+/', trim( $candidate ), 2 );
			if ( empty( $parts[0] ) ) {
				continue;
			}

			$url        = $parts[0];
			$descriptor = $parts[1] ?? '';
			$url_info   = pathinfo( $url );

			$ext = strtolower( $url_info['extension'] ?? '' );
			if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp', 'gif' ), true ) ) {
				continue;
			}

			$new_url  = $url . '.' . $extension;
			$new_path = $this->url_to_path( $new_url );

			if ( ! $new_path || ! file_exists( $new_path ) ) {
				$new_url  = $url_info['dirname'] . '/' . $url_info['filename'] . '.' . $extension;
				$new_path = $this->url_to_path( $new_url );
			}

			if ( $new_path && file_exists( $new_path ) ) {
				$new_candidates[] = trim( $new_url . ' ' . $descriptor );
			}
		}

		return implode( ', ', $new_candidates );
	}

	/**
	 * Convert uploads URL to filesystem path.
	 *
	 * @param string $url URL.
	 * @return string|null Filesystem path or null.
	 */
	private function url_to_path( string $url ): ?string {
		$upload_dir = wp_upload_dir();
		$baseurl    = trailingslashit( $upload_dir['baseurl'] );
		$basedir    = trailingslashit( $upload_dir['basedir'] );

		$url_clean = strtok( $url, '?' );

		if ( 0 === strpos( $url_clean, $baseurl ) ) {
			return $basedir . substr( $url_clean, strlen( $baseurl ) );
		}

		$baseurl_no_protocol = preg_replace( '#^https?:#', '', $baseurl );
		$url_no_protocol     = preg_replace( '#^https?:#', '', (string) $url_clean );
		if ( $baseurl_no_protocol && 0 === strpos( $url_no_protocol, $baseurl_no_protocol ) ) {
			return $basedir . substr( $url_no_protocol, strlen( $baseurl_no_protocol ) );
		}

		return null;
	}

	/**
	 * Return Apache / LiteSpeed mod_rewrite rules.
	 *
	 * @return string
	 */
	public static function get_apache_rules(): string {
		return <<<'HTACCESS'
# BEGIN OptiForge Image Optimizer Direct Delivery
<IfModule mod_rewrite.c>
    RewriteEngine On

    # Serve AVIF if browser supports it and an .avif file exists
    RewriteCond %{HTTP_ACCEPT} image/avif
    RewriteCond %{REQUEST_FILENAME} ^(.+)\.(jpe?g|png|gif|webp|gif)$
    RewriteCond %{REQUEST_FILENAME}.avif -f
    RewriteRule ^(.+)\.(jpe?g|png|gif|webp|gif)$ $0.avif [T=image/avif,E=accept:1,L]

    # Serve WebP if browser supports it and a .webp file exists
    RewriteCond %{HTTP_ACCEPT} image/webp
    RewriteCond %{REQUEST_FILENAME} ^(.+)\.(jpe?g|png|gif)$
    RewriteCond %{REQUEST_FILENAME}.webp -f
    RewriteRule ^(.+)\.(jpe?g|png|gif)$ $0.webp [T=image/webp,E=accept:1,L]

    # Add Vary: Accept header so CDNs/proxies cache both variants separately
    Header append Vary Accept env=accept
</IfModule>
# END OptiForge Image Optimizer Direct Delivery
HTACCESS;
	}

	/**
	 * Return Nginx rewrite configuration directives.
	 *
	 * @return string
	 */
	public static function get_nginx_rules(): string {
		return <<<'NGINX'
# -----------------------------------------------------------------------
# OptiForge Image Optimizer — Nginx Configuration
# Add the map{} blocks to your http{} context (nginx.conf or conf.d/).
# Add the location{} block to your server{} context (site vhost config).
# -----------------------------------------------------------------------

map $http_accept $ofio_avif_suffix {
    default        "";
    "~*image/avif" ".avif";
}

map $http_accept $ofio_webp_suffix {
    default        "";
    "~*image/webp" ".webp";
}

location ~* ^(/wp-content/uploads/.+)\.(jpe?g|png|gif|webp|gif)$ {
    add_header Vary Accept;

    try_files
        ${uri}${ofio_avif_suffix}
        ${uri}${ofio_webp_suffix}
        $uri
        =404;
}
NGINX;
	}
}

// Backwards-compatible class aliases.
if ( ! class_exists( 'OFIO_Delivery', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Delivery', 'OFIO_Delivery' );
}
if ( ! class_exists( '\OptiForge\ImageOptimizer\Delivery', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Delivery', '\OptiForge\ImageOptimizer\Delivery' );
}
if ( ! class_exists( '\OptiForge_Image_Optimizer\Delivery', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Delivery', '\OptiForge_Image_Optimizer\Delivery' );
}