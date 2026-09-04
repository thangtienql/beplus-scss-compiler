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
 * @phpstan-import-type ScssPair from SettingsPage
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
		if ( [] === $settings['pairs'] ) {
			return;
		}

		if ( 'auto' === $settings['compile_mode'] && ! self::$autoCompiled ) {
			self::$autoCompiled = true;
			foreach ( $settings['pairs'] as $pairId => $pair ) {
				$scssDir = SettingsPage::absPath( $pair['scss_dir'] );
				$cssDir  = SettingsPage::absPath( $pair['css_dir'] );
				if ( ! is_dir( $scssDir ) || ! is_dir( $cssDir ) ) {
					continue;
				}
				$this->compileChangedEntries( $settings, $pairId, $scssDir, $cssDir );
			}
		}

		if ( ! $settings['enqueue'] ) {
			return;
		}

		/** @var Style[] $allStyles */
		$allStyles = [];

		foreach ( $settings['pairs'] as $pairId => $pair ) {
			$scssDir = SettingsPage::absPath( $pair['scss_dir'] );
			$cssDir  = SettingsPage::absPath( $pair['css_dir'] );
			if ( ! is_dir( $scssDir ) || ! is_dir( $cssDir ) ) {
				continue;
			}
			$allStyles = array_merge( $allStyles, $this->buildStyles( $cssDir, $pairId ) );
		}

		$styles = apply_filters( 'beplus_scss/enqueue', $allStyles );
		if ( ! is_array( $styles ) ) {
			return;
		}
		$styles = Enqueue::uniqueByUrl( $styles );
		foreach ( $styles as $style ) {
			if ( $style instanceof Style ) {
				wp_enqueue_style( $style->getHandle(), $style->getUrl(), [], (string) $style->getVersion() );
			}
		}
	}

	public function handleCompileNow(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'beplus-scss-compiler' ) );
		}
		check_admin_referer( SettingsPage::NONCE_FIELD );

		$settings = SettingsPage::currentSettings();
		if ( [] === $settings['pairs'] ) {
			$msg = 'error';
		} else {
			$msg = 'compiled';
			foreach ( $settings['pairs'] as $pairId => $pair ) {
				$scssDir = SettingsPage::absPath( $pair['scss_dir'] );
				$cssDir  = SettingsPage::absPath( $pair['css_dir'] );
				if ( ! $this->compileAllEntries( $settings, $pairId, $scssDir, $cssDir ) ) {
					$msg = 'error';
				}
			}
		}

		wp_safe_redirect( add_query_arg( 'msg', $msg, admin_url( 'admin.php?page=' . SettingsPage::MENU_SLUG ) ) );
		exit;
	}

	/**
	 * @return Style[]
	 */
	private function buildStyles( string $cssDir, int $pairId ): array {
		$baseUrl = home_url( '/' ) . ltrim( substr( $cssDir, strlen( ABSPATH ) ), '/' );

		return Enqueue::styles( $cssDir, $baseUrl, $this->compiledFiles(), $pairId );
	}

	/**
	 * @param ScssSettings $settings
	 */
	private function compileChangedEntries( array $settings, int $pairId, string $scssDir, string $cssDir ): void {
		$entries   = Scanner::scan( $scssDir );
		$storedOpt = get_option( self::FINGERPRINTS_OPTION, [] );
		$stored    = $this->pairStored( is_array( $storedOpt ) ? $storedOpt : [], $pairId );
		$changed   = Detector::changedEntries( $entries, $stored, $scssDir );
		if ( [] === $changed ) {
			return;
		}
		$this->compileEntries( $settings, $entries, $changed, $pairId, $scssDir, $cssDir );
	}

	/**
	 * @param ScssSettings $settings
	 */
	private function compileAllEntries( array $settings, int $pairId, string $scssDir, string $cssDir ): bool {
		$entries = Scanner::scan( $scssDir );
		$this->compileEntries( $settings, $entries, $entries, $pairId, $scssDir, $cssDir );

		return ! $this->hasCompileError();
	}

	/**
	 * Extract the stored fingerprints relevant to a pair as `relPath → value`.
	 * Stored keys are "&lt;pairId&gt;:&lt;relPath&gt;" (plus, for pair 0, legacy
	 * unprefixed keys); the prefix is stripped so the returned map matches the
	 * relPath keys Detector::changedEntries() looks up.
	 *
	 * @param array<array-key, mixed> $storedOption
	 * @return array<string, string>
	 */
	private function pairStored( array $storedOption, int $pairId ): array {
		$prefix = $pairId . ':';
		$stored = [];
		foreach ( $storedOption as $key => $value ) {
			if ( ! is_string( $key ) || ! is_string( $value ) ) {
				continue;
			}
			$isPrefixed = 0 === strpos( $key, $prefix );
			$isLegacy   = 0 === $pairId && false === strpos( $key, ':' );
			if ( $isPrefixed ) {
				$stored[ substr( $key, strlen( $prefix ) ) ] = $value;
			} elseif ( $isLegacy ) {
				$stored[ $key ] = $value;
			}
		}

		return $stored;
	}

	/**
	 * @param ScssSettings $settings
	 * @param string[]     $entries
	 * @param string[]     $toCompile
	 */
	private function compileEntries( array $settings, array $entries, array $toCompile, int $pairId, string $scssDir, string $cssDir ): void {
		/** @var CompilerInterface $compiler */
		$compiler = apply_filters( 'beplus_scss/compiler', new ScssPhpCompiler() );
		/** @var array<mixed> $rawImportPaths */
		$rawImportPaths = apply_filters( 'beplus_scss/import_paths', [ $scssDir ] );
		$importPaths    = array_values( array_filter( $rawImportPaths, 'is_string' ) );
		if ( [] === $importPaths ) {
			$importPaths = [ $scssDir ];
		}
		$config          = new CompileConfig(
			$importPaths,
			! empty( $settings['minify'] ),
			! empty( $settings['source_map'] )
		);
		$rawFingerprints = get_option( self::FINGERPRINTS_OPTION, [] );
		$fingerprints    = is_array( $rawFingerprints ) ? $rawFingerprints : [];
		$prefix          = $pairId . ':';

		foreach ( $entries as $entry ) {
			$relPath  = ltrim( str_replace( rtrim( $scssDir, '/' ) . '/', '', $entry ), '/' );
			$entryKey = $prefix . $relPath;

			if ( false !== apply_filters( 'beplus_scss/exclude', false, $entry, $relPath, $pairId ) ) {
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
						$cssDir,
						$pairId
					);
					$dest   = is_string( $dest ) && '' !== $dest ? $dest : Writer::mirrorPath( $entry, $scssDir, $cssDir );
					Writer::write( $result->getCss(), $cssDir . '/' . $dest );
					if ( null !== $result->getMap() ) {
						Writer::writeMap( $result->getMap(), $cssDir . '/' . $dest );
					}
					$this->registerCompiledPath( $prefix . ltrim( $dest, '/' ) );
					$this->clearError( $entryKey );
				} catch ( \Throwable $e ) {
					$this->setError( $entryKey, $e->getMessage() );
					$wpError = new \WP_Error(
						'beplus_scss_compile',
						$e->getMessage(),
						[
							'entry'   => $entryKey,
							'pair_id' => $pairId,
						]
					);
					apply_filters( 'beplus_scss/error', $wpError, $pairId );
				}
			}
			$fingerprints[ $entryKey ] = Scanner::fingerprint( $scssDir );
		}
		update_option( self::FINGERPRINTS_OPTION, $fingerprints );
		$this->pruneCompiledPaths( $cssDir, $pairId );
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

	private function registerCompiledPath( string $key ): void {
		$compiled = $this->compiledFiles();
		if ( ! in_array( $key, $compiled, true ) ) {
			$compiled[] = $key;
			update_option( self::COMPILED_OPTION, array_values( $compiled ) );
		}
	}

	private function pruneCompiledPaths( string $cssDir, int $pairId ): void {
		$prefix   = $pairId . ':';
		$compiled = $this->compiledFiles();
		$kept     = array_values(
			array_filter(
				$compiled,
				static function ( string $key ) use ( $cssDir, $prefix ): bool {
					if ( 0 !== strpos( $key, $prefix ) ) {
						return true;
					}
					$relPath = substr( $key, strlen( $prefix ) );

					return is_file( $cssDir . '/' . $relPath );
				}
			)
		);
		if ( $kept !== $compiled ) {
			update_option( self::COMPILED_OPTION, $kept );
		}
	}

	private function clearError( string $entryKey ): void {
		/** @var array<array-key, mixed> $error */
		$error = get_option( self::LAST_ERROR_OPTION, [] );
		if ( isset( $error['entry'] ) && $error['entry'] === $entryKey ) {
			delete_option( self::LAST_ERROR_OPTION );
		}
	}

	private function setError( string $entryKey, string $message ): void {
		update_option(
			self::LAST_ERROR_OPTION,
			[
				'time'    => time(),
				'entry'   => $entryKey,
				'message' => $message,
			]
		);
	}

	private function hasCompileError(): bool {
		return false !== get_option( self::LAST_ERROR_OPTION, false );
	}
}
