<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Tests\Unit\Packaging;

use FilesystemIterator;
use PharData;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class MakefileTest extends TestCase {
	/** @var list<string> */
	private const RUNTIME_FILES = [
		'appinfo/info.xml',
		'appinfo/routes.php',
		'lib/Federation/FolderCloudFederationProvider.php',
		'templates/index.php',
		'img/app.svg',
		'js/ocmremotewebapp-main.mjs',
		'js/ocmremotewebapp-main.mjs.license',
		'js/ocmremotewebapp-main.mjs.map',
		'css/ocmremotewebapp-main.css',
		'css/main-example.chunk.css',
		'css/fonts/example.woff2',
		'LICENSE',
		'CHANGELOG.md',
		'README.md',
		'openapi.json',
	];

	private string $fixture;
	private string $artifacts;

	protected function setUp(): void {
		parent::setUp();
		$root = getenv('OCM_PACKAGING_TEST_TMPDIR') ?: sys_get_temp_dir();
		$this->fixture = $root . '/ocm-package-' . bin2hex(random_bytes(8));
		$this->artifacts = $this->fixture . '/out artifacts';
		mkdir($this->fixture, 0700, true);
		copy(dirname(__DIR__, 3) . '/Makefile', $this->fixture . '/Makefile');
		foreach (self::RUNTIME_FILES as $path) {
			$this->writeFixture($path, 'runtime file');
		}
		$this->writeFixture('appinfo/info.xml', '<info><version>1.0.10</version></info>');
	}

	protected function tearDown(): void {
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($this->fixture, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($files as $file) {
			if ($file->isDir() && !$file->isLink()) {
				rmdir($file->getPathname());
			} else {
				unlink($file->getPathname());
			}
		}
		rmdir($this->fixture);
		parent::tearDown();
	}

	public function testPackageContainsOnlyRuntimeAllowlistAndNoStaleFiles(): void {
		$unwanted = [
			'vendor/autoload.php',
			'vendor-bin/phpunit/vendor/tool.php',
			'node_modules/dependency/index.js',
			'src/main.ts',
			'tests/unit/Test.php',
			'.git/config',
			'.github/workflows/build.yml',
			'.php-cs-fixer.cache',
			'.php-cs-fixer.dist.php',
			'.env',
			'package.json',
			'package-lock.json',
			'composer.json',
			'composer.lock',
			'vite.config.ts',
			'tsconfig.json',
			'psalm.xml',
			'plan.md',
			'CODE_OF_CONDUCT.md',
			'certificates/app.key',
			'.private/app.pem',
			'appinfo/signature.json',
			'appinfo/certificate.pem',
			'appinfo/development.config.php',
			'new-unknown-directory/private.txt',
			'clean',
			'package',
		];
		foreach ($unwanted as $path) {
			$this->writeFixture($path, 'must not be packaged');
		}
		$this->writeFixture('out artifacts/sign/ocmremotewebapp/vendor-bin/stale.php', 'old staging contamination');

		[$status, $output] = $this->runPackage();
		$this->assertSame(0, $status, $output);
		$archivePath = $this->artifacts . '/ocmremotewebapp-1.0.10.tar.gz';
		$this->assertFileExists($archivePath);
		$archive = new PharData($archivePath);
		$actual = [];
		foreach (new RecursiveIteratorIterator($archive) as $path => $file) {
			$this->assertFalse($file->isLink(), $path);
			$actual[] = substr($path, strlen('phar://' . $archivePath . '/'));
		}
		$expected = array_map(fn (string $path): string => 'ocmremotewebapp/' . $path, self::RUNTIME_FILES);
		sort($expected);
		sort($actual);
		$this->assertSame($expected, $actual);
		$this->assertStringContainsString('<version>1.0.10</version>', $archive['ocmremotewebapp/appinfo/info.xml']->getContent());
	}

	public function testMissingRuntimeFilesFailPackagingInsteadOfPublishingPartialArchive(): void {
		unlink($this->fixture . '/appinfo/routes.php');

		[$status, $output] = $this->runPackage();
		$this->assertNotSame(0, $status, $output);
		$this->assertFileDoesNotExist($this->artifacts . '/ocmremotewebapp-1.0.10.tar.gz');
	}

	public function testSymbolicLinksCannotExposeFilesOutsideTheRuntimeAllowlist(): void {
		$this->writeFixture('.private/key.pem', 'must not be exposed');
		symlink('../.private/key.pem', $this->fixture . '/img/private.pem');

		[$status, $output] = $this->runPackage();
		$this->assertNotSame(0, $status, $output);
		$this->assertStringContainsString('Refusing to package symbolic links', $output);
		$this->assertFileDoesNotExist($this->artifacts . '/ocmremotewebapp-1.0.10.tar.gz');
	}

	public function testAllReleaseAndContainerEntrypointsUseTheSamePackageTarget(): void {
		$makefile = file_get_contents($this->fixture . '/Makefile');
		$this->assertMatchesRegularExpression('/^all:\s+appstore\s*$/m', $makefile);
		$this->assertMatchesRegularExpression('/^release:\s+appstore\s*$/m', $makefile);
		$this->assertMatchesRegularExpression('/^appstore:\s+sign\s*$/m', $makefile);
		$this->assertMatchesRegularExpression('/^sign:[^\n]*\bpackage\b/m', $makefile);
		$this->assertMatchesRegularExpression('/^docker:[^\n]*\bpackage\b/m', $makefile);
	}

	private function writeFixture(string $path, string $content): void {
		$path = $this->fixture . '/' . $path;
		if (!is_dir(dirname($path))) {
			mkdir(dirname($path), 0700, true);
		}
		file_put_contents($path, $content);
	}

	/** @return array{int, string} */
	private function runPackage(): array {
		$process = proc_open(
			['make', '--no-print-directory', '-C', $this->fixture, '--old-file=build', 'package', 'build_dir=' . $this->artifacts],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
		);
		$this->assertIsResource($process);
		$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return [proc_close($process), $output];
	}
}
