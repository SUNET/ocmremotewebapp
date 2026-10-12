<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Tests\Unit\Federation;

use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

abstract class LocalizedTestCase extends TestCase {
	private ?ContainerInterface $previousContainer = null;

	protected function setUp(): void {
		parent::setUp();
		if (!defined('OCMREMOTEWEBAPP_STANDALONE_TESTS')) {
			return;
		}
		$this->previousContainer = \OC::$server;
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(IFactory::class)->willReturn($factory);
		\OC::$server = $container;
	}

	protected function tearDown(): void {
		if (defined('OCMREMOTEWEBAPP_STANDALONE_TESTS')) {
			\OC::$server = $this->previousContainer;
		}
		parent::tearDown();
	}
}
