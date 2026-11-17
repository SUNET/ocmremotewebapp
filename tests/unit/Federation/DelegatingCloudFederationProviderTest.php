<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Tests\Unit\Federation;

use OCA\OCMRemoteWebApp\Federation\DelegatingCloudFederationProvider;
use OCA\OCMRemoteWebApp\Federation\WebappCloudFederationProvider;
use OCP\Federation\Exceptions\BadRequestException;
use OCP\Federation\Exceptions\ProviderCouldNotAddShareException;
use OCP\Federation\Exceptions\ProviderDoesNotExistsException;
use OCP\Federation\ICloudFederationProvider;
use OCP\Federation\ICloudFederationShare;
use OCP\Federation\ISignedCloudFederationProvider;
use OCP\Federation\IValidationAwareCloudFederationProvider;
use PHPUnit\Framework\Attributes\DataProvider;

final class DelegatingCloudFederationProviderTest extends LocalizedTestCase {

	/** @return array<string, array{array<string, mixed>, string}> */
	public static function nonWebappShares(): array {
		return [
			'legacy webdav' => [['name' => 'webdav', 'options' => ['sharedSecret' => 'secret']], 'folder'],
			'webdav' => [['name' => 'webdav', 'webdav' => ['sharedSecret' => 'secret']], 'folder'],
			'multi folder' => [['name' => 'multi', 'webdav' => ['resourceType' => 'folder']], 'folder'],
			'multi file' => [['name' => 'multi', 'webdav' => ['resourceType' => 'file']], 'file'],
			'multi other resource' => [['name' => 'multi', 'webdav' => ['resourceType' => 'other'], 'other' => ['resourceType' => 'webapp']], 'other'],
			'unsupported protocol' => [['name' => 'multi', 'other' => ['resourceType' => 'folder']], 'folder'],
			'empty protocol' => [[], 'folder'],
		];
	}

	/** @param array<string, mixed> $protocol */
	#[DataProvider('nonWebappShares')]
	public function testNonWebappShareIsPassedThroughUnchanged(array $protocol, string $resourceType): void {
		$share = $this->createMock(ICloudFederationShare::class);
		$share->method('getProtocol')->willReturn($protocol);
		$share->expects($this->never())->method('getResourceType')->willReturn($resourceType);
		$share->expects($this->never())->method('setProtocol');
		$share->expects($this->never())->method('setResourceType');
		$share->expects($this->never())->method('setShareWith');
		$share->expects($this->never())->method('getShareSecret');

		$webapp = $this->createMock(WebappCloudFederationProvider::class);
		$webapp->expects($this->never())->method('validateShare');
		$webapp->expects($this->never())->method('shareReceived');
		$core = $this->createMock(IValidationAwareCloudFederationProvider::class);
		$core->expects($this->once())->method('validateShare')->with($this->identicalTo($share));
		$core->expects($this->once())->method('shareReceived')->with($this->identicalTo($share))->willReturn('core-id');
		$provider = new DelegatingCloudFederationProvider($webapp, $core, $resourceType);

		$provider->validateShare($share);
		$this->assertSame('core-id', $provider->shareReceived($share));
	}

	public function testCoreWithoutValidationIsNotCalledDuringValidation(): void {
		$share = $this->createMock(ICloudFederationShare::class);
		$share->method('getProtocol')->willReturn(['name' => 'multi', 'webdav' => []]);
		$core = $this->createMock(ICloudFederationProvider::class);
		$core->expects($this->never())->method('shareReceived');
		$webapp = $this->createMock(WebappCloudFederationProvider::class);
		$webapp->expects($this->never())->method('validateShare');

		(new DelegatingCloudFederationProvider($webapp, $core))->validateShare($share);
		$this->addToAssertionCount(1);
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function webappProtocols(): array {
		return [
			'single protocol' => [['name' => 'webapp', 'webapp' => ['uri' => 'https://remote.example/app']]],
			'deprecated options' => [['name' => 'webapp', 'options' => ['uri' => 'https://remote.example/app']]],
			'multi' => [['name' => 'multi', 'webapp' => ['uri' => 'https://remote.example/app'], 'webdav' => []]],
		];
	}

	/** @param array<string, mixed> $protocol */
	#[DataProvider('webappProtocols')]
	public function testWebappShareIsDelegatedToWebappProvider(array $protocol): void {
		$share = $this->createMock(ICloudFederationShare::class);
		$share->method('getProtocol')->willReturn($protocol);
		$webapp = $this->createMock(WebappCloudFederationProvider::class);
		$webapp->expects($this->once())->method('validateShare')->with($share);
		$webapp->expects($this->once())->method('shareReceived')->with($share)->willReturn('webapp-id');
		$core = $this->createMock(IValidationAwareCloudFederationProvider::class);
		$core->expects($this->never())->method('validateShare');
		$core->expects($this->never())->method('shareReceived');
		$provider = new DelegatingCloudFederationProvider($webapp, $core);

		$provider->validateShare($share);
		$this->assertSame('webapp-id', $provider->shareReceived($share));
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function malformedWebappProtocols(): array {
		return [
			'missing payload' => [['name' => 'webapp']],
			'null payload' => [['name' => 'multi', 'webapp' => null, 'webdav' => []]],
			'string payload' => [['name' => 'multi', 'webapp' => 'invalid', 'webdav' => []]],
			'empty payload' => [['name' => 'multi', 'webapp' => [], 'webdav' => []]],
			'invalid envelope name' => [['name' => 'other', 'webapp' => []]],
		];
	}

	/** @param array<string, mixed> $protocol */
	#[DataProvider('malformedWebappProtocols')]
	public function testInvalidWebappNeverFallsThroughToCore(array $protocol): void {
		$share = $this->createMock(ICloudFederationShare::class);
		$share->method('getProtocol')->willReturn($protocol);
		$webapp = $this->createMock(WebappCloudFederationProvider::class);
		$webapp->expects($this->once())->method('validateShare')->with($share)->willThrowException(new BadRequestException(['protocol.webapp']));
		$core = $this->createMock(ICloudFederationProvider::class);
		$core->expects($this->never())->method('shareReceived');

		$this->expectException(BadRequestException::class);
		(new DelegatingCloudFederationProvider($webapp, $core))->validateShare($share);
	}

	public function testCoreRejectionIsNotHidden(): void {
		$share = $this->createMock(ICloudFederationShare::class);
		$share->method('getProtocol')->willReturn(['name' => 'other']);
		$core = $this->createMock(ICloudFederationProvider::class);
		$core->method('shareReceived')->willThrowException(new ProviderCouldNotAddShareException('Unsupported protocol', '', 501));
		$webapp = $this->createMock(WebappCloudFederationProvider::class);
		$webapp->expects($this->never())->method('shareReceived');

		$this->expectException(ProviderCouldNotAddShareException::class);
		$this->expectExceptionMessage('Unsupported protocol');
		(new DelegatingCloudFederationProvider($webapp, $core))->shareReceived($share);
	}

	public function testCoreGroupShareSupportIsPreserved(): void {
		$core = $this->createMock(ICloudFederationProvider::class);
		$core->method('getSupportedShareTypes')->willReturn(['user', 'group']);
		$webapp = $this->createMock(WebappCloudFederationProvider::class);
		$webapp->method('getSupportedShareTypes')->willReturn(['user']);
		$provider = new DelegatingCloudFederationProvider($webapp, $core);

		$this->assertSame('folder', $provider->getShareType());
		$this->assertSame(['user', 'group'], $provider->getSupportedShareTypes());
	}

	public function testFileResourceTypeIsReportedAndDelegates(): void {
		$share = $this->createMock(ICloudFederationShare::class);
		$share->method('getProtocol')->willReturn(['name' => 'multi', 'webapp' => [], 'webdav' => []]);
		$webapp = $this->createMock(WebappCloudFederationProvider::class);
		$webapp->method('shareReceived')->with($share)->willReturn('webapp-id');
		$webapp->method('getSupportedShareTypes')->willReturn(['user']);
		$core = $this->createMock(ICloudFederationProvider::class);
		$core->expects($this->never())->method('shareReceived');
		$provider = new DelegatingCloudFederationProvider($webapp, $core, 'file');

		$this->assertSame('file', $provider->getShareType());
		$this->assertSame('webapp-id', $provider->shareReceived($share));
	}

	public function testMissingCoreProviderFailsExplicitlyForNonWebappShares(): void {
		$share = $this->createMock(ICloudFederationShare::class);
		$share->method('getProtocol')->willReturn(['name' => 'webdav', 'webdav' => []]);
		$webapp = $this->createMock(WebappCloudFederationProvider::class);
		$webapp->expects($this->never())->method('shareReceived');

		$this->expectException(ProviderDoesNotExistsException::class);
		(new DelegatingCloudFederationProvider($webapp, null))->shareReceived($share);
	}

	public function testWebappDoesNotRequireCoreProvider(): void {
		$share = $this->createMock(ICloudFederationShare::class);
		$share->method('getProtocol')->willReturn(['name' => 'webapp', 'webapp' => []]);
		$webapp = $this->createMock(WebappCloudFederationProvider::class);
		$webapp->method('shareReceived')->with($share)->willReturn('webapp-id');
		$webapp->method('getSupportedShareTypes')->willReturn(['user']);
		$provider = new DelegatingCloudFederationProvider($webapp, null);

		$this->assertSame('webapp-id', $provider->shareReceived($share));
		$this->assertSame(['user'], $provider->getSupportedShareTypes());
	}

	public function testOrdinaryNotificationsGoToCoreUnchanged(): void {
		$notification = ['sharedSecret' => 'secret', 'extra' => 'unchanged'];
		$webapp = $this->createMock(WebappCloudFederationProvider::class);
		$webapp->method('ownsNotification')->with('remote-id', $notification)->willReturn(false);
		$webapp->expects($this->never())->method('notificationReceived');
		$core = $this->createMock(ICloudFederationProvider::class);
		$core->expects($this->once())->method('notificationReceived')->with('SHARE_UNSHARED', 'remote-id', $notification)->willReturn(['core-result']);

		$this->assertSame(['core-result'], (new DelegatingCloudFederationProvider($webapp, $core))->notificationReceived('SHARE_UNSHARED', 'remote-id', $notification));
	}

	public function testOwnedWebappNotificationsAreHandledAndForwardedToCore(): void {
		$notification = ['sharedSecret' => 'secret'];
		$webapp = $this->createMock(WebappCloudFederationProvider::class);
		$webapp->method('ownsNotification')->with('remote-id', $notification)->willReturn(true);
		$webapp->expects($this->once())->method('notificationReceived')->with('SHARE_UNSHARED', 'remote-id', $notification)->willReturn([]);
		// The paired Files mount is reaped by core even when the webapp
		// provider owned the notification (e.g. `file` resourceType).
		$core = $this->createMock(ICloudFederationProvider::class);
		$core->expects($this->once())->method('notificationReceived')->with('SHARE_UNSHARED', 'remote-id', $notification)->willReturn(['core-result']);

		$this->assertSame(['core-result'], (new DelegatingCloudFederationProvider($webapp, $core))->notificationReceived('SHARE_UNSHARED', 'remote-id', $notification));
	}

	public function testSignedNotificationIdentityIsDelegatedToCore(): void {
		$payload = ['sharedSecret' => 'secret'];
		$core = $this->createMock(ISignedCloudFederationProvider::class);
		$core->expects($this->once())->method('getFederationIdFromSharedSecret')->with('secret', $payload)->willReturn('owner@remote.example');
		$webapp = $this->createMock(WebappCloudFederationProvider::class);

		$this->assertSame('owner@remote.example', (new DelegatingCloudFederationProvider($webapp, $core))->getFederationIdFromSharedSecret('secret', $payload));
	}

	public function testUnsignedProviderDoesNotInventNotificationIdentity(): void {
		$core = $this->createMock(ICloudFederationProvider::class);
		$webapp = $this->createMock(WebappCloudFederationProvider::class);

		$this->assertSame('', (new DelegatingCloudFederationProvider($webapp, $core))->getFederationIdFromSharedSecret('secret', []));
	}
}
