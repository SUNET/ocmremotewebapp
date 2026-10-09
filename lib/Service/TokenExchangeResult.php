<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Service;

final class TokenExchangeResult {
	public function __construct(
		public readonly string $accessToken,
		public readonly int $expiresAt,
	) {
	}
}
