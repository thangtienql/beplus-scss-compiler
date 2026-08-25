<?php

namespace Beplus\ScssCompiler\Settings;

use Beplus\ScssCompiler\Filesystem;
use Beplus\ScssCompiler\Scanner;

/**
 * @phpstan-type ScssPair array{scss_dir:string, css_dir:string}
 * @phpstan-type ScssSettings array{pairs:array<int, ScssPair>, scss_dir:string, css_dir:string, compile_mode:'auto'|'manual', source_map:bool, minify:bool, enqueue:bool}
 * @phpstan-type ScssSettingsInput array<string, mixed>
 */
final class SettingsPage {

	const OPTION_NAME    = 'beplus_scss_settings';
	const MENU_SLUG      = 'beplus-scss-compiler';
	const COMPILE_ACTION = 'beplus_scss_compile';
	const NONCE_FIELD    = 'beplus_scss_compile_nonce';

	/**
	 * Errors captured from the settings_errors transient before the core
	 * options-head.php include renders them inline on this screen.
	 *
	 * @var array<array-key, array<string, mixed>>|null
	 */
	private static $pendingErrors = null;

	/**
	 * @return ScssSettings
	 */
	private static function defaults(): array {
		return [
			'pairs'        => [],
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
		$pairs  = self::storedPairs( $stored );

		return [
			'pairs'        => $pairs,
			'scss_dir'     => $pairs[0]['scss_dir'] ?? '',
			'css_dir'      => $pairs[0]['css_dir'] ?? '',
			'compile_mode' => 'manual' === ( $stored['compile_mode'] ?? '' ) ? 'manual' : 'auto',
			'source_map'   => (bool) ( $stored['source_map'] ?? false ),
			'minify'       => (bool) ( $stored['minify'] ?? false ),
			'enqueue'      => (bool) ( $stored['enqueue'] ?? false ),
		];
	}

	/**
	 * Normalize stored pairs, migrating legacy scss_dir/css_dir keys into the
	 * first pair when no pairs key exists, dropping fully-blank rows, and
	 * deduping css_dirs (case-insensitive, first occurrence wins) so pairs
	 * saved by older versions are neutralized without a migration.
	 *
	 * @param array<mixed> $stored
	 * @return array<int, ScssPair>
	 */
	private static function storedPairs( array $stored ): array {
		$pairs   = [];
		$seenCss = [];
		if ( isset( $stored['pairs'] ) && is_array( $stored['pairs'] ) ) {
			foreach ( $stored['pairs'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$scss = isset( $row['scss_dir'] ) && is_string( $row['scss_dir'] ) ? trim( $row['scss_dir'], '/' ) : '';
				$css  = isset( $row['css_dir'] ) && is_string( $row['css_dir'] ) ? trim( $row['css_dir'], '/' ) : '';
				if ( '' === $scss && '' === $css ) {
					continue;
				}
				$cssKey = strtolower( $css );
				if ( isset( $seenCss[ $cssKey ] ) ) {
					continue;
				}
				$seenCss[ $cssKey ] = true;
				$pairs[]            = [
					'scss_dir' => $scss,
					'css_dir'  => $css,
				];
			}
		} elseif ( isset( $stored['scss_dir'] ) && isset( $stored['css_dir'] ) ) {
			$scss = is_string( $stored['scss_dir'] ) ? trim( $stored['scss_dir'], '/' ) : '';
			$css  = is_string( $stored['css_dir'] ) ? trim( $stored['css_dir'], '/' ) : '';
			if ( '' !== $scss || '' !== $css ) {
				$pairs[] = [
					'scss_dir' => $scss,
					'css_dir'  => $css,
				];
			}
		}

		return $pairs;
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
		add_action( 'current_screen', [ $this, 'suppressCoreNotices' ] );
		add_action( 'admin_notices', [ $this, 'captureSettingsErrors' ], PHP_INT_MAX );
	}

	/**
	 * Keep core's settings_errors() from printing its default markup on this
	 * screen; the plugin renders validation errors itself inside the layout.
	 *
	 * @param \WP_Screen|null $screen
	 */
	public function suppressCoreNotices( $screen ): void {
		if ( $screen instanceof \WP_Screen && 'settings_page_' . self::MENU_SLUG === $screen->id ) {
			remove_action( 'admin_notices', 'settings_errors' );
		}
	}

	/**
	 * Neutralize the core settings_errors() inline renderer. WordPress runs
	 * options-head.php (which calls settings_errors() directly) after the
	 * admin_notices hook on every options-general.php child page, so removing
	 * the admin_notices callback is not enough. This captures the transient
	 * errors into memory and empties the global before options-head.php runs.
	 */
	public function captureSettingsErrors(): void {
		$screen = get_current_screen();
		if ( ! $screen instanceof \WP_Screen || 'settings_page_' . self::MENU_SLUG !== $screen->id ) {
			return;
		}
		$transient = get_transient( 'settings_errors' );
		/** @var array<array-key, array<string, mixed>> $pending */
		$pending             = is_array( $transient ) ? $transient : [];
		self::$pendingErrors = $pending;
		delete_transient( 'settings_errors' );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Deliberately neutralizes the core renderer; the errors are kept in self::$pendingErrors.
		$GLOBALS['wp_settings_errors'] = [];
	}

	/**
	 * @return array<array-key, array<string, mixed>>
	 */
	public function capturedErrors(): array {
		return self::$pendingErrors ?? [];
	}

	public function registerMenu(): void {
		add_submenu_page(
			'options-general.php',
			__( 'Beplus SCSS Compiler', 'beplus-scss-compiler' ),
			__( 'Beplus SCSS Compiler', 'beplus-scss-compiler' ),
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
		/** @var string $msg */
		$msg                 = isset( $_GET['msg'] ) && is_scalar( $_GET['msg'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag from the wp_safe_redirect() query args; no state change happens here.
		$version             = get_option( 'beplus_scss_version', '' );
		$version             = is_string( $version ) ? $version : '';
		$settings            = self::currentSettings();
		$errors              = self::$pendingErrors ?? get_settings_errors();
		self::$pendingErrors = null;
		$toasts              = self::toastList(
			$errors,
			$msg,
			__( 'SCSS compiled successfully.', 'beplus-scss-compiler' ),
			__( 'Compilation failed. Check your SCSS sources and try again.', 'beplus-scss-compiler' ),
			__( 'Settings saved.', 'beplus-scss-compiler' )
		);
		?>
		<div class="wrap beplus-wrap">
			<style>
			.beplus-wrap{ --bp-bg:#f7f8fa; --bp-surface:#fff; --bp-hairline:#e2e4e9; --bp-ink:#1d2327; --bp-muted:#646970; --bp-accent:#3858e9; --bp-success:#00a32a; --bp-error:#d63638; --bp-mono:ui-monospace,"SF Mono",Menlo,Consolas,monospace; }
			.beplus-wrap *{ box-sizing:border-box; }
			.beplus-hero{ background:var(--bp-surface); border:1px solid var(--bp-hairline); border-left:4px solid var(--bp-accent); border-radius:8px; padding:24px 26px; margin-bottom:20px; }
			.beplus-hero-top{ display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
			.beplus-hero-text{ flex:1; min-width:200px; }
			.beplus-hero-title{ margin:0; font-size:20px; font-weight:700; line-height:1.3; color:var(--bp-ink); }
			.beplus-hero-sub{ margin:4px 0 0; font-size:13px; color:var(--bp-muted); }
			.beplus-hero-cmd{ margin:10px 0 0; font-family:var(--bp-mono); font-size:12px; color:var(--bp-muted); }
			.beplus-hero-cmd .beplus-cmd-prompt{ color:var(--bp-accent); }
			.beplus-hero-badges{ display:flex; gap:6px; align-items:center; }
			.beplus-badge{ background:#f0f0f1; border:1px solid var(--bp-hairline); border-radius:4px; padding:2px 8px; font-size:11px; font-weight:600; color:var(--bp-muted); white-space:nowrap; }
			.beplus-badge-active{ background:#edfaef; border-color:#bce0c1; color:var(--bp-success); }
			.beplus-toast{ display:flex; align-items:center; gap:10px; border-radius:6px; padding:12px 16px; margin-bottom:20px; font-size:13px; font-weight:500; border:1px solid; }
			.beplus-toast .dashicons{ width:20px; height:20px; font-size:20px; line-height:20px; }
			.beplus-toast-success{ background:#edfaef; border-color:#bce0c1; color:#007017; }
			.beplus-toast-error{ background:#fcf0f1; border-color:#f0b3b3; color:#8a2424; }
			.beplus-stats{ display:flex; gap:12px; margin-bottom:20px; }
			.beplus-stat{ flex:1; min-width:0; background:var(--bp-surface); border:1px solid var(--bp-hairline); border-radius:8px; padding:14px 16px; }
			.beplus-stat-label{ display:block; font-size:11px; font-weight:600; color:var(--bp-muted); }
			.beplus-stat-value{ display:block; margin-top:4px; font-family:var(--bp-mono); font-size:13px; font-weight:600; color:var(--bp-ink); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
			.beplus-grid{ display:flex; gap:20px; align-items:flex-start; }
			.beplus-col-main{ flex:1; min-width:0; }
			.beplus-col-side{ width:340px; flex-shrink:0; position:sticky; top:48px; }
			@media (max-width:959px){ .beplus-grid{ flex-direction:column; } .beplus-col-side{ width:100%; position:static; } }
			.beplus-card{ background:var(--bp-surface); border:1px solid var(--bp-hairline); border-radius:8px; padding:24px; }
			.beplus-card-title{ display:flex; align-items:center; gap:8px; margin:0 0 18px; font-size:15px; font-weight:700; color:var(--bp-ink); }
			.beplus-card-title .dashicons{ color:var(--bp-accent); }
			.beplus-field{ margin-bottom:20px; }
			.beplus-field-label{ display:block; margin-bottom:6px; font-size:13px; font-weight:600; color:var(--bp-ink); }
			.beplus-field input[type="text"]{ width:100%; max-width:100%; margin:0; border:1.5px solid var(--bp-hairline); border-radius:6px; padding:9px 12px; font-size:13px; font-family:var(--bp-mono); color:var(--bp-ink); background:var(--bp-surface); box-shadow:none; }
			.beplus-field input[type="text"]:focus{ border-color:var(--bp-accent); box-shadow:0 0 0 3px rgba(56,88,233,.14); outline:none; }
			.beplus-hint{ margin:6px 0 0; font-size:12px; color:var(--bp-muted); }
			.beplus-pair-row{ background:#fbfbfc; border:1px solid var(--bp-hairline); border-radius:8px; padding:16px 18px; margin-bottom:16px; }
			.beplus-pair-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; }
			.beplus-pair-label{ font-size:11px; font-weight:600; color:var(--bp-muted); }
			.beplus-pair-inputs{ display:grid; grid-template-columns:1fr 1fr; gap:16px; }
			.beplus-pair-inputs .beplus-field{ margin-bottom:0; }
			@media (max-width:600px){ .beplus-pair-inputs{ grid-template-columns:1fr; } }
			.beplus-btn-remove{ background:var(--bp-surface); border:1px solid #f0b3b3; color:var(--bp-error); border-radius:6px; padding:5px 14px; font-size:12px; font-weight:600; cursor:pointer; transition:background .15s; }
			.beplus-btn-remove:hover{ background:#fcf0f1; }
			.beplus-btn-add{ background:var(--bp-surface); border:1px solid var(--bp-hairline); color:var(--bp-ink); border-radius:6px; padding:8px 18px; font-weight:600; cursor:pointer; margin-bottom:20px; }
			.beplus-btn-add:hover{ background:#f6f7f7; }
			.beplus-segment{ margin:0 0 20px; padding:0; border:0; }
			.beplus-segment .beplus-field-label{ margin-bottom:6px; }
			.beplus-segment-inner{ display:flex; gap:4px; padding:4px; background:#f0f0f1; border-radius:6px; }
			.beplus-segment input{ position:absolute; opacity:0; pointer-events:none; }
			.beplus-segment label{ flex:1; display:flex; align-items:center; justify-content:center; gap:6px; padding:8px 12px; border:1px solid transparent; border-radius:5px; font-size:13px; font-weight:600; color:var(--bp-muted); cursor:pointer; }
			.beplus-segment input:checked + label{ background:var(--bp-surface); border-color:var(--bp-hairline); color:var(--bp-ink); box-shadow:0 1px 2px rgba(0,0,0,.05); }
			.beplus-segment input:focus-visible + label{ box-shadow:0 0 0 3px rgba(56,88,233,.25); }
			.beplus-segment .beplus-hint{ margin-top:8px; }
			.beplus-toggle{ display:flex; align-items:center; gap:12px; margin-bottom:14px; cursor:pointer; }
			.beplus-toggle input{ position:absolute; opacity:0; pointer-events:none; }
			.beplus-toggle-track{ position:relative; width:44px; height:24px; flex-shrink:0; border-radius:999px; background:#c3c4c7; transition:background .2s; }
			.beplus-toggle-track::after{ content:""; position:absolute; top:2px; left:2px; width:20px; height:20px; border-radius:50%; background:#fff; box-shadow:0 1px 2px rgba(0,0,0,.2); transition:transform .2s; }
			.beplus-toggle input:checked + .beplus-toggle-track{ background:var(--bp-accent); }
			.beplus-toggle input:checked + .beplus-toggle-track::after{ transform:translateX(20px); }
			.beplus-toggle input:focus-visible + .beplus-toggle-track{ box-shadow:0 0 0 3px rgba(56,88,233,.3); }
			.beplus-toggle-text strong{ display:block; font-size:13px; font-weight:600; color:var(--bp-ink); }
			.beplus-toggle-text small{ display:block; font-size:12px; color:var(--bp-muted); }
			.beplus-btn-save{ background:var(--bp-accent)!important; color:#fff!important; border:0!important; border-radius:6px!important; padding:8px 22px!important; font-weight:600!important; box-shadow:none!important; }
			.beplus-btn-save:hover{ background:#2c47d4!important; }
			.beplus-btn-compile{ background:var(--bp-accent)!important; color:#fff!important; border:0!important; border-radius:6px!important; padding:10px 24px!important; font-weight:600!important; width:100%!important; text-align:center!important; box-shadow:none!important; }
			.beplus-btn-compile:hover{ background:#2c47d4!important; }
			.beplus-tip{ display:flex; gap:10px; margin-top:16px; padding:14px 16px; background:#fcf9f2; border:1px solid #e6d9b8; border-radius:6px; font-size:12.5px; line-height:1.5; color:#7a5b13; }
			.beplus-tip .dashicons{ flex-shrink:0; color:#b98500; }
			</style>

			<div class="beplus-hero">
				<div class="beplus-hero-top">
					<div class="beplus-hero-text">
						<h1 class="beplus-hero-title"><?php echo esc_html( __( 'Beplus SCSS Compiler', 'beplus-scss-compiler' ) ); ?></h1>
						<p class="beplus-hero-sub"><?php echo esc_html( __( 'Declare your SCSS source and CSS destination directories and let the plugin handle the rest.', 'beplus-scss-compiler' ) ); ?></p>
						<p class="beplus-hero-cmd"><span class="beplus-cmd-prompt">$</span> beplus-scss compile</p>
					</div>
					<div class="beplus-hero-badges">
					<?php if ( '' !== $version ) : ?>
						<?php /* translators: %s: Plugin version number. */ ?>
						<span class="beplus-badge"><?php echo esc_html( sprintf( __( 'v%s', 'beplus-scss-compiler' ), $version ) ); ?></span>
					<?php endif; ?>
						<span class="beplus-badge beplus-badge-active"><?php echo esc_html( __( 'Active', 'beplus-scss-compiler' ) ); ?></span>
					</div>
				</div>
			</div>

			<?php foreach ( $toasts as $toast ) : ?>
				<div class="beplus-toast beplus-toast-<?php echo esc_attr( $toast['type'] ); ?>" role="<?php echo 'error' === $toast['type'] ? 'alert' : 'status'; ?>">
					<span class="dashicons dashicons-<?php echo 'error' === $toast['type'] ? 'warning' : 'yes-alt'; ?>" aria-hidden="true"></span>
					<span><?php echo esc_html( $toast['message'] ); ?></span>
				</div>
			<?php endforeach; ?>

			<script>
			( function () {
				var url = new URL( window.location.href );
				if ( url.searchParams.has( 'msg' ) ) {
					url.searchParams.delete( 'msg' );
					window.history.replaceState( {}, '', url.toString() );
				}
			} )();
			</script>

			<div class="beplus-stats">
				<div class="beplus-stat">
					<span class="beplus-stat-label"><?php echo esc_html( __( 'Mode', 'beplus-scss-compiler' ) ); ?></span>
					<span class="beplus-stat-value"><?php echo esc_html( 'auto' === $settings['compile_mode'] ? __( 'Auto-compile', 'beplus-scss-compiler' ) : __( 'Manual', 'beplus-scss-compiler' ) ); ?></span>
				</div>
				<div class="beplus-stat">
					<span class="beplus-stat-label"><?php echo esc_html( __( 'Minify', 'beplus-scss-compiler' ) ); ?></span>
					<span class="beplus-stat-value"><?php echo esc_html( $settings['minify'] ? __( 'On', 'beplus-scss-compiler' ) : __( 'Off', 'beplus-scss-compiler' ) ); ?></span>
				</div>
				<div class="beplus-stat">
					<span class="beplus-stat-label"><?php echo esc_html( __( 'Output', 'beplus-scss-compiler' ) ); ?></span>
					<span class="beplus-stat-value">
					<?php
					$outputCount = count( $settings['pairs'] );
					if ( 0 === $outputCount ) {
						echo esc_html( __( 'Not set', 'beplus-scss-compiler' ) );
					} elseif ( 1 === $outputCount ) {
						echo esc_html( $settings['pairs'][0]['css_dir'] );
					} else {
						/* translators: %d: Number of SCSS/CSS pairs. */
						echo esc_html( sprintf( __( '%d outputs', 'beplus-scss-compiler' ), $outputCount ) );
					}
					?>
					</span>
				</div>
			</div>

			<div class="beplus-grid">
				<div class="beplus-col-main">
					<form action="options.php" method="post" class="beplus-card">
						<h2 class="beplus-card-title"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span><?php echo esc_html( __( 'Settings', 'beplus-scss-compiler' ) ); ?></h2>
						<?php
						settings_fields( 'beplus_scss_settings_group' );
						$this->renderFields( $settings );
						submit_button( __( 'Save Changes', 'beplus-scss-compiler' ), 'primary', 'submit', false, [ 'class' => 'beplus-btn-save' ] );
						?>
					</form>
				</div>
				<div class="beplus-col-side">
					<div class="beplus-card">
						<h2 class="beplus-card-title"><span class="dashicons dashicons-performance" aria-hidden="true"></span><?php echo esc_html( __( 'Compile now', 'beplus-scss-compiler' ) ); ?></h2>
						<?php $this->renderCompileNow(); ?>
					</div>
					<div class="beplus-tip">
						<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
						<span><?php echo esc_html( __( 'Tip: Auto mode recompiles changed files on the frontend. Manual mode compiles only when you press the button.', 'beplus-scss-compiler' ) ); ?></span>
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
		<div class="beplus-pairs" id="beplus-pairs">
			<?php
			$pairs = $values['pairs'];
			if ( [] === $pairs ) {
				$pairs[] = [
					'scss_dir' => '',
					'css_dir'  => '',
				];
			}
			foreach ( $pairs as $pairIndex => $pair ) {
				$this->renderPairRow( $pairIndex, $pair['scss_dir'], $pair['css_dir'] );
			}
			?>
			<button type="button" class="beplus-btn-add" id="beplus-add-pair"><?php echo esc_html( __( 'Add pair', 'beplus-scss-compiler' ) ); ?></button>
		</div>
		<script type="text/template" id="beplus-pair-template"><?php $this->renderPairRowTemplate(); ?></script>
		<script>
		( function () {
			var container = document.getElementById( 'beplus-pairs' );
			var template  = document.getElementById( 'beplus-pair-template' );
			var addBtn    = document.getElementById( 'beplus-add-pair' );
			var labelPrefix = <?php echo wp_json_encode( __( 'Pair', 'beplus-scss-compiler' ) . ' ' ); ?>;
			if ( ! container || ! template || ! addBtn ) {
				return;
			}
			addBtn.addEventListener( 'click', function () {
				var index = container.querySelectorAll( '.beplus-pair-row' ).length;
				var row   = template.textContent.trim().replace( /__INDEX__/g, String( index ) );
				addBtn.insertAdjacentHTML( 'beforebegin', row );
				var label = addBtn.previousElementSibling.querySelector( '[data-pair-label]' );
				if ( label ) {
					label.textContent = labelPrefix + String( index + 1 );
				}
			} );
			container.addEventListener( 'click', function ( event ) {
				var btn = event.target.closest( '[data-remove]' );
				if ( ! btn ) {
					return;
				}
				var rows = container.querySelectorAll( '.beplus-pair-row' );
				if ( rows.length > 1 ) {
					btn.closest( '.beplus-pair-row' ).remove();
				}
			} );
		} )();
		</script>
		<fieldset class="beplus-segment">
			<legend class="beplus-field-label"><?php echo esc_html( __( 'Compile mode', 'beplus-scss-compiler' ) ); ?></legend>
			<div class="beplus-segment-inner">
				<input type="radio" id="beplus_scss_mode_auto" name="beplus_scss_settings[compile_mode]" value="auto" <?php checked( 'auto', $values['compile_mode'] ); ?> />
				<label for="beplus_scss_mode_auto"><span class="dashicons dashicons-update" aria-hidden="true"></span><?php echo esc_html( __( 'Auto', 'beplus-scss-compiler' ) ); ?></label>
				<input type="radio" id="beplus_scss_mode_manual" name="beplus_scss_settings[compile_mode]" value="manual" <?php checked( 'manual', $values['compile_mode'] ); ?> />
				<label for="beplus_scss_mode_manual"><span class="dashicons dashicons-controls-repeat" aria-hidden="true"></span><?php echo esc_html( __( 'Manual', 'beplus-scss-compiler' ) ); ?></label>
			</div>
			<p class="beplus-hint"><?php echo esc_html( __( 'Auto recompiles changed files on the frontend; Manual compiles only via the button.', 'beplus-scss-compiler' ) ); ?></p>
		</fieldset>
		<label class="beplus-toggle">
			<input type="checkbox" name="beplus_scss_settings[source_map]" value="1" <?php checked( true, (bool) $values['source_map'] ); ?> />
			<span class="beplus-toggle-track"></span>
			<span class="beplus-toggle-text">
				<strong><?php echo esc_html( __( 'Source maps', 'beplus-scss-compiler' ) ); ?></strong>
				<small><?php echo esc_html( __( 'Emit .css.map files', 'beplus-scss-compiler' ) ); ?></small>
			</span>
		</label>
		<label class="beplus-toggle">
			<input type="checkbox" name="beplus_scss_settings[minify]" value="1" <?php checked( true, (bool) $values['minify'] ); ?> />
			<span class="beplus-toggle-track"></span>
			<span class="beplus-toggle-text">
				<strong><?php echo esc_html( __( 'Minify', 'beplus-scss-compiler' ) ); ?></strong>
				<small><?php echo esc_html( __( 'Compact output', 'beplus-scss-compiler' ) ); ?></small>
			</span>
		</label>
		<label class="beplus-toggle">
			<input type="checkbox" name="beplus_scss_settings[enqueue]" value="1" <?php checked( true, (bool) $values['enqueue'] ); ?> />
			<span class="beplus-toggle-track"></span>
			<span class="beplus-toggle-text">
				<strong><?php echo esc_html( __( 'Enqueue compiled CSS', 'beplus-scss-compiler' ) ); ?></strong>
				<small><?php echo esc_html( __( 'Load compiled styles on the frontend', 'beplus-scss-compiler' ) ); ?></small>
			</span>
		</label>
		<?php
	}

	/**
	 * @param string $scss
	 * @param string $css
	 */
	private function renderPairRow( int $index, string $scss, string $css ): void {
		?>
		<div class="beplus-pair-row">
			<div class="beplus-pair-head">
				<?php /* translators: %d: Pair number. */ ?>
				<span class="beplus-pair-label"><?php echo esc_html( sprintf( __( 'Pair %d', 'beplus-scss-compiler' ), $index + 1 ) ); ?></span>
				<button type="button" class="beplus-btn-remove" data-remove><?php echo esc_html( __( 'Remove', 'beplus-scss-compiler' ) ); ?></button>
			</div>
			<div class="beplus-pair-inputs">
				<div class="beplus-field">
					<label class="beplus-field-label" for="beplus_scss_scss_dir_<?php echo esc_attr( (string) $index ); ?>"><?php echo esc_html( __( 'SCSS source directory', 'beplus-scss-compiler' ) ); ?></label>
					<input type="text" id="beplus_scss_scss_dir_<?php echo esc_attr( (string) $index ); ?>" name="beplus_scss_settings[pairs][<?php echo esc_attr( (string) $index ); ?>][scss_dir]" value="<?php echo esc_attr( $scss ); ?>" placeholder="<?php echo esc_attr( 'assets/scss' ); ?>" />
					<p class="beplus-hint"><?php echo esc_html( __( 'Relative to your active theme. Must contain at least one non-partial .scss file.', 'beplus-scss-compiler' ) ); ?></p>
				</div>
				<div class="beplus-field">
					<label class="beplus-field-label" for="beplus_scss_css_dir_<?php echo esc_attr( (string) $index ); ?>"><?php echo esc_html( __( 'CSS destination directory', 'beplus-scss-compiler' ) ); ?></label>
					<input type="text" id="beplus_scss_css_dir_<?php echo esc_attr( (string) $index ); ?>" name="beplus_scss_settings[pairs][<?php echo esc_attr( (string) $index ); ?>][css_dir]" value="<?php echo esc_attr( $css ); ?>" placeholder="<?php echo esc_attr( 'assets/css' ); ?>" />
					<p class="beplus-hint"><?php echo esc_html( __( 'Relative to your active theme. Must be writable by the server.', 'beplus-scss-compiler' ) ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	private function renderPairRowTemplate(): void {
		?>
		<div class="beplus-pair-row">
			<div class="beplus-pair-head">
				<span class="beplus-pair-label" data-pair-label></span>
				<button type="button" class="beplus-btn-remove" data-remove><?php echo esc_html( __( 'Remove', 'beplus-scss-compiler' ) ); ?></button>
			</div>
			<div class="beplus-pair-inputs">
				<div class="beplus-field">
					<label class="beplus-field-label"><?php echo esc_html( __( 'SCSS source directory', 'beplus-scss-compiler' ) ); ?></label>
					<input type="text" name="beplus_scss_settings[pairs][__INDEX__][scss_dir]" value="" placeholder="<?php echo esc_attr( 'assets/scss' ); ?>" />
					<p class="beplus-hint"><?php echo esc_html( __( 'Relative to your active theme. Must contain at least one non-partial .scss file.', 'beplus-scss-compiler' ) ); ?></p>
				</div>
				<div class="beplus-field">
					<label class="beplus-field-label"><?php echo esc_html( __( 'CSS destination directory', 'beplus-scss-compiler' ) ); ?></label>
					<input type="text" name="beplus_scss_settings[pairs][__INDEX__][css_dir]" value="" placeholder="<?php echo esc_attr( 'assets/css' ); ?>" />
					<p class="beplus-hint"><?php echo esc_html( __( 'Relative to your active theme. Must be writable by the server.', 'beplus-scss-compiler' ) ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	public function renderCompileNow(): void {
		$url = admin_url( 'admin-post.php' );
		?>
		<form method="get" action="<?php echo esc_url( $url ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::COMPILE_ACTION ); ?>" />
			<?php wp_nonce_field( self::NONCE_FIELD ); ?>
			<input type="hidden" name="msg_base" value="admin" />
			<?php submit_button( __( 'Compile SCSS now', 'beplus-scss-compiler' ), 'secondary beplus-btn-compile', 'compile-now', false ); ?>
		</form>
		<?php
	}

	/**
	 * Resolve which toasts to render. Pure (no WP calls) so the priority
	 * rules are unit-testable: validation errors win, then "Settings saved.",
	 * then the compile message.
	 *
	 * @param array<array-key, array<string, mixed>> $errors
	 * @param string $msg
	 * @param string $compiledMsg
	 * @param string $failedMsg
	 * @param string $savedMsg
	 * @return array<int, array{type:string, message:string}>
	 */
	public static function toastList( array $errors, string $msg, string $compiledMsg, string $failedMsg, string $savedMsg ): array {
		$toasts  = [];
		$hasSave = false;

		foreach ( $errors as $error ) {
			$message = isset( $error['message'] ) && is_string( $error['message'] ) ? $error['message'] : '';
			$type    = isset( $error['type'] ) && 'success' === $error['type'] ? 'success' : 'error';
			if ( 'settings_updated' === ( $error['code'] ?? '' ) ) {
				$hasSave = true;
				continue;
			}
			if ( '' === $message ) {
				continue;
			}
			$toasts[] = [
				'type'    => $type,
				'message' => $message,
			];
		}

		if ( [] !== $toasts ) {
			return $toasts;
		}

		if ( $hasSave ) {
			return [
				[
					'type'    => 'success',
					'message' => $savedMsg,
				],
			];
		}

		if ( 'compiled' === $msg ) {
			return [
				[
					'type'    => 'success',
					'message' => $compiledMsg,
				],
			];
		}
		if ( 'error' === $msg ) {
			return [
				[
					'type'    => 'error',
					'message' => $failedMsg,
				],
			];
		}

		return [];
	}

	/**
	 * Validate and normalize a list of pair rows. Pure — filesystem checks are
	 * injected and no WP functions are called, so the decision logic is
	 * unit-testable without wp-env. Error messages are mapped to translated
	 * strings by the caller (sanitize()).
	 *
	 * @param array<mixed> $inputPairs
	 * @param array<int, ScssPair> $previousPairs previous stored pairs (per-row fallback on error)
	 * @param callable(string):bool $scssValidator
	 * @param callable(string):bool $cssValidator
	 * @return array{pairs:array<int, ScssPair>, errors:array<int, array{index:int, code:string}>}
	 */
	public static function sanitizePairs( array $inputPairs, array $previousPairs, callable $scssValidator, callable $cssValidator ): array {
		$pairs       = [];
		$errors      = [];
		$seenCssDirs = [];

		foreach ( $inputPairs as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$scssRaw = isset( $row['scss_dir'] ) && is_string( $row['scss_dir'] ) ? $row['scss_dir'] : '';
			$cssRaw  = isset( $row['css_dir'] ) && is_string( $row['css_dir'] ) ? $row['css_dir'] : '';
			$scss    = self::trimSlashes( trim( $scssRaw ) );
			$css     = self::trimSlashes( trim( $cssRaw ) );

			if ( '' === $scss && '' === $css ) {
				continue;
			}
			if ( '' === $scss || '' === $css ) {
				$errors[] = [
					'index' => $index,
					'code'  => '' === $scss ? 'bad_scss_dir_' . $index : 'bad_css_dir_' . $index,
				];
				self::revertPair( $previousPairs, $index, $pairs, $seenCssDirs );
				continue;
			}
			$scssErr = self::hasDotDotSegment( $scss ) || ! $scssValidator( $scss );
			$cssErr  = self::hasDotDotSegment( $css ) || ! $cssValidator( $css );
			if ( $scssErr ) {
				$errors[] = [
					'index' => $index,
					'code'  => 'bad_scss_dir_' . $index,
				];
			}
			if ( $cssErr ) {
				$errors[] = [
					'index' => $index,
					'code'  => 'bad_css_dir_' . $index,
				];
			}
			if ( $scssErr || $cssErr ) {
				self::revertPair( $previousPairs, $index, $pairs, $seenCssDirs );
				continue;
			}
			if ( isset( $seenCssDirs[ strtolower( $css ) ] ) ) {
				$errors[] = [
					'index' => $index,
					'code'  => 'duplicate_css_dir_' . $index,
				];
				self::revertPair( $previousPairs, $index, $pairs, $seenCssDirs );
				continue;
			}
			$seenCssDirs[ strtolower( $css ) ] = $index;
			$pairs[]                           = [
				'scss_dir' => $scss,
				'css_dir'  => $css,
			];
		}

		return [
			'pairs'  => $pairs,
			'errors' => $errors,
		];
	}

	/**
	 * Append the row's previous value, unless it was never stored or its css_dir
	 * duplicates one already accepted (case-insensitive) — dropping avoids
	 * re-introducing the very duplication being rejected.
	 *
	 * @param array<int, ScssPair> $previousPairs
	 * @param int|string           $index
	 * @param array<int, ScssPair> $pairs
	 * @param array<string, mixed> $seenCssDirs
	 */
	private static function revertPair( array $previousPairs, $index, array &$pairs, array &$seenCssDirs ): void {
		if ( ! isset( $previousPairs[ $index ] ) ) {
			return;
		}
		$previous = $previousPairs[ $index ];
		$css      = strtolower( $previous['css_dir'] );
		if ( '' === $css || isset( $seenCssDirs[ $css ] ) ) {
			return;
		}
		$seenCssDirs[ $css ] = $index;
		$pairs[]             = $previous;
	}

	/**
	 * @param ScssSettingsInput $input
	 * @return ScssSettings
	 */
	public function sanitize( array $input ): array {
		/** @var ScssSettings $output */
		$output = wp_parse_args( self::currentSettings(), self::defaults() );
		/** @var mixed $storedOption */
		$storedOption = get_option( self::OPTION_NAME, [] );
		$prev         = self::storedPairs( is_array( $storedOption ) ? $storedOption : [] );

		if ( isset( $input['pairs'] ) && is_array( $input['pairs'] ) ) {
			$result          = self::sanitizePairs(
				$input['pairs'],
				$prev,
				function ( string $rel ): bool {
					$validated = $this->validateScssDir( self::absPath( $rel ) );

					return true === $validated;
				},
				function ( string $rel ): bool {
					$validated = $this->validateCssDir( self::absPath( $rel ) );

					return true === $validated;
				}
			);
			$output['pairs'] = $result['pairs'];
			foreach ( $result['errors'] as $error ) {
				if ( strpos( $error['code'], 'bad_scss_dir' ) === 0 ) {
					$message = __( 'SCSS directory is not valid.', 'beplus-scss-compiler' );
				} elseif ( strpos( $error['code'], 'duplicate_css_dir' ) === 0 ) {
					$message = __( 'CSS destination directory must be unique across pairs.', 'beplus-scss-compiler' );
				} else {
					$message = __( 'CSS directory is not valid.', 'beplus-scss-compiler' );
				}
				if ( function_exists( 'add_settings_error' ) ) {
					add_settings_error( self::OPTION_NAME, $error['code'], $message );
				}
			}
		} else {
			$output['pairs'] = $prev;
		}

		$output['scss_dir'] = $output['pairs'][0]['scss_dir'] ?? '';
		$output['css_dir']  = $output['pairs'][0]['css_dir'] ?? '';

		if ( isset( $input['compile_mode'] ) && in_array( $input['compile_mode'], [ 'auto', 'manual' ], true ) ) {
			$output['compile_mode'] = $input['compile_mode'];
		}

		$output['source_map'] = ! empty( $input['source_map'] );
		$output['minify']     = ! empty( $input['minify'] );
		$output['enqueue']    = ! empty( $input['enqueue'] );

		return $output;
	}

	private static function trimSlashes( string $path ): string {
		return trim( $path, '/' );
	}

	private static function hasDotDotSegment( string $path ): bool {
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
		if ( ! Filesystem::isDir( $path ) ) {
			return __( 'SCSS directory does not exist.', 'beplus-scss-compiler' );
		}
		if ( ! Filesystem::isReadable( $path ) ) {
			return __( 'SCSS directory is not readable.', 'beplus-scss-compiler' );
		}
		if ( 0 < count( Scanner::scan( $path ) ) ) {
			return true;
		}

		return __( 'SCSS directory contains no entries (non-partial .scss files).', 'beplus-scss-compiler' );
	}

	/**
	 * @return true|string True when valid, else an error message.
	 */
	private function validateCssDir( string $path ) {
		if ( '' === $path ) {
			return true;
		}
		if ( ! Filesystem::isDir( $path ) ) {
			return __( 'CSS directory does not exist.', 'beplus-scss-compiler' );
		}
		if ( ! Filesystem::isWritable( $path ) ) {
			return __( 'CSS directory is not writable.', 'beplus-scss-compiler' );
		}

		return true;
	}
}