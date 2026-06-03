<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Controller;

use OCA\OCMRemoteWebApp\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

class ConfigController extends Controller {

	private const ALLOWED_MODES = ['iframe', 'popup', 'redirect'];

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private IConfig $config,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Personal display-mode preference. PUT because we replace the single
	 * named value, not append. The launcher (PageController::open) reads
	 * the same key.
	 */
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function setDisplay(string $mode): DataResponse {
		$uid = $this->userId ?? '';
		if ($uid === '') {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		if (!in_array($mode, self::ALLOWED_MODES, true)) {
			return new DataResponse(
				['error' => 'invalid mode', 'allowed' => self::ALLOWED_MODES],
				Http::STATUS_BAD_REQUEST,
			);
		}
		$this->config->setUserValue($uid, Application::APP_ID, 'displayMode', $mode);
		return new DataResponse(['displayMode' => $mode]);
	}
}
