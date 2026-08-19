<?php

namespace Beplus\ScssCompiler;

final class Scanner {

	/**
	 * @return string[] Absolute paths of compile-worthy `.scss` entries.
	 */
	public static function scan( string $scssDir ): array {
		$entries = [];
		$files   = self::splFileInfoIterator( $scssDir );

		foreach ( $files as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$relPath = self::relativePath( $scssDir, $file->getPathname() );
			if ( self::isHiddenSegment( $relPath ) ) {
				continue;
			}
			if ( 'scss' !== $file->getExtension() ) {
				continue;
			}
			if ( 0 === strpos( $file->getBasename(), '_' ) ) {
				continue;
			}
			$entries[] = $file->getPathname();
		}

		sort( $entries );

		return $entries;
	}

	public static function fingerprint( string $scssDir ): string {
		$lines = [];
		$files = self::splFileInfoIterator( $scssDir );

		foreach ( $files as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			if ( 'scss' !== $file->getExtension() ) {
				continue;
			}
			$relPath = self::relativePath( $scssDir, $file->getPathname() );
			if ( self::isHiddenSegment( $relPath ) ) {
				continue;
			}
			$lines[] = sprintf( "%s:%d:%d\n", $relPath, $file->getMTime(), $file->getSize() );
		}

		sort( $lines );

		return md5( implode( '', $lines ) );
	}

	private static function relativePath( string $scssDir, string $absPath ): string {
		$prefix = rtrim( $scssDir, '/' ) . '/';

		return ltrim( str_replace( $prefix, '', $absPath ), '/' );
	}

	private static function isHiddenSegment( string $relPath ): bool {
		foreach ( explode( '/', $relPath ) as $segment ) {
			if ( 0 === strpos( $segment, '.' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Recursively yields the `SplFileInfo` for every entry under a directory.
	 *
	 * @return \Generator<int, \SplFileInfo, void, void>
	 */
	private static function splFileInfoIterator( string $dir ): \Generator {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( $file instanceof \SplFileInfo ) {
				yield $file;
			}
		}
	}
}
