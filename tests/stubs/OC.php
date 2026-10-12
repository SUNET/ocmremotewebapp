<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;

/** Test-only service locator used by Nextcloud's public exception classes. */
class OC {
	public static ?ContainerInterface $server = null;
}
