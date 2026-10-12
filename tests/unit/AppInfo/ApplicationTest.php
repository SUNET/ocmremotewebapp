<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Tests\Unit\AppInfo;

use OCA\OCMRemoteWebApp\AppInfo\Application;
use OCA\OCMRemoteWebApp\Federation\FolderCloudFederationProvider;
use OCA\OCMRemoteWebApp\Federation\WebappCloudFederationProvider;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\Federation\ICloudFederationProvider;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Federation\ICloudFederationShare;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;

final class ApplicationTest extends TestCase {

	/** @return array<string, array{string}> */
	public static function coreRegistrations(): array {
		return ['folder provider' => ['folder'], 'older file-only provider' => ['file']];
	}

	#[DataProvider('coreRegistrations')]
	public function testBootPreservesCoreProviderAndIsLazyAndRepeatable(string $registeredType): void {
		$share = $this->createMock(ICloudFederationShare::class);
		$share->method('getProtocol')->willReturn(['name' => 'multi', 'webdav' => ['sharedSecret' => 'secret']]);
		$core = $this->createMock(ICloudFederationProvider::class);
		$core->expects($this->once())->method('shareReceived')->with($this->identicalTo($share))->willReturn('core-id');
		$webapp = $this->createMock(WebappCloudFederationProvider::class);
		$webapp->expects($this->never())->method('shareReceived');
		$coreResolutions = 0;
		$registrations = [$registeredType => ['callback' => function () use ($core, &$coreResolutions): ICloudFederationProvider {
			$coreResolutions++;
			return $core;
		}]];
		if ($registeredType === 'folder') {
			$file = $this->createMock(ICloudFederationProvider::class);
			$file->expects($this->never())->method('shareReceived');
			$registrations['file'] = ['callback' => fn (): ICloudFederationProvider => $file];
		}
		$fileCallback = $registrations['file']['callback'];
		$manager = $this->createMock(ICloudFederationProviderManager::class);
		$manager->method('getAllCloudFederationProviders')->willReturnCallback(function () use (&$registrations): array {
			return $registrations;
		});
		$manager->expects($this->exactly(2))->method('removeCloudFederationProvider')->with('folder')->willReturnCallback(function (string $type) use (&$registrations): void {
			unset($registrations[$type]);
		});
		$manager->expects($this->exactly(2))->method('addCloudFederationProvider')->with('folder', Application::WEBAPP_DISPLAY_NAME, $this->isType('callable'))->willReturnCallback(function (string $type, string $name, callable $callback) use (&$registrations): void {
			$registrations[$type] = ['callback' => $callback];
		});
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->exactly(2))->method('get')->with(WebappCloudFederationProvider::class)->willReturn($webapp);
		$context = $this->createMock(IBootContext::class);
		$context->method('getAppContainer')->willReturn($container);
		$context->method('injectFn')->willReturnCallback(fn (callable $fn) => $fn($manager));
		$app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();

		$app->boot($context);
		$this->assertSame(0, $coreResolutions);
		$decorator = $registrations['folder']['callback']();
		$this->assertInstanceOf(FolderCloudFederationProvider::class, $decorator);
		$this->assertSame('core-id', $decorator->shareReceived($share));
		$this->assertSame($fileCallback, $registrations['file']['callback']);

		$app->boot($context);
		$this->assertInstanceOf(FolderCloudFederationProvider::class, $registrations['folder']['callback']());
	}

	public function testBootDoesNotWrapAnExistingDecorator(): void {
		$decorator = $this->createMock(FolderCloudFederationProvider::class);
		$manager = $this->createMock(ICloudFederationProviderManager::class);
		$manager->method('getAllCloudFederationProviders')->willReturn([
			'folder' => ['callback' => fn (): FolderCloudFederationProvider => $decorator],
		]);
		$registeredCallback = null;
		$manager->method('addCloudFederationProvider')->willReturnCallback(function (string $type, string $name, callable $callback) use (&$registeredCallback): void {
			$registeredCallback = $callback;
		});
		$context = $this->createMock(IBootContext::class);
		$context->expects($this->never())->method('getAppContainer');
		$context->method('injectFn')->willReturnCallback(fn (callable $fn) => $fn($manager));
		$app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();

		$app->boot($context);
		$this->assertIsCallable($registeredCallback);
		$this->assertSame($decorator, $registeredCallback());
	}

	public function testBootWithoutCoreStillRegistersWebappHandling(): void {
		$share = $this->createMock(ICloudFederationShare::class);
		$share->method('getProtocol')->willReturn(['name' => 'webapp', 'webapp' => []]);
		$webapp = $this->createMock(WebappCloudFederationProvider::class);
		$webapp->method('shareReceived')->with($share)->willReturn('webapp-id');
		$manager = $this->createMock(ICloudFederationProviderManager::class);
		$manager->method('getAllCloudFederationProviders')->willReturn([]);
		$registeredCallback = null;
		$manager->method('addCloudFederationProvider')->willReturnCallback(function (string $type, string $name, callable $callback) use (&$registeredCallback): void {
			$registeredCallback = $callback;
		});
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(WebappCloudFederationProvider::class)->willReturn($webapp);
		$context = $this->createMock(IBootContext::class);
		$context->method('getAppContainer')->willReturn($container);
		$context->method('injectFn')->willReturnCallback(fn (callable $fn) => $fn($manager));
		$app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();

		$app->boot($context);
		$this->assertIsCallable($registeredCallback);
		$this->assertSame('webapp-id', $registeredCallback()->shareReceived($share));
	}
}
