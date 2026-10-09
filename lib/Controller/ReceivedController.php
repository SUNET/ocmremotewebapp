<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Controller;

use OCA\OCMRemoteWebApp\Db\WebappShare;
use OCA\OCMRemoteWebApp\Db\WebappShareMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
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
				// Standard OCM decline; the sender's app reaps any hub server
				// it spawned off the resulting SHARE_DECLINED notification.
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
