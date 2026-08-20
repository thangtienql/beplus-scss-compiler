<?php

namespace Beplus\ScssCompiler;

final class Writer {

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $cssDir is part of the pinned API in Plugin.md, kept for symmetry with the write_path filter signature.
	public static function mirrorPath( string $entry, string $scssDir, string $cssDir ): string {
		$prefix = rtrim( $scssDir, '/' ) . '/';
		$rel    = ltrim( str_replace( $prefix, '', $entry ), '/' );

		$mapped = preg_replace( '/\.scss$/', '.css', $rel );

		return $mapped ?? $rel;
	}

	public static function write( string $content, string $absPath ): bool {
		return self::atomicWrite( $content, $absPath );
	}

	public static function writeMap( string $content, string $absPath ): bool {
		return self::atomicWrite( $content, $absPath . '.map' );
	}

	private static function atomicWrite( string $content, string $absPath ): bool {
		// The Writer is a WordPress-agnostic layer (Plugin.md): it must not call
		// WP_Filesystem. Direct filesystem access is intentional — atomic rename
		// (temp file + rename) is not reliably supported by WP_Filesystem, and
		// atomic writes guarantee the previous CSS stays intact on compile error.
		$dir = dirname( $absPath );
		if ( ! is_dir( $dir ) ) {
			@mkdir( $dir, 0777, true );
			if ( ! is_dir( $dir ) ) {
				return false;
			}
		}

		$tmp = $dir . '/.' . basename( $absPath ) . '.tmp.' . uniqid();
		if ( false === file_put_contents( $tmp, $content ) ) {
			return false;
		}

		if ( ! @rename( $tmp, $absPath ) ) {
			@unlink( $tmp );

			return false;
		}

		return true;
	}
}
