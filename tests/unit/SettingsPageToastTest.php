<?php

namespace Beplus\ScssCompiler\Tests\Unit;

use Beplus\ScssCompiler\Settings\SettingsPage;
use PHPUnit\Framework\TestCase;

final class SettingsPageToastTest extends TestCase {

	public function test_errors_win_over_compile_toast(): void {
		$errors = [ $this->error( 'bad_scss_dir', 'SCSS directory does not exist.' ) ];
		$toasts = SettingsPage::toastList( $errors, 'compiled', 'compiled msg', 'failed msg', 'Settings saved.' );

		self::assertSame(
			[
				[
					'type'    => 'error',
					'message' => 'SCSS directory does not exist.',
				],
			],
			$toasts
		);
	}

	public function test_errors_win_over_settings_saved(): void {
		$errors = [
			$this->saved(),
			$this->error( 'bad_css_dir', 'CSS directory is not writable.' ),
		];
		$toasts = SettingsPage::toastList( $errors, '', 'compiled msg', 'failed msg', 'Settings saved.' );

		self::assertSame(
			[
				[
					'type'    => 'error',
					'message' => 'CSS directory is not writable.',
				],
			],
			$toasts
		);
	}

	public function test_settings_saved_toast_shown_when_no_errors(): void {
		$toasts = SettingsPage::toastList( [ $this->saved() ], '', 'compiled msg', 'failed msg', 'Settings saved.' );

		self::assertSame(
			[
				[
					'type'    => 'success',
					'message' => 'Settings saved.',
				],
			],
			$toasts
		);
	}

	public function test_compile_success_toast_for_compiled_msg(): void {
		$toasts = SettingsPage::toastList( [], 'compiled', 'compiled msg', 'failed msg', 'Settings saved.' );

		self::assertSame(
			[
				[
					'type'    => 'success',
					'message' => 'compiled msg',
				],
			],
			$toasts
		);
	}

	public function test_compile_error_toast_for_error_msg(): void {
		$toasts = SettingsPage::toastList( [], 'error', 'compiled msg', 'failed msg', 'Settings saved.' );

		self::assertSame(
			[
				[
					'type'    => 'error',
					'message' => 'failed msg',
				],
			],
			$toasts
		);
	}

	public function test_no_toasts_when_nothing_pending(): void {
		self::assertSame( [], SettingsPage::toastList( [], '', 'compiled msg', 'failed msg', 'Settings saved.' ) );
	}

	public function test_multiple_errors_become_multiple_toasts(): void {
		$errors = [
			$this->error( 'bad_scss_dir', 'SCSS directory does not exist.' ),
			$this->error( 'bad_css_dir', 'CSS directory is not writable.' ),
		];
		$toasts = SettingsPage::toastList( $errors, 'compiled', 'compiled msg', 'failed msg', 'Settings saved.' );

		self::assertCount( 2, $toasts );
		self::assertSame( 'error', $toasts[0]['type'] );
		self::assertSame( 'SCSS directory does not exist.', $toasts[0]['message'] );
		self::assertSame( 'error', $toasts[1]['type'] );
		self::assertSame( 'CSS directory is not writable.', $toasts[1]['message'] );
	}

	/**
	 * @return array{setting:string, code:string, message:string, type:string}
	 */
	private function error( string $code, string $message ): array {
		return [
			'setting' => 'beplus_scss_settings',
			'code'    => $code,
			'message' => $message,
			'type'    => 'error',
		];
	}

	/**
	 * @return array{setting:string, code:string, message:string, type:string}
	 */
	private function saved(): array {
		return [
			'setting' => 'general',
			'code'    => 'settings_updated',
			'message' => 'Settings saved.',
			'type'    => 'success',
		];
	}
}
