<?php

namespace Beplus\ScssCompiler;

use Beplus\ScssCompiler\Compiler\CompilerInterface;
use Beplus\ScssCompiler\Compiler\ScssPhpCompiler;
use Beplus\ScssCompiler\Settings\SettingsPage;
use Beplus\ScssCompiler\Value\CompileConfig;
use Beplus\ScssCompiler\Value\CompiledResult;
use Beplus\ScssCompiler\Value\Style;

/**
 * @phpstan-import-type ScssSettings from SettingsPage
 */
final class Plugin {

	const VERSION             = '1.0.0';
	const FINGERPRINTS_OPTION = 'beplus_scss_fingerprints';
	const LAST_ERROR_OPTION   = 'beplus_scss_last_error';
	const VERSION_OPTION      = 'beplus_scss_version';

	private SettingsPage $settingsPage;
	private static bool $autoCompiled = false;

	public function __construct() {
		$this->settingsPage = new SettingsPage();
	}

	public function register(): void {
		$this->settingsPage->register();

		add_action( 'wp_enqueue_scripts', [ $this, 'onEnqueueScripts' ] );
		add_action( 'admin_post_' . SettingsPage::COMPILE_ACTION, [ $this, 'handleCompileNow' ] );
		add_action( 'init', [ $this, 'registerEndpoint' ] );
		add_action( 'template_redirect', [ $this, 'serveFile' ] );
		add_action( 'update_option_beplus_scss_settings', [ $this, 'flushRewriteRules' ] );

		register_activation_hook( BE_PLUS_SCSS_COMPILER_MAIN_FILE, [ $this, 'activate' ] );
		register_deactivation_hook( BE_PLUS_SCSS_COMPILER_MAIN_FILE, [ $this, 'deactivate' ] );
	}

	public function activate(): void {
		$this->registerEndpoint();
		update_option( self::VERSION_OPTION, self::VERSION );
		flush_rewrite_rules();
	}

	public function deactivate(): void {
		flush_rewrite_rules();
	}

	public function flushRewriteRules(): void {
		$this->registerEndpoint();
		flush_rewrite_rules();
	}

	public function registerEndpoint(): void {
		add_rewrite_rule( '^beplus-scss/([^/]+)$', 'index.php?beplus_scss_file=$matches[1]', 'top' );
		add_filter(
			'query_vars',
			static function ( array $vars ): array {
				$vars[] = 'beplus_scss_file';

				return $vars;
			}
		);
	}

	public function onEnqueueScripts(): void {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}
		$settings = SettingsPage::currentSettings();
		$scssDir  = $settings['scss_dir'];
		$cssDir   = $settings['css_dir'];
		if ( '' === $scssDir || '' === $cssDir || ! is_dir( $scssDir ) || ! is_dir( $cssDir ) ) {
			return;
		}

		if ( 'auto' === $settings['compile_mode'] && ! self::$autoCompiled ) {
			self::$autoCompiled = true;
			$this->compileChangedEntries( $settings );
		}

		/** @var Style[] $styles */
		$styles = $this->buildStyles( $settings, $cssDir );
		$styles = apply_filters( 'beplus_scss/enqueue', $styles );
		if ( ! is_array( $styles ) ) {
			return;
		}
		foreach ( $styles as $style ) {
			if ( $style instanceof Style ) {
				wp_enqueue_style( $style->getHandle(), $style->getUrl(), [], (string) $style->getVersion() );
			}
		}
	}

	public function handleCompileNow(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'beplus-scss' ) );
		}
		check_admin_referer( SettingsPage::NONCE_FIELD );

		$settings = SettingsPage::currentSettings();
		$compiled = $this->compileAllEntries( $settings );
		$msg      = $compiled ? 'compiled' : 'error';

		wp_safe_redirect( add_query_arg( 'msg', $msg, admin_url( 'admin.php?page=' . SettingsPage::MENU_SLUG ) ) );
		exit;
	}

	public function serveFile(): void {
		$file = get_query_var( 'beplus_scss_file' );
		if ( ! is_string( $file ) || '' === $file ) {
			return;
		}
		$file     = rawurldecode( (string) $file );
		$settings = SettingsPage::currentSettings();
		$cssDir   = $settings['css_dir'];
		if ( '' === $cssDir ) {
			return;
		}

		if ( ! $this->isServeable( $file ) ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		$realDir = realpath( $cssDir );
		$real    = realpath( $cssDir . '/' . $file );
		if ( false === $real || false === $realDir || 0 !== strpos( $real, $realDir . '/' ) ) {
			status_header( 404 );
			exit;
		}

		$mime = 'map' === pathinfo( $real, PATHINFO_EXTENSION ) ? 'application/json' : 'text/css';
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . (string) filesize( $real ) );
		readfile( $real );
		exit;
	}

	private function isServeable( string $file ): bool {
		$ext = pathinfo( $file, PATHINFO_EXTENSION );
		if ( ! in_array( $ext, [ 'css', 'map' ], true ) ) {
			return false;
		}

		return 0 !== strpos( $file, '.' );
	}

	/**
	 * @param ScssSettings $settings
	 * @return Style[]
	 */
	private function buildStyles( array $settings, string $cssDir ): array {
		$webRoot = ! empty( $settings['web_root'] );
		$baseUrl = $webRoot
			? home_url( '/' ) . ltrim( substr( $cssDir, strlen( ABSPATH ) ), '/' )
			: home_url( '/beplus-scss' );

		return Enqueue::styles( $cssDir, $webRoot, $baseUrl );
	}

	/**
	 * @param ScssSettings $settings
	 */
	private function compileChangedEntries( array $settings ): void {
		$entries = Scanner::scan( $settings['scss_dir'] );
		/** @var array<array-key, mixed> $storedOption */
		$storedOption = get_option( self::FINGERPRINTS_OPTION, [] );
		$stored       = array_filter( $storedOption, 'is_string' );
		/** @var array<string, string> $stored */
		$stored  = array_filter( $stored, 'is_string', ARRAY_FILTER_USE_BOTH );
		$changed = Detector::changedEntries( $entries, $stored, $settings['scss_dir'] );
		if ( [] === $changed ) {
			return;
		}
		$this->compileEntries( $settings, $entries, $changed );
	}

	/**
	 * @param ScssSettings $settings
	 */
	private function compileAllEntries( array $settings ): bool {
		$entries = Scanner::scan( $settings['scss_dir'] );
		$this->compileEntries( $settings, $entries, $entries );

		return ! $this->hasCompileError();
	}

	/**
	 * @param ScssSettings $settings
	 * @param string[]                   $entries
	 * @param string[]                   $toCompile
	 */
	private function compileEntries( array $settings, array $entries, array $toCompile ): void {
		/** @var CompilerInterface $compiler */
		$compiler = apply_filters( 'beplus_scss/compiler', new ScssPhpCompiler() );
		/** @var array<mixed> $rawImportPaths */
		$rawImportPaths = apply_filters( 'beplus_scss/import_paths', [ $settings['scss_dir'] ] );
		$importPaths    = array_values( array_filter( $rawImportPaths, 'is_string' ) );
		if ( [] === $importPaths ) {
			$importPaths = [ $settings['scss_dir'] ];
		}
		$config       = new CompileConfig(
			$importPaths,
			! empty( $settings['minify'] ),
			! empty( $settings['source_map'] )
		);
		$scssDir      = $settings['scss_dir'];
		$cssDir       = $settings['css_dir'];
		$fingerprints = get_option( self::FINGERPRINTS_OPTION, [] );
		$fingerprints = is_array( $fingerprints ) ? $fingerprints : [];

		foreach ( $entries as $entry ) {
			$relPath = ltrim( str_replace( rtrim( $scssDir, '/' ) . '/', '', $entry ), '/' );

			if ( false !== apply_filters( 'beplus_scss/exclude', false, $entry, $relPath ) ) {
				continue;
			}

			if ( in_array( $entry, $toCompile, true ) ) {
				try {
					/** @var CompiledResult $result */
					$result = $compiler->compile( $entry, $config );
					$dest   = apply_filters(
						'beplus_scss/write_path',
						Writer::mirrorPath( $entry, $scssDir, $cssDir ),
						$entry,
						$scssDir,
						$cssDir
					);
					$dest   = is_string( $dest ) && '' !== $dest ? $dest : Writer::mirrorPath( $entry, $scssDir, $cssDir );
					Writer::write( $result->getCss(), $cssDir . '/' . $dest );
					if ( null !== $result->getMap() ) {
						Writer::writeMap( $result->getMap(), $cssDir . '/' . $dest );
					}
					$this->clearError( $relPath );
				} catch ( \Throwable $e ) {
					$this->setError( $relPath, $e->getMessage() );
					$wpError = new \WP_Error( 'beplus_scss_compile', $e->getMessage(), [ 'entry' => $relPath ] );
					apply_filters( 'beplus_scss/error', $wpError );
				}
			}
			$fingerprints[ $relPath ] = Scanner::fingerprint( $scssDir );
		}
		update_option( self::FINGERPRINTS_OPTION, $fingerprints );
	}

	private function clearError( string $entry ): void {
		/** @var array<array-key, mixed> $error */
		$error = get_option( self::LAST_ERROR_OPTION, [] );
		if ( isset( $error['entry'] ) && $error['entry'] === $entry ) {
			delete_option( self::LAST_ERROR_OPTION );
		}
	}

	private function setError( string $entry, string $message ): void {
		update_option(
			self::LAST_ERROR_OPTION,
			[
				'time'    => time(),
				'entry'   => $entry,
				'message' => $message,
			]
		);
	}

	private function hasCompileError(): bool {
		return false !== get_option( self::LAST_ERROR_OPTION, false );
	}
}
