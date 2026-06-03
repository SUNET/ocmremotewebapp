<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Service;

use OC\OCM\OCMSignatoryManager;
use OC\OCM\Rfc9421SignatoryManager;
use OCA\OCMRemoteWebApp\Db\WebappShare;
use OCA\OCMRemoteWebApp\Exception\TokenExchangeException;
use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use OCP\OCM\IOCMDiscoveryService;
use OCP\Security\Signature\ISignatureManager;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * Exchanges a share's long-lived `refresh_token` (the wire's
 * `sharedSecret`) for a short-lived JWT access token at the sender's
 * OCM `tokenEndPoint`.
 *
 * Lifted from apps/federatedfilesharing/lib/FederatedShareProvider::exchangeToken
 * in nextcloud/server PR #57234 (that method is private; we duplicate
 * rather than depend on it).
 */
class TokenExchanger {

	private const HTTP_TIMEOUT_SECONDS = 10;

	public function __construct(
		private readonly IOCMDiscoveryService $discoveryService,
		private readonly IClientService $clientService,
		private readonly ISignatureManager $signatureManager,
		private readonly OCMSignatoryManager $signatoryManager,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @throws TokenExchangeException
	 */
	public function exchange(WebappShare $share): TokenExchangeResult {
		$remoteHost = $this->remoteHostFromShare($share);
		if ($remoteHost === '') {
			throw new TokenExchangeException('Cannot derive remote host from share owner');
		}
		$remoteUrl = 'https://' . $remoteHost;

		try {
			$ocmProvider = $this->discoveryService->discover($remoteUrl);
		} catch (\Throwable $e) {
			throw new TokenExchangeException('OCM discovery failed for ' . $remoteHost, 0, $e);
		}

		$tokenEndpoint = $ocmProvider->getTokenEndPoint();
		if ($tokenEndpoint === '') {
			throw new TokenExchangeException('Remote does not expose tokenEndPoint: ' . $remoteHost);
		}

		return $this->postExchange($tokenEndpoint, $share->getRefreshToken(), $remoteHost);
	}

	/**
	 * @throws TokenExchangeException
	 */
	private function postExchange(
		string $tokenEndpoint,
		#[SensitiveParameter] string $refreshToken,
		string $remoteHost,
	): TokenExchangeResult {
		$clientId = (string)parse_url($this->urlGenerator->getAbsoluteURL('/'), PHP_URL_HOST);

		$options = [
			'body' => http_build_query([
				'grant_type' => 'authorization_code',
				'client_id' => $clientId,
				'code' => $refreshToken,
			]),
			'headers' => [
				'Content-Type' => 'application/x-www-form-urlencoded',
			],
			'timeout' => self::HTTP_TIMEOUT_SECONDS,
			'connect_timeout' => self::HTTP_TIMEOUT_SECONDS,
		];

		try {
			$options = $this->signatureManager->signOutgoingRequestIClientPayload(
				new Rfc9421SignatoryManager($this->signatoryManager),
				$options,
				'post',
				$tokenEndpoint,
			);
		} catch (\Throwable $e) {
			throw new TokenExchangeException('Failed to sign token exchange request', 0, $e);
		}

		try {
			$response = $this->clientService->newClient()->post($tokenEndpoint, $options);
		} catch (\Throwable $e) {
			throw new TokenExchangeException('Token exchange HTTP call failed', 0, $e);
		}

		if ($response->getStatusCode() !== 200) {
			throw new TokenExchangeException(sprintf(
				'Token exchange returned HTTP %d from %s',
				$response->getStatusCode(),
				$remoteHost,
			));
		}

		$data = json_decode((string)$response->getBody(), true);
		if (!is_array($data)) {
			throw new TokenExchangeException('Token exchange response is not JSON');
		}

		$accessToken = $data['access_token'] ?? null;
		$tokenType = $data['token_type'] ?? null;
		$expiresIn = $data['expires_in'] ?? null;

		if (!is_string($accessToken) || $accessToken === '') {
			throw new TokenExchangeException('Token exchange response missing access_token');
		}
		if (!is_string($tokenType) || strtolower($tokenType) !== 'bearer') {
			throw new TokenExchangeException('Token exchange response token_type is not Bearer');
		}
		if (!is_int($expiresIn) || $expiresIn <= 0) {
			throw new TokenExchangeException('Token exchange response missing or invalid expires_in');
		}

		$this->logger->debug('Exchanged refresh token for access token', ['remote' => $remoteHost]);

		return new TokenExchangeResult($accessToken, time() + $expiresIn);
	}

	/**
	 * Remote host = the part after the last `@` in remote_owner
	 * (a federated user ID, `user@host`).
	 */
	private function remoteHostFromShare(WebappShare $share): string {
		$owner = $share->getRemoteOwner();
		$at = strrpos($owner, '@');
		if ($at === false) {
			return '';
		}
		return substr($owner, $at + 1);
	}
}
