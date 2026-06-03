<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Controller;

use OCA\OCMRemoteWebApp\Db\WebappShareMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Vue-app-internal JSON API for the share list. Accept/decline mutate
 * local state only — we own no IShare, so there's no OCM
 * SHARE_ACCEPTED/SHARE_DECLINED notification to originate
 */
class ReceivedController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private WebappShareMapper $mapper,
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
		return new DataResponse($share->toApiArray());
	}

	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function decline(int $id): DataResponse {
		$uid = $this->userId ?? '';
		if ($uid === '') {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		// Uid-scoped delete — if the row doesn't belong to this user, the
		// DELETE simply matches zero rows. No outbound notification,
		// since we own no IShare lifecycle. Row is removed (not kept as a
		// `declined` tombstone) — re-sends from the same origin are
		// treated as fresh.
		$this->mapper->deleteById($id, $uid);
		return new DataResponse([], Http::STATUS_NO_CONTENT);
	}
}
