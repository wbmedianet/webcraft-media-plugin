<?php
/**
 * Updates for themes and plugins released on GitHub.
 *
 * A theme or plugin opts in with an "Update URI" header naming its repository:
 *
 *     Update URI: https://github.com/wbmedianet/moroccan-spirit
 *
 * At each update check WordPress asks for the latest release of that repository; when it
 * is newer than the installed version, the update is offered like any other and the
 * release zip is installed.
 *
 * @package WebcraftMedia
 */

namespace WebcraftMedia;

defined( 'ABSPATH' ) || exit;

/*
 * GitHub accounts whose themes and plugins are updated from here.
 */
const GITHUB_OWNERS = array( 'wbmedianet' );

/*
 * Check results that mean the update key is missing, wrong, expired or not meant for the product.
 */
const KEY_PROBLEMS = array( 'no_key', 'invalid_key', 'no_access' );

/**
 * GitHub accounts, lowercase.
 *
 * @return string[]
 */
function owners(): array {
	/**
	 * Filters the GitHub accounts whose themes and plugins are updated by this plugin.
	 *
	 * @param string[] $owners Account names.
	 */
	return array_map( 'strtolower', (array) apply_filters( 'webcraft_media_github_owners', GITHUB_OWNERS ) );
}

/**
 * Repository ("owner/name") named by an Update URI, or '' when it is not one of ours.
 *
 * @param string $uri Update URI header.
 */
function repo_from_uri( string $uri ): string {
	$parts = wp_parse_url( trim( $uri ) );
	if ( ! is_array( $parts ) || 'github.com' !== strtolower( $parts['host'] ?? '' ) ) {
		return '';
	}
	$path = explode( '/', trim( $parts['path'] ?? '', '/' ) );
	if ( count( $path ) < 2 || ! in_array( strtolower( $path[0] ), owners(), true ) ) {
		return '';
	}
	return $path[0] . '/' . preg_replace( '/\.git$/', '', $path[1] );
}

/**
 * Installed themes and plugins updated from GitHub, keyed "theme:{folder}" or "plugin:{file}".
 *
 * @return array<string, array{key: string, type: string, id: string, slug: string, name: string, version: string, repo: string, dir: string}>
 */
function products(): array {
	$products = array();
	foreach ( wp_get_themes() as $stylesheet => $theme ) {
		$repo = repo_from_uri( (string) $theme->get( 'UpdateURI' ) );
		if ( '' !== $repo ) {
			$products[ 'theme:' . $stylesheet ] = array(
				'key'     => 'theme:' . $stylesheet,
				'type'    => 'theme',
				'id'      => $stylesheet,
				'slug'    => $stylesheet,
				'name'    => (string) $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
				'repo'    => $repo,
				'dir'     => $theme->get_stylesheet_directory(),
			);
		}
	}

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	foreach ( get_plugins() as $file => $data ) {
		$repo   = repo_from_uri( (string) ( $data['UpdateURI'] ?? '' ) );
		$folder = dirname( $file );
		// Only plugins in their own folder: a release zip holds that folder.
		if ( '' !== $repo && '.' !== $folder ) {
			$products[ 'plugin:' . $file ] = array(
				'key'     => 'plugin:' . $file,
				'type'    => 'plugin',
				'id'      => $file,
				'slug'    => $folder,
				'name'    => (string) $data['Name'],
				'version' => (string) $data['Version'],
				'repo'    => $repo,
				'dir'     => WP_PLUGIN_DIR . '/' . $folder,
			);
		}
	}
	return $products;
}

/**
 * Whether a product is a development copy inside a git working copy. Installing a release
 * there would overwrite work that only git should change, so no update is offered.
 *
 * @param array $product Product, see products().
 */
function is_git_copy( array $product ): bool {
	/**
	 * Filters whether development copies kept in git are left out of updates.
	 *
	 * @param bool  $skip    Whether to leave git copies out. Default true.
	 * @param array $product Product, see products().
	 */
	if ( ! apply_filters( 'webcraft_media_skip_git_copies', true, $product ) ) {
		return false;
	}
	// From the product folder up to the folder that holds the WordPress folder.
	$dir  = wp_normalize_path( untrailingslashit( $product['dir'] ) );
	$stop = wp_normalize_path( dirname( ABSPATH, 2 ) );
	while ( strlen( $dir ) >= strlen( $stop ) ) {
		if ( file_exists( $dir . '/.git' ) ) {
			return true;
		}
		$parent = dirname( $dir );
		if ( $parent === $dir ) {
			break;
		}
		$dir = $parent;
	}
	return false;
}

/**
 * Last check result of each product, keyed like products().
 *
 * @return array<string, array{status: string, version: string, message: string, checked: int}>
 */
function checks(): array {
	return (array) get_option( 'webcraft_media_checks', array() );
}

/**
 * Latest release for a product, cached for an hour (15 minutes after a failed check).
 * Each fresh result is also kept for the settings page and the dashboard notice.
 *
 * @param array $product Product, see products().
 * @param bool  $fresh   Ask GitHub even when a cached answer exists.
 */
function product_release( array $product, bool $fresh = false ): array {
	$cache   = 'webcraft_media_' . md5( $product['repo'] . '|' . $product['slug'] );
	$release = $fresh ? false : get_site_transient( $cache );
	if ( is_array( $release ) ) {
		return $release;
	}

	$release = latest_release( $product['repo'], $product['slug'] );
	set_site_transient( $cache, $release, in_array( $release['status'], array( 'ok', 'no_release' ), true ) ? HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS );

	$checks                    = checks();
	$checks[ $product['key'] ] = array(
		'status'  => $release['status'],
		'version' => $release['version'],
		'message' => $release['message'],
		'checked' => time(),
	);
	update_option( 'webcraft_media_checks', $checks, false );
	return $release;
}

/**
 * Whether updates are off because the update key is missing, wrong or expired.
 */
function updates_inactive(): bool {
	$checks = checks();
	foreach ( array_keys( products() ) as $key ) {
		if ( in_array( $checks[ $key ]['status'] ?? '', KEY_PROBLEMS, true ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Checks every product now and lets WordPress list the updates found.
 */
function check_now(): void {
	foreach ( products() as $product ) {
		product_release( $product, true );
	}
	delete_site_transient( 'update_themes' );
	delete_site_transient( 'update_plugins' );
	wp_update_themes();
	wp_update_plugins();
}

/**
 * Page with the release notes of a product's latest version.
 *
 * @param string $key Product key, see products().
 */
function details_url( string $key ): string {
	return add_query_arg(
		array(
			'action'  => 'webcraft_media_details',
			'product' => rawurlencode( $key ),
		),
		admin_url( 'admin-post.php' )
	);
}

/**
 * Update offered to WordPress for a product, or false when there is none.
 *
 * @param mixed  $update Answer from another handler of the same host.
 * @param string $key    Product key, see products().
 * @return array|false|mixed
 */
function update_offer( $update, string $key ) {
	if ( $update ) {
		return $update;
	}
	$products = products();
	if ( ! isset( $products[ $key ] ) ) {
		return $update;
	}
	$product = $products[ $key ];
	$release = product_release( $product );
	if ( 'ok' !== $release['status'] || is_git_copy( $product ) ) {
		return false;
	}

	$offer = array(
		'version' => $release['version'],
		'package' => $release['package'],
	);
	if ( 'theme' === $product['type'] ) {
		$offer['theme'] = $product['id'];
		$offer['url']   = details_url( $key );
	} else {
		$offer['slug']   = $product['slug'];
		$offer['plugin'] = $product['id'];
		$offer['url']    = CONTACT_URL;
	}
	return $offer;
}

add_filter(
	'update_themes_github.com',
	static fn( $update, $theme_data, $stylesheet ) => update_offer( $update, 'theme:' . $stylesheet ),
	10,
	3
);

add_filter(
	'update_plugins_github.com',
	static fn( $update, $plugin_data, $file ) => update_offer( $update, 'plugin:' . $file ),
	10,
	3
);

/**
 * Whether a package address is a release zip of one of our repositories.
 *
 * @param string $url Package address.
 */
function is_release_package( string $url ): bool {
	if ( ! preg_match( '#^' . preg_quote( GITHUB_API, '#' ) . '/repos/([^/]+)/[^/]+/releases/assets/\d+$#', $url, $match ) ) {
		return false;
	}
	return in_array( strtolower( $match[1] ), owners(), true );
}

/*
 * Release zips are downloaded through the GitHub API, with the update key.
 */
add_filter(
	'upgrader_pre_download',
	static function ( $reply, $package ) {
		if ( false !== $reply || ! is_string( $package ) || ! is_release_package( $package ) ) {
			return $reply;
		}
		return download_release( $package );
	},
	10,
	2
);

/*
 * The zip's top folder becomes the theme or plugin folder. A zip using another name is
 * renamed, so the update replaces the installed copy instead of installing a second one.
 */
add_filter(
	'upgrader_source_selection',
	static function ( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( is_wp_error( $source ) || ! is_array( $hook_extra ) ) {
			return $source;
		}
		if ( isset( $hook_extra['theme'] ) ) {
			$key = 'theme:' . $hook_extra['theme'];
		} elseif ( isset( $hook_extra['plugin'] ) ) {
			$key = 'plugin:' . $hook_extra['plugin'];
		} else {
			return $source;
		}
		$products = products();
		if ( ! isset( $products[ $key ] ) ) {
			return $source;
		}

		$wanted = trailingslashit( wp_normalize_path( $remote_source ) ) . $products[ $key ]['slug'] . '/';
		if ( trailingslashit( wp_normalize_path( $source ) ) === $wanted ) {
			return $source;
		}
		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->move( $source, $wanted ) ) {
			return $wanted;
		}
		return new \WP_Error( 'webcraft_media_folder', __( 'The update could not be prepared for installation.', 'webcraft-media' ) );
	},
	10,
	4
);

/*
 * "View details" for plugins: name, version and release notes.
 */
add_filter(
	'plugins_api',
	static function ( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $result;
		}
		foreach ( products() as $product ) {
			if ( 'plugin' !== $product['type'] || $product['slug'] !== $args->slug ) {
				continue;
			}
			$release = product_release( $product );
			return (object) array(
				'name'          => $product['name'],
				'slug'          => $product['slug'],
				'version'       => '' !== $release['version'] ? $release['version'] : $product['version'],
				'author'        => '<a href="' . esc_url( CONTACT_URL ) . '">Webcraft Media</a>',
				'homepage'      => CONTACT_URL,
				'last_updated'  => $release['date'],
				'download_link' => $release['package'],
				'sections'      => array(
					'changelog' => '' !== $release['notes'] ? $release['notes'] : '<p>' . esc_html__( 'There are no release notes for this version.', 'webcraft-media' ) . '</p>',
				),
			);
		}
		return $result;
	},
	10,
	3
);
