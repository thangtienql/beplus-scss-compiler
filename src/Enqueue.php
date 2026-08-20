<?php

namespace Beplus\ScssCompiler;

use Beplus\ScssCompiler\Value\Style;

final class Enqueue {

	/**
	 * @param string[] $registeredFiles entries `"<pairId>:<relative_path>"` from $cssDir the plugin actually compiled
	 * @param int $pairId pair index; pair 0 also accepts legacy unprefixed entries
	 * @return Style[] Styles ready to be enqueued, ordered by handle.
	 */
	public static function styles( string $cssDir, string $baseUrl, array $registeredFiles, int $pairId ): array {
		$styles = [];
		$files  = self::splFileInfoIterator( $cssDir );
		$prefix = $pairId . ':';

		foreach ( $files as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$relPath = ltrim( str_replace( rtrim( $cssDir, '/' ) . '/', '', $file->getPathname() ), '/' );
			if ( self::isHiddenSegment( $relPath ) ) {
				continue;
			}
			if ( 'css' !== $file->getExtension() ) {
				continue;
			}
			if ( ! self::isRegistered( $relPath, $registeredFiles, $prefix, $pairId ) ) {
				continue;
			}
			$handle   = 'beplus-scss-' . $pairId . '-' . str_replace( [ '/', '.' ], [ '-', '' ], substr( $relPath, 0, -4 ) );
			$url      = rtrim( $baseUrl, '/' ) . '/' . $relPath;
			$styles[] = new Style( $handle, $url, (int) $file->getMTime() );
		}

		usort(
			$styles,
			static function ( Style $a, Style $b ): int {
				return strcmp( $a->getHandle(), $b->getHandle() );
			}
		);

		return $styles;
	}

	/**
	 * @param string[] $registeredFiles
	 */
	private static function isRegistered( string $relPath, array $registeredFiles, string $prefix, int $pairId ): bool {
		if ( in_array( $prefix . $relPath, $registeredFiles, true ) ) {
			return true;
		}
		if ( 0 === $pairId && in_array( $relPath, $registeredFiles, true ) ) {
			return true;
		}

		return false;
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
