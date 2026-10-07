<?php
/**
 * Admin: Settings → Webcraft Media (update key, update status), the dashboard notice
 * when updates are not active, and the release notes view.
 *
 * @package WebcraftMedia
 */

namespace WebcraftMedia;

defined( 'ABSPATH' ) || exit;

const PAGE = 'webcraft-media';

/**
 * Settings page address.
 */
function settings_url(): string {
	return admin_url( 'options-general.php?page=' . PAGE );
}

/**
 * Key shown without revealing it: "github_pat_…a3F9".
 *
 * @param string $key Update key.
 */
function masked_key( string $key ): string {
	if ( strlen( $key ) <= 12 ) {
		return '••••••••';
	}
	$prefix = preg_match( '/^(github_pat_|ghp_)/', $key, $match ) ? $match[1] : '';
	return $prefix . '…' . substr( $key, -4 );
}

add_action(
	'admin_menu',
	static function () {
		add_options_page( 'Webcraft Media', 'Webcraft Media', 'manage_options', PAGE, __NAMESPACE__ . '\render_settings_page' );
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( PLUGIN_FILE ),
	static function ( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( settings_url() ) . '">' . esc_html__( 'Settings', 'webcraft-media' ) . '</a>' );
		return $links;
	}
);

add_action(
	'admin_init',
	static function () {
		register_setting(
			PAGE,
			'webcraft_media_token',
			array(
				'type'              => 'string',
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => __NAMESPACE__ . '\sanitize_key_field',
			)
		);
		register_setting(
			PAGE,
			'webcraft_media_notice',
			array(
				'type'              => 'string',
				'default'           => '1',
				'show_in_rest'      => false,
				'sanitize_callback' => static fn( $value ) => $value ? '1' : '0',
			)
		);
	}
);

/**
 * Update key from the settings form: an empty field keeps the saved key, "Remove the
 * saved key" clears it.
 *
 * @param mixed $value Submitted value.
 */
function sanitize_key_field( $value ): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php checked the nonce.
	if ( ! empty( $_POST['webcraft_media_token_remove'] ) ) {
		return '';
	}
	$value = preg_replace( '/\s+/', '', (string) $value );
	return '' === $value ? (string) get_option( 'webcraft_media_token', '' ) : $value;
}

/**
 * Tries a new key at once; the result replaces "Settings saved.".
 */
function key_saved(): void {
	check_now();
	if ( '' === token() ) {
		add_settings_error( PAGE, 'webcraft_media_key', __( 'The update key was removed.', 'webcraft-media' ), 'info' );
	} elseif ( updates_inactive() ) {
		add_settings_error( PAGE, 'webcraft_media_key', __( 'The key was saved, but GitHub does not accept it for these products. Check that it was copied whole, or contact Webcraft Media.', 'webcraft-media' ), 'error' );
	} else {
		add_settings_error( PAGE, 'webcraft_media_key', __( 'The key works: updates are active.', 'webcraft-media' ), 'success' );
	}
}

add_action( 'add_option_webcraft_media_token', __NAMESPACE__ . '\key_saved' );
add_action( 'update_option_webcraft_media_token', __NAMESPACE__ . '\key_saved' );

add_action(
	'admin_post_webcraft_media_check',
	static function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'webcraft-media' ), 403 );
		}
		check_admin_referer( 'webcraft_media_check' );
		check_now();
		wp_safe_redirect( add_query_arg( 'checked', '1', settings_url() ) );
		exit;
	}
);

/**
 * Status of one product for the settings page.
 *
 * @param array $product Product, see products().
 * @param array $check   Last check result, see checks().
 */
function product_status( array $product, array $check ): string {
	$status = $check['status'] ?? '';
	if ( 'ok' === $status && is_git_copy( $product ) ) {
		return __( 'Development copy (kept in git): updates are not installed here.', 'webcraft-media' );
	}
	switch ( $status ) {
		case 'ok':
			if ( version_compare( $check['version'], $product['version'], '>' ) ) {
				/* translators: %s: link to Dashboard → Updates. */
				return sprintf( __( 'A new version is available in %s.', 'webcraft-media' ), '<a href="' . esc_url( self_admin_url( 'update-core.php' ) ) . '">' . esc_html__( 'Dashboard → Updates', 'webcraft-media' ) . '</a>' );
			}
			return __( 'Up to date.', 'webcraft-media' );
		case 'no_key':
			return __( 'No update key.', 'webcraft-media' );
		case 'invalid_key':
			return __( 'The update key is not valid or has expired.', 'webcraft-media' );
		case 'no_access':
			return __( 'The update key is not meant for this product.', 'webcraft-media' );
		case 'no_release':
			return __( 'No version has been published yet.', 'webcraft-media' );
		case 'error':
			/* translators: %s: error details. */
			return sprintf( __( 'GitHub could not be reached: %s', 'webcraft-media' ), esc_html( $check['message'] ) );
		default:
			return __( 'Not checked yet.', 'webcraft-media' );
	}
}

/**
 * Settings → Webcraft Media.
 */
function render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$products = products();
	$checks   = checks();
	$key      = token();
	$in_code  = defined( 'WEBCRAFT_MEDIA_TOKEN' );
	?>
	<div class="wrap">
		<h1>Webcraft Media</h1>

		<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only. ?>
		<?php if ( isset( $_GET['checked'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Checked for updates just now.', 'webcraft-media' ); ?></p></div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Updates', 'webcraft-media' ); ?></h2>
		<?php if ( ! $products ) : ?>
			<p><?php esc_html_e( 'No theme or plugin on this site is updated by Webcraft Media.', 'webcraft-media' ); ?></p>
		<?php else : ?>
			<p>
				<?php
				if ( updates_inactive() ) {
					printf(
						/* translators: %s: link to the Webcraft Media website. */
						esc_html__( 'Updates are not active. To turn them back on, contact %s.', 'webcraft-media' ),
						'<a href="' . esc_url( CONTACT_URL ) . '">Webcraft Media</a>'
					);
				} else {
					esc_html_e( 'New versions of these themes and plugins appear in Dashboard → Updates, like any other update.', 'webcraft-media' );
				}
				?>
			</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Theme or plugin', 'webcraft-media' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Installed', 'webcraft-media' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Latest', 'webcraft-media' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'webcraft-media' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last check', 'webcraft-media' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $products as $product_key => $product ) : ?>
						<?php $check = $checks[ $product_key ] ?? array(); ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $product['name'] ); ?></strong><br>
								<?php echo esc_html( 'theme' === $product['type'] ? __( 'Theme', 'webcraft-media' ) : __( 'Plugin', 'webcraft-media' ) ); ?>
							</td>
							<td><?php echo esc_html( $product['version'] ); ?></td>
							<td>
								<?php if ( ! empty( $check['version'] ) ) : ?>
									<a href="<?php echo esc_url( details_url( $product_key ) ); ?>"><?php echo esc_html( $check['version'] ); ?></a>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
							<td><?php echo wp_kses_post( product_status( $product, $check ) ); ?></td>
							<td>
								<?php
								echo empty( $check['checked'] )
									? '—'
									/* translators: %s: time span, e.g. "5 mins". */
									: esc_html( sprintf( __( '%s ago', 'webcraft-media' ), human_time_diff( (int) $check['checked'] ) ) );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="webcraft_media_check">
				<?php wp_nonce_field( 'webcraft_media_check' ); ?>
				<?php submit_button( __( 'Check now', 'webcraft-media' ), 'secondary', 'submit', true ); ?>
			</form>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php settings_fields( PAGE ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="webcraft-media-key"><?php esc_html_e( 'Update key', 'webcraft-media' ); ?></label></th>
					<td>
						<?php if ( $in_code ) : ?>
							<p><?php esc_html_e( 'The update key is set in wp-config.php.', 'webcraft-media' ); ?></p>
						<?php else : ?>
							<input type="password" id="webcraft-media-key" name="webcraft_media_token" class="regular-text code" value="" autocomplete="off" spellcheck="false" placeholder="<?php echo esc_attr( '' !== $key ? masked_key( $key ) : 'github_pat_…' ); ?>">
							<p class="description">
								<?php esc_html_e( 'Webcraft Media gives you this key with your maintenance plan. Paste it here and save.', 'webcraft-media' ); ?>
								<?php if ( '' !== $key ) : ?>
									<?php
									/* translators: %s: the saved key, partly hidden. */
									echo esc_html( sprintf( __( 'Saved key: %s. Leave the field empty to keep it.', 'webcraft-media' ), masked_key( $key ) ) );
									?>
								<?php endif; ?>
							</p>
							<?php if ( '' !== $key ) : ?>
								<p><label><input type="checkbox" name="webcraft_media_token_remove" value="1"> <?php esc_html_e( 'Remove the saved key', 'webcraft-media' ); ?></label></p>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Notice', 'webcraft-media' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="webcraft_media_notice" value="1" <?php checked( '0' !== (string) get_option( 'webcraft_media_notice', '1' ) ); ?>>
							<?php esc_html_e( 'Show a notice in the dashboard when updates are not active', 'webcraft-media' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/*
 * Notice on the Dashboard, Updates, Themes and Plugins screens while updates are not active.
 */
add_action(
	'admin_notices',
	static function () {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'update-core', 'themes', 'plugins' ), true ) ) {
			return;
		}
		if ( ! current_user_can( 'update_themes' ) && ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		if ( '0' === (string) get_option( 'webcraft_media_notice', '1' ) || ! updates_inactive() ) {
			return;
		}
		$details = current_user_can( 'manage_options' )
			? ' <a href="' . esc_url( settings_url() ) . '">' . esc_html__( 'Details', 'webcraft-media' ) . '</a>'
			: '';
		printf(
			'<div class="notice notice-warning"><p>%s%s</p></div>',
			sprintf(
				/* translators: %s: link to the Webcraft Media website. */
				esc_html__( 'Updates are not active. To turn them back on, contact %s.', 'webcraft-media' ),
				'<a href="' . esc_url( CONTACT_URL ) . '">Webcraft Media</a>'
			),
			$details // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		);
	}
);

/*
 * Release notes of a product's latest version (opened from "View version details").
 */
add_action(
	'admin_post_webcraft_media_details',
	static function () {
		if ( ! current_user_can( 'update_themes' ) && ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'webcraft-media' ), 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$key      = sanitize_text_field( wp_unslash( $_GET['product'] ?? '' ) );
		$products = products();
		if ( ! isset( $products[ $key ] ) ) {
			wp_die( esc_html__( 'This theme or plugin is not updated by Webcraft Media.', 'webcraft-media' ), 404 );
		}
		$product = $products[ $key ];
		$release = product_release( $product );

		iframe_header( $product['name'] );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $product['name'] . ( '' !== $release['version'] ? ' ' . $release['version'] : '' ) ); ?></h1>
			<?php if ( '' !== $release['date'] ) : ?>
				<p><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $release['date'] ) ) ); ?></p>
			<?php endif; ?>
			<?php
			echo '' !== $release['notes']
				? wp_kses_post( $release['notes'] )
				: '<p>' . esc_html__( 'There are no release notes for this version.', 'webcraft-media' ) . '</p>';
			?>
		</div>
		<?php
		iframe_footer();
		exit;
	}
);
