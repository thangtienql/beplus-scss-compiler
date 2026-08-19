<?php

namespace Beplus\ScssCompiler\Tests\Unit;

use Beplus\ScssCompiler\Detector;
use Beplus\ScssCompiler\Scanner;
use PHPUnit\Framework\TestCase;

final class DetectorTest extends TestCase {

	private string $scssDir;

	/** @var string[] */
	private array $entries;

	protected function setUp(): void {
		parent::setUp();
		$this->scssDir = dirname( __DIR__ ) . '/fixtures/scss';
		$this->entries = [
			$this->scssDir . '/main.scss',
			$this->scssDir . '/modules/card.scss',
		];
	}

	public function test_returns_entries_missing_in_stored(): void {
		$fp      = Scanner::fingerprint( $this->scssDir );
		$stored  = [ 'main.scss' => $fp ];
		$current = Detector::changedEntries( $this->entries, $stored, $this->scssDir );

		self::assertSame( [ $this->scssDir . '/modules/card.scss' ], $current );
	}

	public function test_returns_entries_with_stale_fingerprint(): void {
		$stored  = [
			'main.scss'         => 'stale',
			'modules/card.scss' => Scanner::fingerprint( $this->scssDir ),
		];
		$current = Detector::changedEntries( $this->entries, $stored, $this->scssDir );

		self::assertSame( [ $this->scssDir . '/main.scss' ], $current );
	}

	public function test_returns_empty_when_all_fingerprints_match(): void {
		$fp      = Scanner::fingerprint( $this->scssDir );
		$stored  = [
			'main.scss'         => $fp,
			'modules/card.scss' => $fp,
		];
		$current = Detector::changedEntries( $this->entries, $stored, $this->scssDir );

		self::assertSame( [], $current );
	}
}
