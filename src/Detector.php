<?php

namespace Beplus\ScssCompiler;

final class Detector {

	/**
	 * @param string[]            $entries             Absolute `.scss` entry paths.
	 * @param array<string,string> $storedFingerprints Entry-relative path → stored fingerprint.
	 * @return string[] Entries whose fingerprint differs from the stored one.
	 */
	public static function changedEntries( array $entries, array $storedFingerprints, string $scssDir ): array {
		$current = Scanner::fingerprint( $scssDir );
		$changed = [];

		foreach ( $entries as $entry ) {
			$relPath = ltrim( str_replace( rtrim( $scssDir, '/' ) . '/', '', (string) $entry ), '/' );
			if ( ! isset( $storedFingerprints[ $relPath ] ) || $storedFingerprints[ $relPath ] !== $current ) {
				$changed[] = $entry;
			}
		}

		return $changed;
	}
}
