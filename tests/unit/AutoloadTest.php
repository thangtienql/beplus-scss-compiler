<?php

namespace Beplus\ScssCompiler\Tests\Unit;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the PSR-4 autoloader maps the plugin namespace to src/.
 */
final class AutoloadTest extends TestCase {

	public function test_namespace_maps_to_src_directory(): void {
		foreach ( ClassLoader::getRegisteredLoaders() as $loader ) {
			$prefixes = $loader->getPrefixesPsr4();

			if ( ! isset( $prefixes['Beplus\\ScssCompiler\\'] ) ) {
				continue;
			}

			self::assertStringEndsWith( '/src', $prefixes['Beplus\\ScssCompiler\\'][0] );
			return;
		}

		self::fail( 'Beplus\\ScssCompiler\\ PSR-4 prefix is not registered.' );
	}
}
