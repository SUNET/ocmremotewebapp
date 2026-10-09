<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getLocalUid()
 * @method void setLocalUid(string $localUid)
 * @method string getToken()
 * @method void setToken(string $token)
 * @method string getRemoteProviderId()
 * @method void setRemoteProviderId(string $remoteProviderId)
 * @method string getRemoteOwner()
 * @method void setRemoteOwner(string $remoteOwner)
 * @method string getRemoteSharedBy()
 * @method void setRemoteSharedBy(string $remoteSharedBy)
 * @method string getResourceName()
 * @method void setResourceName(string $resourceName)
 * @method string getUri()
 * @method void setUri(string $uri)
 * @method string getPermissions()
 * @method void setPermissions(string $permissions)
 * @method string getTargets()
 * @method void setTargets(string $targets)
 * @method string getRefreshToken()
 * @method void setRefreshToken(string $refreshToken)
 * @method ?string getAccessToken()
 * @method void setAccessToken(?string $accessToken)
 * @method ?int getAccessTokenExpires()
 * @method void setAccessTokenExpires(?int $accessTokenExpires)
 * @method string getState()
 * @method void setState(string $state)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method string getAppName()
 * @method void setAppName(string $appName)
 * @method ?string getAppIconHint()
 * @method void setAppIconHint(?string $appIconHint)
 * @method ?string getMediaTypes()
 * @method void setMediaTypes(?string $mediaTypes)
 * @method ?string getFileShareId()
 * @method void setFileShareId(?string $fileShareId)
 */
class WebappShare extends Entity {
	protected string $localUid = '';
	protected string $token = '';
	// The wire `providerId` (share id at the sending server); the lookup
	// key for SHARE_UNSHARED. Empty on pre-migration rows.
	protected string $remoteProviderId = '';
	protected string $remoteOwner = '';
	protected string $remoteSharedBy = '';
	protected string $resourceName = '';
	protected string $uri = '';
	protected string $permissions = 'view';
	// JSON-encoded subset of the wire targets (blank/iframe).
	protected string $targets = '[]';
	// The wire `sharedSecret`, stored verbatim. Long-lived OAuth2
	// authorization code; never expose to the browser.
	protected string $refreshToken = '';
	// Cached JWT minted via TokenExchanger. NULL ⇒ exchange before launch.
	protected ?string $accessToken = null;
	// JWT `exp` claim, unix seconds. NULL ⇔ accessToken is NULL.
	protected ?int $accessTokenExpires = null;
	protected string $state = 'pending';
	protected int $createdAt = 0;
	protected string $appName = '';
	// MIME-type hint for icon selection, e.g. application/vnd.jupyter.
	protected ?string $appIconHint = null;
	// JSON-encoded MIME types the sender's webapp can handle. NULL when absent.
	protected ?string $mediaTypes = null;
	// Id of the paired NC federated external share (Files mount) created
	// from the webdav protocol entry. NULL when no mount was created.
	protected ?string $fileShareId = null;

	public function __construct() {
		$this->addType('createdAt', 'integer');
		$this->addType('accessTokenExpires', 'integer');
	}

	/**
	 * Safe wire shape for both initial-state hydration and JSON API
	 * output. Deliberately omits `refresh_token` and `access_token` —
	 * those are server-internal and must never leak to the browser.
	 *
	 * @return array<string, mixed>
	 */
	public function toApiArray(): array {
		return [
			'id' => $this->getId(),
			'token' => $this->getToken(),
			'remoteOwner' => $this->getRemoteOwner(),
			'remoteSharedBy' => $this->getRemoteSharedBy(),
			'resourceName' => $this->getResourceName(),
			'permissions' => $this->getPermissions(),
			'targets' => $this->getTargets(),
			'state' => $this->getState(),
			'createdAt' => $this->getCreatedAt(),
			'appName' => $this->getAppName(),
			'appIconHint' => $this->getAppIconHint(),
			'mediaTypes' => $this->getMediaTypes(),
		];
	}
}
