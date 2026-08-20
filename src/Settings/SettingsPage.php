<?php

namespace Beplus\ScssCompiler\Settings;

use Beplus\ScssCompiler\Scanner;

/**
 * @phpstan-type ScssSettings array{scss_dir:string, css_dir:string, compile_mode:'auto'|'manual', source_map:bool, minify:bool, enqueue:bool}
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
			'enqueue'      => false,
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
			'enqueue'      => (bool) ( $stored['enqueue'] ?? false ),
		];
	}

	/**
	 * Resolve a stored theme-relative path against the active theme directory.
	 */
	public static function absPath( string $relative ): string {
		return get_stylesheet_directory() . '/' . ltrim( $relative, '/' );
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'registerMenu' ] );
		add_action( 'admin_init', [ $this, 'registerSettings' ] );
	}

	public function registerMenu(): void {
		add_submenu_page(
			'options-general.php',
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
		$rawMsg   = isset( $_GET['msg'] ) && is_scalar( $_GET['msg'] ) ? (string) $_GET['msg'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag from the wp_safe_redirect() query args; no state change happens here.
		$msg      = sanitize_key( $rawMsg );
		$version  = get_option( 'beplus_scss_version', '' );
		$version  = is_string( $version ) ? $version : '';
		$settings = self::currentSettings();
		?>
		<div class="wrap beplus-wrap">
			<style>
			.beplus-wrap{ --bp-indigo:#4f46e5; --bp-purple:#a855f7; --bp-border:#e2e8f0; --bp-ink:#0f172a; --bp-muted:#64748b; }
			.beplus-wrap *{ box-sizing:border-box; }
			.beplus-hero{ background:linear-gradient(135deg,var(--bp-indigo),var(--bp-purple)); border-radius:16px; padding:26px; margin-bottom:20px; box-shadow:0 12px 28px rgba(99,102,241,.22); }
			.beplus-hero-top{ display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
			.beplus-hero-icon{ width:44px; height:44px; border-radius:12px; background:rgba(255,255,255,.16); display:flex; align-items:center; justify-content:center; }
			.beplus-hero-icon.dashicons{ width:44px; height:44px; font-size:26px; line-height:44px; color:#fff; }
			.beplus-hero-text{ flex:1; min-width:200px; }
			.beplus-hero-title{ margin:0; font-size:20px; font-weight:600; line-height:1.3; color:#fff; }
			.beplus-hero-sub{ margin:4px 0 0; font-size:13px; color:rgba(255,255,255,.85); }
			.beplus-hero-chips{ display:flex; gap:8px; align-items:center; }
			.beplus-chip{ background:rgba(255,255,255,.16); border:1px solid rgba(255,255,255,.35); border-radius:999px; padding:3px 11px; font-size:11px; font-weight:600; color:#fff; white-space:nowrap; display:inline-flex; align-items:center; gap:4px; }
			.beplus-chip .dashicons{ width:14px; height:14px; font-size:14px; line-height:14px; }
			.beplus-chip-active{ background:#22c55e; border-color:#22c55e; animation:beplus-pulse 2s infinite; }
			@keyframes beplus-pulse{ 0%,100%{ box-shadow:0 0 0 0 rgba(34,197,94,.45); } 50%{ box-shadow:0 0 0 6px rgba(34,197,94,0); } }
			.beplus-toast{ display:flex; align-items:center; gap:10px; border-radius:12px; padding:12px 16px; margin-bottom:20px; font-size:13px; font-weight:500; border:1px solid; animation:beplus-toast-in .25s ease; }
			@keyframes beplus-toast-in{ from{ opacity:0; transform:translateY(-6px); } to{ opacity:1; transform:none; } }
			.beplus-toast .dashicons{ width:20px; height:20px; font-size:20px; line-height:20px; }
			.beplus-toast-success{ background:#f0fdf4; border-color:#bbf7d0; color:#166534; }
			.beplus-toast-error{ background:#fef2f2; border-color:#fecaca; color:#991b1b; }
			.beplus-stats{ display:flex; gap:12px; margin-bottom:20px; }
			.beplus-stat{ flex:1; min-width:0; background:#fff; border:1px solid var(--bp-border); border-radius:12px; padding:14px 16px; display:flex; gap:12px; align-items:center; }
			.beplus-stat-icon{ width:36px; height:36px; border-radius:10px; background:#eef2ff; color:var(--bp-indigo); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
			.beplus-stat-icon.dashicons{ width:36px; height:36px; font-size:18px; line-height:36px; }
			.beplus-stat-label{ display:block; font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:var(--bp-muted); }
			.beplus-stat-value{ display:block; font-size:13px; font-weight:600; color:var(--bp-ink); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
			.beplus-grid{ display:flex; gap:20px; align-items:flex-start; }
			.beplus-col-main{ flex:1; min-width:0; }
			.beplus-col-side{ width:340px; flex-shrink:0; position:sticky; top:48px; }
			@media (max-width:959px){ .beplus-grid{ flex-direction:column; } .beplus-col-side{ width:100%; position:static; } }
			.beplus-card{ background:#fff; border:1px solid var(--bp-border); border-radius:16px; padding:24px; }
			.beplus-card-title{ display:flex; align-items:center; gap:8px; margin:0 0 18px; font-size:15px; font-weight:600; color:var(--bp-ink); }
			.beplus-card-title .dashicons{ color:var(--bp-indigo); }
			.beplus-field{ margin-bottom:20px; }
			.beplus-field-label{ display:block; margin-bottom:6px; font-size:13px; font-weight:600; color:var(--bp-ink); }
			.beplus-field input[type="text"]{ width:100%; max-width:100%; margin:0; border:1.5px solid var(--bp-border); border-radius:10px; padding:9px 12px; font-size:13px; color:var(--bp-ink); background:#fff; box-shadow:none; }
			.beplus-field input[type="text"]:focus{ border-color:var(--bp-purple); box-shadow:0 0 0 3px rgba(168,85,247,.16); outline:none; }
			.beplus-hint{ margin:6px 0 0; font-size:12px; color:var(--bp-muted); }
			.beplus-segment{ margin:0 0 20px; padding:0; border:0; }
			.beplus-segment .beplus-field-label{ margin-bottom:6px; }
			.beplus-segment-inner{ display:flex; gap:4px; padding:4px; background:#f1f5f9; border-radius:10px; }
			.beplus-segment input{ position:absolute; opacity:0; pointer-events:none; }
			.beplus-segment label{ flex:1; display:flex; align-items:center; justify-content:center; gap:6px; padding:8px 12px; border:1px solid transparent; border-radius:8px; font-size:13px; font-weight:600; color:var(--bp-muted); cursor:pointer; }
			.beplus-segment input:checked + label{ background:#eef2ff; border-color:var(--bp-purple); color:var(--bp-indigo); }
			.beplus-segment input:focus-visible + label{ box-shadow:0 0 0 3px rgba(168,85,247,.25); }
			.beplus-segment .beplus-hint{ margin-top:8px; }
			.beplus-toggle{ display:flex; align-items:center; gap:12px; margin-bottom:14px; cursor:pointer; }
			.beplus-toggle input{ position:absolute; opacity:0; pointer-events:none; }
			.beplus-toggle-track{ position:relative; width:44px; height:24px; flex-shrink:0; border-radius:999px; background:#cbd5e1; transition:background .2s; }
			.beplus-toggle-track::after{ content:""; position:absolute; top:2px; left:2px; width:20px; height:20px; border-radius:50%; background:#fff; box-shadow:0 1px 2px rgba(0,0,0,.2); transition:transform .2s; }
			.beplus-toggle input:checked + .beplus-toggle-track{ background:var(--bp-purple); }
			.beplus-toggle input:checked + .beplus-toggle-track::after{ transform:translateX(20px); }
			.beplus-toggle input:focus-visible + .beplus-toggle-track{ box-shadow:0 0 0 3px rgba(168,85,247,.3); }
			.beplus-toggle-text strong{ display:block; font-size:13px; font-weight:600; color:var(--bp-ink); }
			.beplus-toggle-text small{ display:block; font-size:12px; color:var(--bp-muted); }
			.beplus-btn-save{ background:linear-gradient(135deg,var(--bp-indigo),var(--bp-purple))!important; color:#fff!important; border:0!important; border-radius:10px!important; padding:8px 22px!important; font-weight:600!important; box-shadow:none!important; }
			.beplus-btn-save:hover{ filter:brightness(1.08); }
			.beplus-btn-compile{ background:linear-gradient(135deg,var(--bp-indigo),var(--bp-purple))!important; color:#fff!important; border:0!important; border-radius:10px!important; padding:10px 24px!important; font-weight:600!important; width:100%!important; text-align:center!important; box-shadow:none!important; transition:transform .15s ease, box-shadow .15s ease; }
			.beplus-btn-compile:hover{ transform:translateY(-1px); box-shadow:0 8px 18px rgba(99,102,241,.35)!important; }
			.beplus-tip{ display:flex; gap:10px; margin-top:16px; padding:14px 16px; background:#fffbeb; border:1px solid #fde68a; border-radius:12px; font-size:12.5px; line-height:1.5; color:#92400e; }
			.beplus-tip .dashicons{ flex-shrink:0; color:#f59e0b; }
			</style>

			<div class="beplus-hero">
				<div class="beplus-hero-top">
					<span class="beplus-hero-icon dashicons dashicons-editor-code" aria-hidden="true"></span>
					<div class="beplus-hero-text">
						<h1 class="beplus-hero-title"><?php echo esc_html( __( 'Beplus SCSS Compiler', 'beplus-scss' ) ); ?></h1>
						<p class="beplus-hero-sub"><?php echo esc_html( __( 'Declare your SCSS source and CSS destination directories and let the plugin handle the rest.', 'beplus-scss' ) ); ?></p>
					</div>
					<div class="beplus-hero-chips">
					<?php if ( '' !== $version ) : ?>
						<?php /* translators: %s: Plugin version number. */ ?>
						<span class="beplus-chip"><?php echo esc_html( sprintf( __( 'v%s', 'beplus-scss' ), $version ) ); ?></span>
					<?php endif; ?>
						<span class="beplus-chip beplus-chip-active"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php echo esc_html( __( 'Active', 'beplus-scss' ) ); ?></span>
					</div>
				</div>
			</div>

			<?php if ( 'compiled' === $msg ) : ?>
				<div class="beplus-toast beplus-toast-success" role="status">
					<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<span><?php echo esc_html( __( 'SCSS compiled successfully.', 'beplus-scss' ) ); ?></span>
				</div>
			<?php elseif ( 'error' === $msg ) : ?>
				<div class="beplus-toast beplus-toast-error" role="alert">
					<span class="dashicons dashicons-warning" aria-hidden="true"></span>
					<span><?php echo esc_html( __( 'Compilation failed. Check your SCSS sources and try again.', 'beplus-scss' ) ); ?></span>
				</div>
			<?php endif; ?>

			<div class="beplus-stats">
				<div class="beplus-stat">
					<span class="beplus-stat-icon dashicons dashicons-update" aria-hidden="true"></span>
					<div>
						<span class="beplus-stat-label"><?php echo esc_html( __( 'Mode', 'beplus-scss' ) ); ?></span>
						<span class="beplus-stat-value"><?php echo esc_html( 'auto' === $settings['compile_mode'] ? __( 'Auto-compile', 'beplus-scss' ) : __( 'Manual', 'beplus-scss' ) ); ?></span>
					</div>
				</div>
				<div class="beplus-stat">
					<span class="beplus-stat-icon dashicons dashicons-editor-contract" aria-hidden="true"></span>
					<div>
						<span class="beplus-stat-label"><?php echo esc_html( __( 'Minify', 'beplus-scss' ) ); ?></span>
						<span class="beplus-stat-value"><?php echo esc_html( $settings['minify'] ? __( 'On', 'beplus-scss' ) : __( 'Off', 'beplus-scss' ) ); ?></span>
					</div>
				</div>
				<div class="beplus-stat">
					<span class="beplus-stat-icon dashicons dashicons-portfolio" aria-hidden="true"></span>
					<div>
						<span class="beplus-stat-label"><?php echo esc_html( __( 'Output', 'beplus-scss' ) ); ?></span>
						<span class="beplus-stat-value"><?php echo esc_html( '' !== $settings['css_dir'] ? $settings['css_dir'] : __( 'Not set', 'beplus-scss' ) ); ?></span>
					</div>
				</div>
			</div>

			<div class="beplus-grid">
				<div class="beplus-col-main">
					<form action="options.php" method="post" class="beplus-card">
						<h2 class="beplus-card-title"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span><?php echo esc_html( __( 'Settings', 'beplus-scss' ) ); ?></h2>
						<?php
						settings_fields( 'beplus_scss_settings_group' );
						$this->renderFields( $settings );
						submit_button( __( 'Save Changes', 'beplus-scss' ), 'primary', 'submit', false, [ 'class' => 'beplus-btn-save' ] );
						?>
					</form>
				</div>
				<div class="beplus-col-side">
					<div class="beplus-card">
						<h2 class="beplus-card-title"><span class="dashicons dashicons-performance" aria-hidden="true"></span><?php echo esc_html( __( 'Compile now', 'beplus-scss' ) ); ?></h2>
						<?php $this->renderCompileNow(); ?>
					</div>
					<div class="beplus-tip">
						<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
						<span><?php echo esc_html( __( 'Tip: Auto mode recompiles changed files on the frontend. Manual mode compiles only when you press the button.', 'beplus-scss' ) ); ?></span>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * @param ScssSettings $settings
	 */
	public function renderFields( array $settings ): void {
		/** @var ScssSettings $values */
		$values = wp_parse_args( $settings, self::defaults() );
		?>
		<div class="beplus-field">
			<label class="beplus-field-label" for="beplus_scss_scss_dir"><?php echo esc_html( __( 'SCSS source directory', 'beplus-scss' ) ); ?></label>
			<input type="text" id="beplus_scss_scss_dir" name="beplus_scss_settings[scss_dir]" value="<?php echo esc_attr( $values['scss_dir'] ); ?>" placeholder="<?php echo esc_attr( 'assets/scss' ); ?>" />
			<p class="beplus-hint"><?php echo esc_html( __( 'Relative to your active theme. Must contain at least one non-partial .scss file.', 'beplus-scss' ) ); ?></p>
		</div>
		<div class="beplus-field">
			<label class="beplus-field-label" for="beplus_scss_css_dir"><?php echo esc_html( __( 'CSS destination directory', 'beplus-scss' ) ); ?></label>
			<input type="text" id="beplus_scss_css_dir" name="beplus_scss_settings[css_dir]" value="<?php echo esc_attr( $values['css_dir'] ); ?>" placeholder="<?php echo esc_attr( 'assets/css' ); ?>" />
			<p class="beplus-hint"><?php echo esc_html( __( 'Relative to your active theme. Must be writable by the server.', 'beplus-scss' ) ); ?></p>
		</div>
		<fieldset class="beplus-segment">
			<legend class="beplus-field-label"><?php echo esc_html( __( 'Compile mode', 'beplus-scss' ) ); ?></legend>
			<div class="beplus-segment-inner">
				<input type="radio" id="beplus_scss_mode_auto" name="beplus_scss_settings[compile_mode]" value="auto" <?php checked( 'auto', $values['compile_mode'] ); ?> />
				<label for="beplus_scss_mode_auto"><span class="dashicons dashicons-update" aria-hidden="true"></span><?php echo esc_html( __( 'Auto', 'beplus-scss' ) ); ?></label>
				<input type="radio" id="beplus_scss_mode_manual" name="beplus_scss_settings[compile_mode]" value="manual" <?php checked( 'manual', $values['compile_mode'] ); ?> />
				<label for="beplus_scss_mode_manual"><span class="dashicons dashicons-controls-repeat" aria-hidden="true"></span><?php echo esc_html( __( 'Manual', 'beplus-scss' ) ); ?></label>
			</div>
			<p class="beplus-hint"><?php echo esc_html( __( 'Auto recompiles changed files on the frontend; Manual compiles only via the button.', 'beplus-scss' ) ); ?></p>
		</fieldset>
		<label class="beplus-toggle">
			<input type="checkbox" name="beplus_scss_settings[source_map]" value="1" <?php checked( true, (bool) $values['source_map'] ); ?> />
			<span class="beplus-toggle-track"></span>
			<span class="beplus-toggle-text">
				<strong><?php echo esc_html( __( 'Source maps', 'beplus-scss' ) ); ?></strong>
				<small><?php echo esc_html( __( 'Emit .css.map files', 'beplus-scss' ) ); ?></small>
			</span>
		</label>
		<label class="beplus-toggle">
			<input type="checkbox" name="beplus_scss_settings[minify]" value="1" <?php checked( true, (bool) $values['minify'] ); ?> />
			<span class="beplus-toggle-track"></span>
			<span class="beplus-toggle-text">
				<strong><?php echo esc_html( __( 'Minify', 'beplus-scss' ) ); ?></strong>
				<small><?php echo esc_html( __( 'Compact output', 'beplus-scss' ) ); ?></small>
			</span>
		</label>
		<label class="beplus-toggle">
			<input type="checkbox" name="beplus_scss_settings[enqueue]" value="1" <?php checked( true, (bool) $values['enqueue'] ); ?> />
			<span class="beplus-toggle-track"></span>
			<span class="beplus-toggle-text">
				<strong><?php echo esc_html( __( 'Enqueue compiled CSS', 'beplus-scss' ) ); ?></strong>
				<small><?php echo esc_html( __( 'Load compiled styles on the frontend', 'beplus-scss' ) ); ?></small>
			</span>
		</label>
		<?php
	}

	public function renderCompileNow(): void {
		$url = admin_url( 'admin-post.php' );
		?>
		<form method="get" action="<?php echo esc_url( $url ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::COMPILE_ACTION ); ?>" />
			<?php wp_nonce_field( self::NONCE_FIELD ); ?>
			<input type="hidden" name="msg_base" value="admin" />
			<?php submit_button( __( 'Compile SCSS now', 'beplus-scss' ), 'secondary beplus-btn-compile', 'compile-now', false ); ?>
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
			$rel = $this->normalizePath( $input['scss_dir'] );
			if ( '' === $rel ) {
				$output['scss_dir'] = '';
			} elseif ( $this->hasDotDotSegment( $rel ) ) {
				add_settings_error( self::OPTION_NAME, 'bad_scss_dir', __( 'SCSS directory must not contain "..".', 'beplus-scss' ) );
			} else {
				$validated = $this->validateScssDir( self::absPath( $rel ) );
				if ( true === $validated ) {
					$output['scss_dir'] = $rel;
				} else {
					add_settings_error( self::OPTION_NAME, 'bad_scss_dir', $validated );
				}
			}
		}

		if ( isset( $input['css_dir'] ) && is_string( $input['css_dir'] ) ) {
			$rel = $this->normalizePath( $input['css_dir'] );
			if ( '' === $rel ) {
				$output['css_dir'] = '';
			} elseif ( $this->hasDotDotSegment( $rel ) ) {
				add_settings_error( self::OPTION_NAME, 'bad_css_dir', __( 'CSS directory must not contain "..".', 'beplus-scss' ) );
			} else {
				$validated = $this->validateCssDir( self::absPath( $rel ) );
				if ( true === $validated ) {
					$output['css_dir'] = $rel;
				} else {
					add_settings_error( self::OPTION_NAME, 'bad_css_dir', $validated );
				}
			}
		}

		if ( isset( $input['compile_mode'] ) && in_array( $input['compile_mode'], [ 'auto', 'manual' ], true ) ) {
			$output['compile_mode'] = $input['compile_mode'];
		}

		$output['source_map'] = ! empty( $input['source_map'] );
		$output['minify']     = ! empty( $input['minify'] );
		$output['enqueue']    = ! empty( $input['enqueue'] );

		return $output;
	}

	private function normalizePath( string $path ): string {
		$path = trim( $path );
		if ( '' === $path ) {
			return '';
		}
		$path = wp_normalize_path( $path );
		$path = trim( $path, '/' );

		return $path;
	}

	private function hasDotDotSegment( string $path ): bool {
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '..' === $segment ) {
				return true;
			}
		}

		return false;
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