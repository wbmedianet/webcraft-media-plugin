<?php
/**
 * Site migrations without development files.
 *
 * A development copy of a theme or plugin keeps its git files in its folder. Copied to another
 * site by a migration plugin, the .git folder would make the copy there count as a development
 * copy too, so it would never be offered updates. All-in-One WP Migration is told to leave
 * those files out (its older versions filter the whole content folder, newer ones the plugins
 * and themes folders separately).
 *
 * @package WebcraftMedia
 */

namespace WebcraftMedia;

defined( 'ABSPATH' ) || exit;

/*
 * Files that only matter in a git working copy.
 */
const DEV_FILES = array( '.git', '.github', '.gitattributes', '.gitignore' );

/**
 * Development files of our products, as paths relative to a base folder.
 *
 * @param string $base Folder the paths are relative to.
 * @return string[]
 */
function dev_files_in( string $base ): array {
	$base  = trailingslashit( wp_normalize_path( $base ) );
	$paths = array();
	foreach ( products() as $product ) {
		$dir = trailingslashit( wp_normalize_path( $product['dir'] ) );
		if ( 0 !== strpos( $dir, $base ) ) {
			continue;
		}
		foreach ( DEV_FILES as $name ) {
			if ( file_exists( $dir . $name ) ) {
				$paths[] = str_replace( '/', DIRECTORY_SEPARATOR, substr( $dir, strlen( $base ) ) . $name );
			}
		}
	}
	return $paths;
}

add_filter(
	'ai1wm_exclude_content_from_export',
	static fn( $exclude ) => array_merge( (array) $exclude, dev_files_in( WP_CONTENT_DIR ) )
);

add_filter(
	'ai1wm_exclude_plugins_from_export',
	static fn( $exclude ) => array_merge( (array) $exclude, dev_files_in( WP_PLUGIN_DIR ) )
);

add_filter(
	'ai1wm_exclude_themes_from_export',
	static fn( $exclude ) => array_merge( (array) $exclude, dev_files_in( get_theme_root() ) )
);
