<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Service;

use OCA\OCMRemoteWebApp\Db\WebappShare;
use OCA\OCMRemoteWebApp\Exception\TokenExchangeException;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Asks the remote JupyterHub to stop+remove the notebook server it spawned for
 * a webapp share, so it doesn't linger once the user leaves the share (decline)
 * or the sender revokes it (SHARE_UNSHARED).
 *
 * The hub's close endpoint sits next to the share's open URI
 * (`.../services/ocm/open` -> `.../services/ocm/close`) and authenticates the
 * same way as launch: a valid access_token for this share. Everything here is
 * best-effort — a failure (e.g. the sender already unshared so the secret can
 * no longer be exchanged, and no live cached token remains) must never block
 * the local removal; the hub's own idle-culling is the backstop.
 */
class HubReaper {

	// Re-exchange only when the cached token has less than this much life left.
	private const TOKEN_SLACK_SECONDS = 30;

	public function __construct(
		private TokenExchanger $tokenExchanger,
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}

	public function reap(WebappShare $share): void {
		$openUri = rtrim($share->getUri(), '/');
		$closeUri = preg_replace('#/open$#', '/close', $openUri);
		if ($closeUri === null || $closeUri === $openUri) {
			// Not a hub open URI we recognise; nothing to reap.
			return;
		}

		try {
			$token = $this->accessToken($share);
		} catch (TokenExchangeException $e) {
			$this->logger->info('Skipping hub reap for share {id}: token unavailable: {msg}', [
				'id' => $share->getId(),
				'msg' => $e->getMessage(),
			]);
			return;
		}

		try {
			$this->clientService->newClient()->post($closeUri, [
				'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
				'body' => http_build_query(['access_token' => $token]),
				'timeout' => 10,
			]);
		} catch (\Throwable $e) {
			$this->logger->info('Hub reap call failed for share {id}: {msg}', [
				'id' => $share->getId(),
				'msg' => $e->getMessage(),
				'exception' => $e,
			]);
		}
	}

	/**
	 * A live access_token for the share: the cached one while it still has life,
	 * otherwise a fresh exchange of the shared secret.
	 *
	 * @throws TokenExchangeException when the secret can no longer be exchanged
	 *   (e.g. the sender already revoked the share)
	 */
	private function accessToken(WebappShare $share): string {
		$cached = $share->getAccessToken();
		$expires = $share->getAccessTokenExpires();
		if ($cached !== null && $expires !== null && $expires > time() + self::TOKEN_SLACK_SECONDS) {
			return $cached;
		}
		return $this->tokenExchanger->exchange($share)->accessToken;
	}
}
