<?php

namespace Beplus\ScssCompiler\Settings;

use Beplus\ScssCompiler\Scanner;

/**
 * @phpstan-type ScssSettings array{scss_dir:string, css_dir:string, compile_mode:'auto'|'manual', source_map:bool, minify:bool, web_root:bool}
 * @phpstan-type ScssSettingsInput array<string, mixed>
 */
final class SettingsPage {

	const OPTION_NAME    = 'beplus_scss_settings';
	const MENU_SLUG      = 'beplus-scss';
	const COMPILE_ACTION = 'beplus_scss_compile';
	const NONCE_FIELD    = 'beplus_scss_compile_nonce';

	/**
	 * @return ScssSettings
	 */
	private static function defaults(): array {
		return [
			'scss_dir'     => '',
			'css_dir'      => '',
			'compile_mode' => 'auto',
			'source_map'   => false,
			'minify'       => false,
			'web_root'     => false,
		];
	}

	/**
	 * @return ScssSettings
	 */
	public static function currentSettings(): array {
		$stored = get_option( self::OPTION_NAME, [] );
		$stored = is_array( $stored ) ? $stored : [];

		return [
			'scss_dir'     => isset( $stored['scss_dir'] ) && is_string( $stored['scss_dir'] ) ? $stored['scss_dir'] : '',
			'css_dir'      => isset( $stored['css_dir'] ) && is_string( $stored['css_dir'] ) ? $stored['css_dir'] : '',
			'compile_mode' => 'manual' === ( $stored['compile_mode'] ?? '' ) ? 'manual' : 'auto',
			'source_map'   => (bool) ( $stored['source_map'] ?? false ),
			'minify'       => (bool) ( $stored['minify'] ?? false ),
			'web_root'     => (bool) ( $stored['web_root'] ?? false ),
		];
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'registerMenu' ] );
		add_action( 'admin_init', [ $this, 'registerSettings' ] );
	}

	public function registerMenu(): void {
		add_menu_page(
			__( 'Beplus SCSS', 'beplus-scss' ),
			__( 'Beplus SCSS', 'beplus-scss' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'renderPage' ]
		);
	}

	public function registerSettings(): void {
		register_setting(
			'beplus_scss_settings_group',
			self::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
			]
		);
	}

	public function renderPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		/** @var string $rawMsg */
		$rawMsg = isset( $_GET['msg'] ) && is_scalar( $_GET['msg'] ) ? (string) $_GET['msg'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag from the wp_safe_redirect() query args; no state change happens here.
		$msg    = sanitize_key( $rawMsg );
		if ( 'compiled' === $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'SCSS compiled successfully.', 'beplus-scss' ) . '</p></div>';
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Beplus SCSS Compiler', 'beplus-scss' ) . '</h1>';
		echo '<form action="options.php" method="post">';
		settings_fields( 'beplus_scss_settings_group' );
		$this->renderFields( self::currentSettings() );
		submit_button();
		echo '</form>';
		echo '<hr>';
		$this->renderCompileNow();
		echo '</div>';
	}

	/**
	 * @param ScssSettings $settings
	 */
	public function renderFields( array $settings ): void {
		/** @var ScssSettings $values */
		$values = wp_parse_args( $settings, self::defaults() );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="beplus_scss_scss_dir"><?php esc_html_e( 'SCSS source directory', 'beplus-scss' ); ?></label></th>
				<td><input type="text" class="regular-text code" id="beplus_scss_scss_dir" name="beplus_scss_settings[scss_dir]" value="<?php echo esc_attr( $values['scss_dir'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="beplus_scss_css_dir"><?php esc_html_e( 'CSS destination directory', 'beplus-scss' ); ?></label></th>
				<td><input type="text" class="regular-text code" id="beplus_scss_css_dir" name="beplus_scss_settings[css_dir]" value="<?php echo esc_attr( $values['css_dir'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Compile mode', 'beplus-scss' ); ?></th>
				<td>
					<fieldset>
						<label><input type="radio" name="beplus_scss_settings[compile_mode]" value="auto" <?php checked( 'auto', $values['compile_mode'] ); ?> /> <?php esc_html_e( 'Auto (recompile on change)', 'beplus-scss' ); ?></label><br>
						<label><input type="radio" name="beplus_scss_settings[compile_mode]" value="manual" <?php checked( 'manual', $values['compile_mode'] ); ?> /> <?php esc_html_e( 'Manual (compile via button)', 'beplus-scss' ); ?></label>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Source maps', 'beplus-scss' ); ?></th>
				<td><label><input type="checkbox" name="beplus_scss_settings[source_map]" value="1" <?php checked( true, (bool) $values['source_map'] ); ?> /> <?php esc_html_e( 'Emit .css.map files', 'beplus-scss' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Minify', 'beplus-scss' ); ?></th>
				<td><label><input type="checkbox" name="beplus_scss_settings[minify]" value="1" <?php checked( true, (bool) $values['minify'] ); ?> /> <?php esc_html_e( 'Compact output', 'beplus-scss' ); ?></label></td>
			</tr>
		</table>
		<?php
	}

	public function renderCompileNow(): void {
		$url = admin_url( 'admin-post.php' );
		?>
		<form method="get" action="<?php echo esc_url( $url ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::COMPILE_ACTION ); ?>" />
			<?php wp_nonce_field( self::NONCE_FIELD ); ?>
			<input type="hidden" name="msg_base" value="admin" />
			<?php submit_button( __( 'Compile SCSS now', 'beplus-scss' ), 'secondary', 'compile-now', false ); ?>
		</form>
		<?php
	}

	/**
	 * @param ScssSettingsInput $input
	 * @return ScssSettings
	 */
	public function sanitize( array $input ): array {
		/** @var ScssSettings $output */
		$output = wp_parse_args( self::currentSettings(), self::defaults() );

		if ( isset( $input['scss_dir'] ) && is_string( $input['scss_dir'] ) ) {
			$scss      = $this->normalizePath( $input['scss_dir'] );
			$validated = $this->validateScssDir( $scss );
			if ( true === $validated ) {
				$output['scss_dir'] = $scss;
			} else {
				add_settings_error( self::OPTION_NAME, 'bad_scss_dir', $validated );
			}
		}

		if ( isset( $input['css_dir'] ) && is_string( $input['css_dir'] ) ) {
			$css       = $this->normalizePath( $input['css_dir'] );
			$validated = $this->validateCssDir( $css );
			if ( true === $validated ) {
				$output['css_dir']  = $css;
				$output['web_root'] = 0 === strpos( $css . '/', ABSPATH );
			} else {
				add_settings_error( self::OPTION_NAME, 'bad_css_dir', $validated );
			}
		}

		if ( isset( $input['compile_mode'] ) && in_array( $input['compile_mode'], [ 'auto', 'manual' ], true ) ) {
			$output['compile_mode'] = $input['compile_mode'];
		}

		$output['source_map'] = ! empty( $input['source_map'] );
		$output['minify']     = ! empty( $input['minify'] );

		return $output;
	}

	private function normalizePath( string $path ): string {
		$path = trim( $path );
		if ( '' === $path ) {
			return '';
		}
		$path = wp_normalize_path( $path );
		$path = rtrim( $path, '/' );

		return $path;
	}

	/**
	 * @return true|string True when valid, else an error message.
	 */
	private function validateScssDir( string $path ) {
		if ( '' === $path ) {
			return true;
		}
		if ( ! is_dir( $path ) ) {
			return __( 'SCSS directory does not exist.', 'beplus-scss' );
		}
		if ( ! is_readable( $path ) ) {
			return __( 'SCSS directory is not readable.', 'beplus-scss' );
		}
		if ( 0 < count( Scanner::scan( $path ) ) ) {
			return true;
		}

		return __( 'SCSS directory contains no entries (non-partial .scss files).', 'beplus-scss' );
	}

	/**
	 * @return true|string True when valid, else an error message.
	 */
	private function validateCssDir( string $path ) {
		if ( '' === $path ) {
			return true;
		}
		if ( ! is_dir( $path ) ) {
			return __( 'CSS directory does not exist.', 'beplus-scss' );
		}
		if ( ! is_writable( $path ) ) {
			return __( 'CSS directory is not writable.', 'beplus-scss' );
		}

		return true;
	}
}