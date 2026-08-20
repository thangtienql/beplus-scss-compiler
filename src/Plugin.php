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
	const COMPILED_OPTION     = 'beplus_scss_compiled';
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

		register_activation_hook( BE_PLUS_SCSS_COMPILER_MAIN_FILE, [ $this, 'activate' ] );
	}

	public function activate(): void {
		update_option( self::VERSION_OPTION, self::VERSION );
	}

	public function onEnqueueScripts(): void {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}
		$settings = SettingsPage::currentSettings();
		if ( '' === $settings['scss_dir'] || '' === $settings['css_dir'] ) {
			return;
		}
		$scssDir = SettingsPage::absPath( $settings['scss_dir'] );
		$cssDir  = SettingsPage::absPath( $settings['css_dir'] );
		if ( ! is_dir( $scssDir ) || ! is_dir( $cssDir ) ) {
			return;
		}

		if ( 'auto' === $settings['compile_mode'] && ! self::$autoCompiled ) {
			self::$autoCompiled = true;
			$this->compileChangedEntries( $settings, $scssDir, $cssDir );
		}

		if ( ! $settings['enqueue'] ) {
			return;
		}

		/** @var Style[] $styles */
		$styles = $this->buildStyles( $cssDir );
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
		if ( '' === $settings['scss_dir'] || '' === $settings['css_dir'] ) {
			$msg = 'error';
		} else {
			$scssDir = SettingsPage::absPath( $settings['scss_dir'] );
			$cssDir  = SettingsPage::absPath( $settings['css_dir'] );
			$msg     = $this->compileAllEntries( $settings, $scssDir, $cssDir ) ? 'compiled' : 'error';
		}

		wp_safe_redirect( add_query_arg( 'msg', $msg, admin_url( 'admin.php?page=' . SettingsPage::MENU_SLUG ) ) );
		exit;
	}

	/**
	 * @return Style[]
	 */
	private function buildStyles( string $cssDir ): array {
		$baseUrl = home_url( '/' ) . ltrim( substr( $cssDir, strlen( ABSPATH ) ), '/' );

		return Enqueue::styles( $cssDir, $baseUrl, $this->compiledFiles() );
	}

	/**
	 * @param ScssSettings $settings
	 */
	private function compileChangedEntries( array $settings, string $scssDir, string $cssDir ): void {
		$entries = Scanner::scan( $scssDir );
		/** @var array<array-key, mixed> $storedOption */
		$storedOption = get_option( self::FINGERPRINTS_OPTION, [] );
		/** @var array<string, string> $stored */
		$stored  = array_filter( $storedOption, 'is_string' );
		$changed = Detector::changedEntries( $entries, $stored, $scssDir );
		if ( [] === $changed ) {
			return;
		}
		$this->compileEntries( $settings, $entries, $changed, $scssDir, $cssDir );
	}

	/**
	 * @param ScssSettings $settings
	 */
	private function compileAllEntries( array $settings, string $scssDir, string $cssDir ): bool {
		$entries = Scanner::scan( $scssDir );
		$this->compileEntries( $settings, $entries, $entries, $scssDir, $cssDir );

		return ! $this->hasCompileError();
	}

	/**
	 * @param ScssSettings $settings
	 * @param string[]                   $entries
	 * @param string[]                   $toCompile
	 */
	private function compileEntries( array $settings, array $entries, array $toCompile, string $scssDir, string $cssDir ): void {
		/** @var CompilerInterface $compiler */
		$compiler = apply_filters( 'beplus_scss/compiler', new ScssPhpCompiler() );
		/** @var array<mixed> $rawImportPaths */
		$rawImportPaths = apply_filters( 'beplus_scss/import_paths', [ $scssDir ] );
		$importPaths    = array_values( array_filter( $rawImportPaths, 'is_string' ) );
		if ( [] === $importPaths ) {
			$importPaths = [ $scssDir ];
		}
		$config       = new CompileConfig(
			$importPaths,
			! empty( $settings['minify'] ),
			! empty( $settings['source_map'] )
		);
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
					$this->registerCompiledPath( ltrim( $dest, '/' ) );
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
		$this->pruneCompiledPaths( $cssDir );
	}

	/**
	 * @return string[]
	 */
	private function compiledFiles(): array {
		$stored = get_option( self::COMPILED_OPTION, [] );
		if ( ! is_array( $stored ) ) {
			return [];
		}

		return array_values( array_filter( $stored, 'is_string' ) );
	}

	private function registerCompiledPath( string $relPath ): void {
		$compiled = $this->compiledFiles();
		if ( ! in_array( $relPath, $compiled, true ) ) {
			$compiled[] = $relPath;
			update_option( self::COMPILED_OPTION, array_values( $compiled ) );
		}
	}

	private function pruneCompiledPaths( string $cssDir ): void {
		$compiled = $this->compiledFiles();
		$kept     = array_values(
			array_filter(
				$compiled,
				static function ( string $relPath ) use ( $cssDir ): bool {
					return is_file( $cssDir . '/' . $relPath );
				}
			)
		);
		if ( $kept !== $compiled ) {
			update_option( self::COMPILED_OPTION, $kept );
		}
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
