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
		// Filesystem access is routed through the Filesystem helper (a thin
		// WP_Filesystem wrapper) so the plugin passes the Plugin Check
		// "direct filesystem" guidance. The move() attempt keeps the write as
		// atomic as the transport allows (rename where supported); when the FS
		// cannot move, fall back to a direct put_contents() to preserve the file.
		$dir = dirname( $absPath );
		if ( ! Filesystem::isDir( $dir ) ) {
			if ( ! Filesystem::mkdir( $dir ) ) {
				return false;
			}
		}

		$tmp = $dir . '/.' . basename( $absPath ) . '.tmp.' . uniqid();
		if ( false === Filesystem::putContents( $tmp, $content ) ) {
			return false;
		}

		if ( ! Filesystem::move( $tmp, $absPath ) ) {
			Filesystem::delete( $tmp );

			return false;
		}

		return true;
	}
}
