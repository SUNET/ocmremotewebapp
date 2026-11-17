<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Tests\Unit\AppInfo;

use OCA\OCMRemoteWebApp\AppInfo\Application;
use OCA\OCMRemoteWebApp\Federation\DelegatingCloudFederationProvider;
use OCA\OCMRemoteWebApp\Federation\WebappCloudFederationProvider;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\Federation\ICloudFederationProvider;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Federation\ICloudFederationShare;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;

final class ApplicationTest extends TestCase {

	private function app(): Application {
		return (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
	}

	/** @param array<string, array{callback: callable}> $registrations */
	private function manager(array &$registrations): ICloudFederationProviderManager {
		$manager = $this->createMock(ICloudFederationProviderManager::class);
		$manager->method('getAllCloudFederationProviders')->willReturnCallback(function () use (&$registrations): array {
			return $registrations;
		});
		$manager->method('removeCloudFederationProvider')->willReturnCallback(function (string $type) use (&$registrations): void {
			unset($registrations[$type]);
		});
		$manager->method('addCloudFederationProvider')->willReturnCallback(function (string $type, string $name, callable $callback) use (&$registrations): void {
			$registrations[$type] = ['callback' => $callback];
		});
		return $manager;
	}

	private function bootContext(ICloudFederationProviderManager $manager, WebappCloudFederationProvider $webapp): IBootContext {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(WebappCloudFederationProvider::class)->willReturn($webapp);
		$context = $this->createMock(IBootContext::class);
		$context->method('getAppContainer')->willReturn($container);
		$context->method('injectFn')->willReturnCallback(fn (callable $fn) => $fn($manager));
		return $context;
	}

	public function testBootWrapsFolderAndFileProvidersLazilyAndRepeatably(): void {
		$share = $this->createMock(ICloudFederationShare::class);
		$share->method('getProtocol')->willReturn(['name' => 'multi', 'webdav' => ['sharedSecret' => 'secret']]);
		$folderCore = $this->createMock(ICloudFederationProvider::class);
		$folderCore->expects($this->once())->method('shareReceived')->with($this->identicalTo($share))->willReturn('folder-core-id');
		$fileCore = $this->createMock(ICloudFederationProvider::class);
		$fileCore->expects($this->never())->method('shareReceived');
		$webapp = $this->createMock(WebappCloudFederationProvider::class);
		$webapp->expects($this->never())->method('shareReceived');
		$resolutions = 0;
		$registrations = [
			'folder' => ['callback' => function () use ($folderCore, &$resolutions): ICloudFederationProvider {
				$resolutions++;
				return $folderCore;
			}],
			'file' => ['callback' => function () use ($fileCore, &$resolutions): ICloudFederationProvider {
				$resolutions++;
				return $fileCore;
			}],
		];
		$manager = $this->manager($registrations);
		$context = $this->bootContext($manager, $webapp);

		$this->app()->boot($context);
		$this->assertSame(0, $resolutions, 'core provider callbacks must stay lazy until first use');
		$this->assertArrayHasKey('folder', $registrations);
		$this->assertArrayHasKey('file', $registrations);
		$folderDecorator = $registrations['folder']['callback']();
		$this->assertInstanceOf(DelegatingCloudFederationProvider::class, $folderDecorator);
		$this->assertSame('folder', $folderDecorator->getShareType());
		$fileDecorator = $registrations['file']['callback']();
		$this->assertInstanceOf(DelegatingCloudFederationProvider::class, $fileDecorator);
		$this->assertSame('file', $fileDecorator->getShareType());
		$this->assertSame('folder-core-id', $folderDecorator->shareReceived($share));

		$this->app()->boot($context);
		$this->assertInstanceOf(DelegatingCloudFederationProvider::class, $registrations['folder']['callback'](), 're-boot must not double-wrap');
		$this->assertInstanceOf(DelegatingCloudFederationProvider::class, $registrations['file']['callback']());
	}

	public function testBootOnOlderNextcloudDerivesFolderFromFileCallback(): void {
		$share = $this->createMock(ICloudFederationShare::class);
		$share->method('getProtocol')->willReturn(['name' => 'webdav', 'webdav' => ['sharedSecret' => 'secret']]);
		$fileCore = $this->createMock(ICloudFederationProvider::class);
		$fileCore->expects($this->once())->method('shareReceived')->willReturn('core-id');
		$webapp = $this->createMock(WebappCloudFederationProvider::class);
		$registrations = ['file' => ['callback' => fn (): ICloudFederationProvider => $fileCore]];
		$manager = $this->manager($registrations);
		$context = $this->bootContext($manager, $webapp);

		$this->app()->boot($context);
		$this->assertArrayHasKey('folder', $registrations, 'folder provider must be registered even when core only has file');
		$this->assertArrayHasKey('file', $registrations);
		$folderDecorator = $registrations['folder']['callback']();
		$this->assertInstanceOf(DelegatingCloudFederationProvider::class, $folderDecorator);
		$this->assertSame('core-id', $folderDecorator->shareReceived($share));
	}

	public function testBootDoesNotWrapAnExistingDecorator(): void {
		$decorator = $this->createMock(DelegatingCloudFederationProvider::class);
		$registrations = [
			'folder' => ['callback' => fn (): DelegatingCloudFederationProvider => $decorator],
			'file' => ['callback' => fn (): DelegatingCloudFederationProvider => $decorator],
		];
		$manager = $this->manager($registrations);
		$context = $this->createMock(IBootContext::class);
		$context->expects($this->never())->method('getAppContainer');
		$context->method('injectFn')->willReturnCallback(fn (callable $fn) => $fn($manager));

		$this->app()->boot($context);
		$this->assertSame($decorator, $registrations['folder']['callback']());
		$this->assertSame($decorator, $registrations['file']['callback']());
	}

	public function testBootWithoutCoreStillRegistersWebappHandlingForBothTypes(): void {
		$share = $this->createMock(ICloudFederationShare::class);
		$share->method('getProtocol')->willReturn(['name' => 'webapp', 'webapp' => []]);
		$webapp = $this->createMock(WebappCloudFederationProvider::class);
		$webapp->expects($this->exactly(2))->method('shareReceived')->with($share)->willReturn('webapp-id');
		$registrations = [];
		$manager = $this->manager($registrations);
		$context = $this->bootContext($manager, $webapp);

		$this->app()->boot($context);
		$this->assertSame('webapp-id', $registrations['folder']['callback']()->shareReceived($share));
		$this->assertSame('webapp-id', $registrations['file']['callback']()->shareReceived($share));
	}
}
