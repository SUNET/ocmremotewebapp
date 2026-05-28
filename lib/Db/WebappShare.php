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
 * @method string getSharedSecret()
 * @method void setSharedSecret(string $sharedSecret)
 * @method string getState()
 * @method void setState(string $state)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method string getProtocolVersion()
 * @method void setProtocolVersion(string $protocolVersion)
 * @method string getAppName()
 * @method void setAppName(string $appName)
 * @method string getAppIcon()
 * @method void setAppIcon(string $appIcon)
 * @method string getTokenEndpoint()
 * @method void setTokenEndpoint(string $tokenEndpoint)
 * @method string getAccessToken()
 * @method void setAccessToken(string $accessToken)
 * @method int getAccessTokenExpiresAt()
 * @method void setAccessTokenExpiresAt(int $accessTokenExpiresAt)
 */
class WebappShare extends Entity {
	protected string $localUid = '';
	protected string $token = '';
	protected string $remoteOwner = '';
	protected string $remoteSharedBy = '';
	protected string $resourceName = '';
	protected string $uri = '';
	// `view`/`read`/`write` from v1 senders (viewMode), or
	// `view`/`read`/`write`/`share` from v2 senders (permissions). Wire
	// value stored verbatim; no projection.
	protected string $permissions = 'view';
	// JSON-encoded array of v2 target hints (`blank`/`iframe`/`popup`).
	// Empty string for v1 rows.
	protected string $targets = '';
	protected string $sharedSecret = '';
	protected string $state = 'pending';
	protected int $createdAt = 0;
	protected string $protocolVersion = 'v1';
	protected string $appName = '';
	protected string $appIcon = '';
	protected string $tokenEndpoint = '';
	// access_token_expires_at = 0 means "no cached token, must exchange".
	protected string $accessToken = '';
	protected int $accessTokenExpiresAt = 0;

	public function __construct() {
		$this->addType('createdAt', 'integer');
		$this->addType('accessTokenExpiresAt', 'integer');
	}
}
