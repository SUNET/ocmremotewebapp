<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Controller;

use OCA\OCMRemoteWebApp\Db\WebappShare;
use OCA\OCMRemoteWebApp\Db\WebappShareMapper;
use OCA\OCMRemoteWebApp\Exception\TokenExchangeException;
use OCA\OCMRemoteWebApp\Service\TokenExchanger;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Vue-app-internal JSON API for the share list. Accept/decline mutate our
 * own record and, when the webapp share carries a paired federated Files
 * mount (file_share_id), the NC external share too — so the user accepts
 * or removes both in a single action.
 */
class ReceivedController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private WebappShareMapper $mapper,
		private IUserManager $userManager,
		private TokenExchanger $tokenExchanger,
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function list(): DataResponse {
		$uid = $this->userId ?? '';
		if ($uid === '') {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		$rows = array_map(
			fn ($s) => $s->toApiArray(),
			$this->mapper->findAllByUid($uid),
		);
		return new DataResponse($rows);
	}

	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function accept(int $id): DataResponse {
		$uid = $this->userId ?? '';
		if ($uid === '') {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$share = $this->mapper->findById($id);
		} catch (DoesNotExistException) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		// Uid check after lookup prevents IDOR: a user must not be able
		// to flip another user's row to `accepted`.
		if ($share->getLocalUid() !== $uid) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		if ($share->getState() !== 'accepted') {
			$share->setState('accepted');
			$this->mapper->update($share);
		}
		// Mount the paired federated Files share, if any.
		$this->actOnFileShare($share, 'accept');
		return new DataResponse($share->toApiArray());
	}

	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function decline(int $id): DataResponse {
		$uid = $this->userId ?? '';
		if ($uid === '') {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		// Look the row up first so we can drop the paired Files mount too.
		// Uid check prevents acting on another user's share; a missing or
		// foreign row is a no-op (idempotent decline).
		try {
			$share = $this->mapper->findById($id);
			if ($share->getLocalUid() === $uid) {
				// Reap the notebook server on the remote hub before dropping
				// our row. Only accepted shares can have launched one.
				if ($share->getState() === 'accepted') {
					$this->reapHubServer($share);
				}
				$this->actOnFileShare($share, 'decline');
			}
		} catch (DoesNotExistException) {
			// already gone
		}
		// Uid-scoped delete — if the row doesn't belong to this user the
		// DELETE matches zero rows. Row is removed (not kept as a
		// `declined` tombstone) so re-sends are treated as fresh.
		$this->mapper->deleteById($id, $uid);
		return new DataResponse([], Http::STATUS_NO_CONTENT);
	}

	/**
	 * Ask the remote JupyterHub to stop+remove the notebook server it spawned
	 * for this share, so it doesn't linger once the user leaves. Best-effort:
	 * any failure (e.g. the sender already unshared so the token can no longer
	 * be exchanged) must not block the local decline — the hub's own culling
	 * is the backstop.
	 *
	 * The hub's close endpoint sits next to the share's open URI
	 * (.../services/ocm/open -> .../services/ocm/close) and authenticates the
	 * same way as launch: a valid access_token for this share.
	 */
	private function reapHubServer(WebappShare $share): void {
		$openUri = rtrim($share->getUri(), '/');
		$closeUri = preg_replace('#/open$#', '/close', $openUri);
		if ($closeUri === null || $closeUri === $openUri) {
			// Not a hub open URI we recognise; nothing to reap.
			return;
		}

		try {
			$token = $this->freshAccessToken($share);
		} catch (TokenExchangeException $e) {
			$this->logger->info('Skipping hub reap for share {id}: token exchange failed: {msg}', [
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
	 * Return a non-expired access_token for the share, re-exchanging the
	 * shared secret only when the cached one is missing or about to expire.
	 *
	 * @throws TokenExchangeException
	 */
	private function freshAccessToken(WebappShare $share): string {
		$cached = $share->getAccessToken();
		$expires = $share->getAccessTokenExpires();
		if ($cached !== null && $expires !== null && $expires > time() + 30) {
			return $cached;
		}
		$result = $this->tokenExchanger->exchange($share);
		$share->setAccessToken($result->accessToken);
		$share->setAccessTokenExpires($result->expiresAt);
		$this->mapper->update($share);
		return $result->accessToken;
	}

	/**
	 * Accept or decline the federated external Files mount paired with this
	 * webapp share. Best-effort: a failure here must not stop the webapp
	 * share's own state change. files_sharing's External\Manager is an
	 * app-internal class, resolved lazily to avoid a hard dependency at
	 * construction time.
	 *
	 * @param 'accept'|'decline' $action
	 */
	private function actOnFileShare(WebappShare $share, string $action): void {
		$fileShareId = $share->getFileShareId();
		if ($fileShareId === null || $fileShareId === '') {
			return;
		}
		$user = $this->userManager->get($share->getLocalUid());
		if ($user === null) {
			return;
		}
		try {
			/** @var \OCA\Files_Sharing\External\Manager $manager */
			$manager = Server::get(\OCA\Files_Sharing\External\Manager::class);
			$externalShare = $manager->getShare($fileShareId, $user);
			if ($externalShare === false) {
				return;
			}
			if ($action === 'accept') {
				$manager->acceptShare($externalShare, $user);
			} else {
				$manager->declineShare($externalShare, $user);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to {action} paired Files mount {id}: {msg}', [
				'action' => $action,
				'id' => $fileShareId,
				'msg' => $e->getMessage(),
				'exception' => $e,
			]);
		}
	}
}
