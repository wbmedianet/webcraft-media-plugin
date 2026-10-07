<?php
/**
 * GitHub API: latest releases and release downloads.
 *
 * Private repositories need an update key: a GitHub fine-grained personal access token
 * with read-only access to the repository contents, saved on the settings page or set as
 * WEBCRAFT_MEDIA_TOKEN in wp-config.php. Public repositories need no key.
 *
 * @package WebcraftMedia
 */

namespace WebcraftMedia;

defined( 'ABSPATH' ) || exit;

const GITHUB_API = 'https://api.github.com';

/**
 * Update key for private repositories ('' when there is none).
 */
function token(): string {
	if ( defined( 'WEBCRAFT_MEDIA_TOKEN' ) && is_string( WEBCRAFT_MEDIA_TOKEN ) ) {
		return trim( WEBCRAFT_MEDIA_TOKEN );
	}
	return trim( (string) get_option( 'webcraft_media_token', '' ) );
}

/**
 * GET request to the GitHub API.
 *
 * @param string $url  API URL.
 * @param bool   $auth Whether to send the update key.
 * @param array  $args Extra wp_remote_get() arguments.
 * @return array{code: int, body: string, location: string, error: string, rate_limited: bool}
 */
function github_request( string $url, bool $auth, array $args = array() ): array {
	$headers = array(
		'Accept'               => 'application/vnd.github+json',
		'X-GitHub-Api-Version' => '2022-11-28',
	);
	if ( $auth && '' !== token() ) {
		$headers['Authorization'] = 'Bearer ' . token();
	}
	$args['headers'] = array_merge( $headers, $args['headers'] ?? array() );
	$args           += array( 'timeout' => 15 );

	$response = wp_remote_get( $url, $args );
	if ( is_wp_error( $response ) ) {
		return array(
			'code'         => 0,
			'body'         => '',
			'location'     => '',
			'error'        => $response->get_error_message(),
			'rate_limited' => false,
		);
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	return array(
		'code'         => $code,
		'body'         => (string) wp_remote_retrieve_body( $response ),
		'location'     => (string) wp_remote_retrieve_header( $response, 'location' ),
		'error'        => '',
		'rate_limited' => 429 === $code || ( 403 === $code && '0' === (string) wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' ) ),
	);
}

/**
 * Latest release of a repository, or of one product in a repository that holds several
 * (there, each product's releases are tagged "{package}-v{version}").
 *
 * @param string $repo    Repository, "owner/name".
 * @param string $slug    Theme or plugin folder name; the release zip is "{slug}.zip".
 * @param string $package Product folder in a repository that holds several, or ''.
 * @return array {
 *     @type string $status  ok | no_key | invalid_key | no_access | no_release | error
 *     @type string $version Release version (the tag without its "v").
 *     @type string $package API address of the release zip.
 *     @type string $notes   Release notes as HTML.
 *     @type string $date    Publication date, ISO 8601.
 *     @type string $message Details for an error.
 * }
 */
function latest_release( string $repo, string $slug, string $package = '' ): array {
	// The newest 100 releases of a shared repository are enough to find each product's latest.
	$url   = GITHUB_API . '/repos/' . $repo . ( '' === $package ? '/releases/latest' : '/releases?per_page=100' );
	$args  = array( 'headers' => array( 'Accept' => 'application/vnd.github.full+json' ) );
	$keyed = '' !== token();

	$response = github_request( $url, $keyed, $args );
	// Public repositories need no key, whatever the key is for.
	if ( $keyed && in_array( $response['code'], array( 401, 403, 404 ), true ) && ! $response['rate_limited'] ) {
		$anonymous = github_request( $url, false, $args );
		if ( 200 === $anonymous['code'] ) {
			$response = $anonymous;
		}
	}

	if ( 200 === $response['code'] ) {
		$data = json_decode( $response['body'], true );
		return '' === $package ? release_from_api( $data, $slug ) : package_release( $data, $slug, $package );
	}
	if ( $response['rate_limited'] ) {
		return release_failure( 'error', __( 'GitHub is limiting requests from this server for now.', 'webcraft-media' ) );
	}
	if ( 0 === $response['code'] ) {
		return release_failure( 'error', $response['error'] );
	}
	if ( 401 === $response['code'] ) {
		return release_failure( 'invalid_key' );
	}
	if ( in_array( $response['code'], array( 403, 404 ), true ) ) {
		// No release published yet, or no access to the repository?
		$repo_url = GITHUB_API . '/repos/' . $repo;
		if ( 200 === github_request( $repo_url, $keyed )['code'] || ( $keyed && 200 === github_request( $repo_url, false )['code'] ) ) {
			return release_failure( 'no_release' );
		}
		return release_failure( $keyed ? 'no_access' : 'no_key' );
	}
	/* translators: %d: HTTP status code. */
	return release_failure( 'error', sprintf( __( 'GitHub answered with HTTP %d.', 'webcraft-media' ), $response['code'] ) );
}

/**
 * Newest release of one product, from the release list of a repository that holds several.
 *
 * @param mixed  $data    Decoded list of releases.
 * @param string $slug    Theme or plugin folder name.
 * @param string $package Product folder; its releases are tagged "{package}-v{version}".
 */
function package_release( $data, string $slug, string $package ): array {
	if ( ! is_array( $data ) ) {
		return release_failure( 'error', __( 'GitHub sent an answer that could not be read.', 'webcraft-media' ) );
	}
	$prefix  = $package . '-v';
	$newest  = null;
	$version = '';
	foreach ( $data as $release ) {
		$tag = (string) ( $release['tag_name'] ?? '' );
		if ( ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) || ! str_starts_with( $tag, $prefix ) ) {
			continue;
		}
		$candidate = substr( $tag, strlen( $prefix ) );
		if ( null === $newest || version_compare( $candidate, $version, '>' ) ) {
			$newest  = $release;
			$version = $candidate;
		}
	}
	return null === $newest ? release_failure( 'no_release' ) : release_from_api( $newest, $slug, $version );
}

/**
 * Release details from a GitHub API answer.
 *
 * @param mixed  $data    Decoded answer.
 * @param string $slug    Theme or plugin folder name.
 * @param string $version Version, when the tag is not just "v{version}".
 */
function release_from_api( $data, string $slug, string $version = '' ): array {
	if ( ! is_array( $data ) ) {
		return release_failure( 'error', __( 'GitHub sent an answer that could not be read.', 'webcraft-media' ) );
	}
	$package = '';
	foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
		$name = (string) ( $asset['name'] ?? '' );
		if ( str_ends_with( strtolower( $name ), '.zip' ) && ( '' === $package || $slug . '.zip' === $name ) ) {
			$package = (string) ( $asset['url'] ?? '' );
		}
	}
	if ( '' === $package ) {
		return release_failure( 'no_release', __( 'The latest release has no zip file.', 'webcraft-media' ) );
	}
	return array(
		'status'  => 'ok',
		'version' => '' !== $version ? $version : ltrim( (string) ( $data['tag_name'] ?? '' ), 'vV' ),
		'package' => $package,
		'notes'   => wp_kses_post( (string) ( $data['body_html'] ?? '' ) ),
		'date'    => (string) ( $data['published_at'] ?? '' ),
		'message' => '',
	);
}

/**
 * Release lookup result without a release.
 *
 * @param string $status  Status code, see latest_release().
 * @param string $message Details.
 */
function release_failure( string $status, string $message = '' ): array {
	return array(
		'status'  => $status,
		'version' => '',
		'package' => '',
		'notes'   => '',
		'date'    => '',
		'message' => $message,
	);
}

/**
 * Downloads a release zip to a temporary file. GitHub answers with a redirect to a
 * short-lived storage link, which is then fetched without the key.
 *
 * @param string $url API address of the release zip.
 * @return string|\WP_Error Path of the temporary file.
 */
function download_release( string $url ) {
	$args     = array(
		'headers'     => array( 'Accept' => 'application/octet-stream' ),
		'redirection' => 0,
	);
	$response = github_request( $url, true, $args );
	if ( '' === $response['location'] && '' !== token() ) {
		$response = github_request( $url, false, $args );
	}
	if ( '' === $response['location'] ) {
		return new \WP_Error(
			'webcraft_media_download',
			/* translators: %d: HTTP status code. */
			sprintf( __( 'GitHub did not provide the update file (HTTP %d).', 'webcraft-media' ), $response['code'] )
		);
	}
	if ( ! function_exists( 'download_url' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	return download_url( $response['location'], 300 );
}
