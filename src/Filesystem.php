<?php

namespace Beplus\ScssCompiler;

/**
 * Thin wrapper around WP_Filesystem.
 *
 * This is the single WordPress-touching access point for file operations in the
 * plugin. Every filesystem call that needs to satisfy the Plugin Check
 * "direct filesystem" guidance (and would otherwise be a raw PHP call such as
 * mkdir(), rename(), unlink() or file_get_contents()) is routed through here.
 */
final class Filesystem {

	/**
	 * @return \WP_Filesystem_Base|null The transport instance, or null when WP_Filesystem() fails.
	 */
	/**
	 * @var \WP_Filesystem_Base|null $fs
	 */
	private static $fs;

	/**
	 * @return \WP_Filesystem_Base|null The transport instance, or null when WP_Filesystem() fails.
	 */
	private static function fs(): ?\WP_Filesystem_Base {
		if ( ! isset( self::$fs ) ) {
			global $wp_filesystem;

			// WP_Filesystem() (and the class files it needs) are only loaded in the
			// admin; on the frontend (where auto-compile runs) they are absent, and
			// get_filesystem_method() may refuse 'direct' when the file owner does
			// not match the web server user. Fall back to instantiating
			// WP_Filesystem_Direct ourselves so file operations work everywhere.
			if ( function_exists( 'WP_Filesystem' ) ) {
				WP_Filesystem();
				if ( $wp_filesystem instanceof \WP_Filesystem_Base ) {
					self::$fs = $wp_filesystem;

					return self::$fs;
				}
			}

			if ( defined( 'ABSPATH' ) ) {
				$base   = ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
				$direct = ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
				if ( file_exists( $base ) && file_exists( $direct ) ) {
					require_once $base;   // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingCustomConstant -- Core file load via ABSPATH.
					require_once $direct; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingCustomConstant -- Core file load via ABSPATH.
					self::$fs = new \WP_Filesystem_Direct( null );

					return self::$fs;
				}
			}

			self::$fs = null;
		}

		return self::$fs;
	}

	public static function isDir( string $path ): bool {
		$fs = self::fs();

		return null !== $fs && $fs->is_dir( $path );
	}

	public static function isReadable( string $path ): bool {
		$fs = self::fs();

		return null !== $fs && $fs->is_readable( $path );
	}

	public static function isWritable( string $path ): bool {
		$fs = self::fs();

		return null !== $fs && $fs->is_writable( $path );
	}

	public static function mkdir( string $path ): bool {
		$fs = self::fs();
		if ( null === $fs ) {
			return false;
		}

		if ( $fs->is_dir( $path ) ) {
			return true;
		}

		$current  = '';
		$segments = preg_split( '#[/\\\\]#', $path );
		$segments = is_array( $segments ) ? $segments : [];

		foreach ( $segments as $segment ) {
			if ( '' === $segment ) {
				continue;
			}
			$current = $current . '/' . $segment;
			if ( ! $fs->is_dir( $current ) ) {
				$fs->mkdir( $current );
			}
		}

		return $fs->is_dir( $path );
	}

	/**
	 * @return string|false File contents, or false on failure.
	 */
	public static function getContents( string $path ) {
		$fs = self::fs();

		return null !== $fs ? $fs->get_contents( $path ) : false;
	}

	public static function putContents( string $path, string $contents ): bool {
		$fs = self::fs();

		return null !== $fs && $fs->put_contents( $path, $contents );
	}

	/**
	 * Move a file. The overwrite flag is on so an existing destination (e.g. a
	 * previously compiled CSS file) is replaced. WP_Filesystem::move() maps to
	 * rename() where the transport supports it (atomic) and falls back to
	 * copy+delete (after deleting the destination) elsewhere.
	 */
	public static function move( string $source, string $destination ): bool {
		$fs = self::fs();

		return null !== $fs && $fs->move( $source, $destination, true );
	}

	public static function delete( string $path ): bool {
		$fs = self::fs();

		return null !== $fs && $fs->delete( $path );
	}
}
