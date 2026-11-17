<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Tests\Unit\Federation;

use OCA\OCMRemoteWebApp\Db\WebappShare;
use OCA\OCMRemoteWebApp\Db\WebappShareMapper;
use OCA\OCMRemoteWebApp\Federation\DelegatingCloudFederationProvider;
use OCA\OCMRemoteWebApp\Federation\WebappCloudFederationProvider;
use OCP\Federation\Exceptions\BadRequestException;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationProvider;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Federation\ICloudFederationShare;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use Psr\Log\NullLogger;

final class WebappCloudFederationProviderTest extends LocalizedTestCase {

	private function provider(WebappShareMapper $mapper): WebappCloudFederationProvider {
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->with('recipient')->willReturn(true);
		return new WebappCloudFederationProvider(
			$users,
			$mapper,
			$this->createMock(ICloudFederationProviderManager::class),
			$this->createMock(ICloudFederationFactory::class),
			new NullLogger(),
		);
	}

	/** @param array<string, mixed> $protocol */
	#[DataProviderExternal(DelegatingCloudFederationProviderTest::class, 'malformedWebappProtocols')]
	public function testMalformedWebappIsRejectedByActualValidator(array $protocol): void {
		$share = $this->createMock(ICloudFederationShare::class);
		$share->method('getProtocol')->willReturn($protocol);
		$share->method('getResourceType')->willReturn('folder');
		$share->method('getShareWith')->willReturn('recipient');
		$mapper = $this->createMock(WebappShareMapper::class);
		$mapper->expects($this->never())->method('insert');
		$folder = $this->createMock(ICloudFederationProvider::class);
		$folder->expects($this->never())->method('shareReceived');

		$this->expectException(BadRequestException::class);
		(new DelegatingCloudFederationProvider($this->provider($mapper), $folder))->validateShare($share);
	}

	/** @param array<string, mixed> $protocol */
	#[DataProviderExternal(DelegatingCloudFederationProviderTest::class, 'malformedWebappProtocols')]
	public function testMalformedWebappIsRejectedBeforePersistence(array $protocol): void {
		$share = $this->createMock(ICloudFederationShare::class);
		$share->method('getProtocol')->willReturn($protocol);
		$share->method('getResourceType')->willReturn('folder');
		$share->method('getShareWith')->willReturn('recipient');
		$mapper = $this->createMock(WebappShareMapper::class);
		$mapper->expects($this->never())->method('insert');
		$folder = $this->createMock(ICloudFederationProvider::class);
		$folder->expects($this->never())->method('shareReceived');

		$this->expectException(BadRequestException::class);
		(new DelegatingCloudFederationProvider($this->provider($mapper), $folder))->shareReceived($share);
	}

	public function testValidWebappStillValidatesAndIsPersisted(): void {
		$share = $this->createMock(ICloudFederationShare::class);
		$share->method('getResourceType')->willReturn('folder');
		$share->method('getShareWith')->willReturn('recipient');
		$share->method('getProtocol')->willReturn([
			'name' => 'webapp',
			'webapp' => [
				'uri' => 'https://remote.example/app',
				'permissions' => ['view'],
				'requirements' => ['must-exchange-token'],
				'targets' => ['blank'],
				'sharedSecret' => 'secret',
				'appIconHint' => 'application/vnd.jupyter',
				'apps' => [
					['name' => 'Jupyter', 'mediaTypes' => ['application/x-ipynb+json', 'text/plain']],
				],
			],
		]);
		$mapper = $this->createMock(WebappShareMapper::class);
		$mapper->expects($this->once())->method('insert')->willReturnCallback(function (WebappShare $entity): WebappShare {
			$this->assertSame('recipient', $entity->getLocalUid());
			$this->assertSame('https://remote.example/app', $entity->getUri());
			$this->assertSame('secret', $entity->getRefreshToken());
			$this->assertSame('pending', $entity->getState());
			$this->assertSame('application/vnd.jupyter', $entity->getAppIconHint());
			$this->assertSame(
				[['name' => 'Jupyter', 'mediaTypes' => ['application/x-ipynb+json', 'text/plain']]],
				json_decode((string)$entity->getApps(), true),
			);
			$this->assertSame('Jupyter', $entity->getPrimaryAppName());
			$entity->setId(123);
			return $entity;
		});
		$folder = $this->createMock(ICloudFederationProvider::class);
		$folder->expects($this->never())->method('shareReceived');
		$provider = new DelegatingCloudFederationProvider($this->provider($mapper), $folder);

		$provider->validateShare($share);
		$this->assertSame('123', $provider->shareReceived($share));
	}

	public function testFileResourceTypeWebappShareIsAccepted(): void {
		$share = $this->createMock(ICloudFederationShare::class);
		$share->method('getResourceType')->willReturn('file');
		$share->method('getShareWith')->willReturn('recipient');
		$share->method('getProtocol')->willReturn([
			'name' => 'webapp',
			'webapp' => [
				'uri' => 'https://remote.example/app',
				'permissions' => ['read'],
				'requirements' => ['must-exchange-token'],
				'targets' => ['iframe'],
				'sharedSecret' => 'secret',
				'apps' => [['name' => 'Editor', 'mediaTypes' => ['text/markdown']]],
			],
		]);
		$mapper = $this->createMock(WebappShareMapper::class);
		$mapper->expects($this->once())->method('insert')->willReturnCallback(function (WebappShare $entity): WebappShare {
			$this->assertSame('recipient', $entity->getLocalUid());
			$this->assertSame('Editor', $entity->getPrimaryAppName());
			$entity->setId(456);
			return $entity;
		});
		$provider = new DelegatingCloudFederationProvider($this->provider($mapper), null, 'file');

		$this->assertSame('456', $provider->shareReceived($share));
	}

	public function testUnsupportedResourceTypeIsRejected(): void {
		$share = $this->createMock(ICloudFederationShare::class);
		$share->method('getResourceType')->willReturn('calendar');
		$share->method('getShareWith')->willReturn('recipient');
		$share->method('getProtocol')->willReturn(['name' => 'webapp', 'webapp' => ['uri' => 'https://remote.example/app']]);
		$mapper = $this->createMock(WebappShareMapper::class);
		$mapper->expects($this->never())->method('insert');

		$this->expectException(BadRequestException::class);
		(new DelegatingCloudFederationProvider($this->provider($mapper), null))->shareReceived($share);
	}

	/** @param array<string, mixed>|null $apps */
	private function storedAppsFor(?array $apps): ?string {
		$share = $this->createMock(ICloudFederationShare::class);
		$share->method('getResourceType')->willReturn('folder');
		$share->method('getShareWith')->willReturn('recipient');
		$webapp = [
			'uri' => 'https://remote.example/app',
			'permissions' => ['view'],
			'requirements' => ['must-exchange-token'],
			'targets' => ['blank'],
		];
		if ($apps !== null) {
			$webapp['apps'] = $apps;
		}
		$share->method('getProtocol')->willReturn(['name' => 'webapp', 'webapp' => $webapp]);
		$mapper = $this->createMock(WebappShareMapper::class);
		$captured = null;
		$mapper->method('insert')->willReturnCallback(function (WebappShare $entity) use (&$captured): WebappShare {
			$captured = $entity->getApps();
			$entity->setId(1);
			return $entity;
		});
		$this->provider($mapper)->shareReceived($share);
		return $captured;
	}

	public function testAppsKeepOrderDropInvalidEntriesAndDedupeByName(): void {
		$apps = $this->storedAppsFor([
			['name' => 'HedgeDoc', 'mediaTypes' => ['text/markdown', 'text/html', '']],
			['name' => 'Collabora', 'mediaTypes' => ['application/vnd.oasis.opendocument.text']],
			['name' => 'HedgeDoc', 'mediaTypes' => ['text/plain']], // duplicate name: first wins
			['name' => '', 'mediaTypes' => ['text/plain']], // no name
			['name' => 'Broken', 'mediaTypes' => []], // empty mediaTypes
			['name' => 'Broken2'], // no mediaTypes
			'not-an-object',
		]);
		$this->assertSame([
			['name' => 'HedgeDoc', 'mediaTypes' => ['text/markdown', 'text/html']],
			['name' => 'Collabora', 'mediaTypes' => ['application/vnd.oasis.opendocument.text']],
		], json_decode((string)$apps, true));
	}

	public function testAbsentOrEmptyAppsAreStoredAsNull(): void {
		$this->assertNull($this->storedAppsFor(null));
		$this->assertNull($this->storedAppsFor([]));
		$this->assertNull($this->storedAppsFor([['name' => '', 'mediaTypes' => ['text/plain']]]));
	}

	public function testNotificationOwnershipRequiresMatchingProviderIdAndSecret(): void {
		$owned = new WebappShare();
		$owned->setRefreshToken('owned-secret');
		$other = new WebappShare();
		$other->setRefreshToken('other-secret');
		$mapper = $this->createMock(WebappShareMapper::class);
		$mapper->method('findAllByRemoteProviderId')->willReturnCallback(fn (string $id): array => $id === 'remote-id' ? [$owned, $other] : []);
		$mapper->expects($this->never())->method('delete');
		$provider = $this->provider($mapper);

		$this->assertTrue($provider->ownsNotification('remote-id', ['sharedSecret' => 'owned-secret']));
		$this->assertFalse($provider->ownsNotification('other-id', ['sharedSecret' => 'owned-secret']));
		$this->assertFalse($provider->ownsNotification('remote-id', ['sharedSecret' => 'wrong-secret']));
		$this->assertFalse($provider->ownsNotification('remote-id', ['sharedSecret' => '']));
		$this->assertFalse($provider->ownsNotification('remote-id', ['sharedSecret' => 123]));
		$this->assertFalse($provider->ownsNotification('remote-id', []));
	}

	public function testUnshareDeletesOnlyAuthenticatedWebappShares(): void {
		$owned = new WebappShare();
		$owned->setRefreshToken('owned-secret');
		$other = new WebappShare();
		$other->setRefreshToken('other-secret');
		$mapper = $this->createMock(WebappShareMapper::class);
		$mapper->expects($this->once())->method('findAllByRemoteProviderId')->with('remote-id')->willReturn([$owned, $other]);
		$mapper->expects($this->once())->method('delete')->with($this->identicalTo($owned));

		$this->assertSame([], $this->provider($mapper)->notificationReceived('SHARE_UNSHARED', 'remote-id', ['sharedSecret' => 'owned-secret']));
	}
}
