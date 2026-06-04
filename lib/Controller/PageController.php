<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Controller;

use OCA\OCMRemoteWebApp\AppInfo\Application;
use OCA\OCMRemoteWebApp\Db\WebappShare;
use OCA\OCMRemoteWebApp\Db\WebappShareMapper;
use OCA\OCMRemoteWebApp\Exception\TokenExchangeException;
use OCA\OCMRemoteWebApp\Service\TokenExchanger;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class PageController extends Controller {

	private const DISPLAY_MODES = ['iframe', 'popup', 'redirect'];
	private const DEFAULT_DISPLAY_MODE = 'redirect';
	private const MODE_TO_WIRE = ['iframe' => 'iframe', 'popup' => 'popup', 'redirect' => 'blank'];
	private const WIRE_TO_MODE = ['iframe' => 'iframe', 'popup' => 'popup', 'blank' => 'redirect'];
	// Re-exchange when the cached JWT has less than this much life left,
	// so it doesn't expire mid-redirect.
	private const ACCESS_TOKEN_SLACK_SECONDS = 30;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private IInitialState $initialState,
		private WebappShareMapper $mapper,
		private IConfig $config,
		private TokenExchanger $tokenExchanger,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * App landing page. Hydrates the Vue front-end with the user's share
	 * list and display-mode preference so first paint doesn't need a
	 * round-trip.
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function index(): TemplateResponse {
		$uid = $this->userId ?? '';
		$displayMode = $this->resolveDisplayMode($uid);

		$shares = $uid !== ''
			? array_map(fn ($s) => $s->toApiArray(), $this->mapper->findAllByUid($uid))
			: [];

		$this->initialState->provideInitialState('shares', $shares);
		$this->initialState->provideInitialState('displayMode', $displayMode);
		$this->initialState->provideInitialState('displayModes', self::DISPLAY_MODES);

		return new TemplateResponse(Application::APP_ID, 'index');
	}

	/**
	 * Launcher. Resolves the share by (uid, token), ensures a fresh
	 * `access_token` JWT is cached, then renders the POST-form template
	 * for the effective display mode.
	 */
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function open(string $token): Response {
		$uid = $this->userId ?? '';
		if ($uid === '' || $token === '') {
			return new NotFoundResponse();
		}

		try {
			$share = $this->mapper->findByToken($token, $uid);
		} catch (DoesNotExistException) {
			return new NotFoundResponse();
		}

		try {
			$accessToken = $this->ensureFreshAccessToken($share);
		} catch (TokenExchangeException $e) {
			$this->logger->warning('Token exchange failed for share {id}: {msg}', [
				'id' => $share->getId(),
				'msg' => $e->getMessage(),
				'exception' => $e,
			]);
			return new TemplateResponse(
				Application::APP_ID,
				'launch_error',
				['message' => 'The remote service is currently unavailable.'],
				TemplateResponse::RENDER_AS_USER,
				Http::STATUS_BAD_GATEWAY,
			);
		}

		$mode = $this->effectiveMode($uid, $share);

		return match ($mode) {
			'iframe' => $this->renderEmbed($share, $accessToken),
			'popup' => $this->renderPopup($share, $accessToken),
			default => $this->renderRedirect($share, $accessToken),
		};
	}

	/**
	 * @throws TokenExchangeException
	 */
	private function ensureFreshAccessToken(WebappShare $share): string {
		$cached = $share->getAccessToken();
		$expires = $share->getAccessTokenExpires();
		$now = time();
		if ($cached !== null && $expires !== null && $expires > $now + self::ACCESS_TOKEN_SLACK_SECONDS) {
			return $cached;
		}

		$result = $this->tokenExchanger->exchange($share);
		$share->setAccessToken($result->accessToken);
		$share->setAccessTokenExpires($result->expiresAt);
		$this->mapper->update($share);

		return $result->accessToken;
	}

	private function renderRedirect(WebappShare $share, string $accessToken): TemplateResponse {
		return new TemplateResponse(
			Application::APP_ID,
			'redirect',
			[
				'uri' => $share->getUri(),
				'accessToken' => $accessToken,
				'appName' => $share->getAppName(),
			],
			TemplateResponse::RENDER_AS_BLANK,
		);
	}

	private function renderEmbed(WebappShare $share, string $accessToken): TemplateResponse {
		return new TemplateResponse(
			Application::APP_ID,
			'embed',
			[
				'uri' => $share->getUri(),
				'accessToken' => $accessToken,
				'sandbox' => $this->sandboxFor($share->getPermissions()),
				'appName' => $share->getAppName(),
			],
			TemplateResponse::RENDER_AS_BLANK,
		);
	}

	private function renderPopup(WebappShare $share, string $accessToken): TemplateResponse {
		return new TemplateResponse(
			Application::APP_ID,
			'popup',
			[
				'uri' => $share->getUri(),
				'accessToken' => $accessToken,
				'appName' => $share->getAppName(),
				'resourceName' => $share->getResourceName(),
			],
			TemplateResponse::RENDER_AS_USER,
		);
	}

	/**
	 * Effective launch mode = user preference, narrowed by the share's
	 * `targets` array. If the user's pref isn't offered, fall back to
	 * the first sender-offered target (sender intent wins). Both sides
	 * are guaranteed present: `targets` is always JSON on the row, and
	 * the user pref always falls back to the default.
	 */
	private function effectiveMode(string $uid, WebappShare $share): string {
		$pref = $this->resolveDisplayMode($uid);
		$targets = json_decode($share->getTargets(), true);
		if (!is_array($targets) || $targets === []) {
			return $pref;
		}
		$wirePref = self::MODE_TO_WIRE[$pref] ?? $pref;
		if (in_array($wirePref, $targets, true)) {
			return $pref;
		}
		$first = (string)$targets[0];
		return self::WIRE_TO_MODE[$first] ?? self::DEFAULT_DISPLAY_MODE;
	}

	private function resolveDisplayMode(string $uid): string {
		if ($uid === '') {
			return self::DEFAULT_DISPLAY_MODE;
		}
		$stored = $this->config->getUserValue(
			$uid,
			Application::APP_ID,
			'displayMode',
			self::DEFAULT_DISPLAY_MODE,
		);
		return in_array($stored, self::DISPLAY_MODES, true) ? $stored : self::DEFAULT_DISPLAY_MODE;
	}

	/**
	 * Tighten iframe sandbox by permission. `view`-only shares cannot
	 * submit forms inside the iframe; anything else (read/write/share)
	 * can. The launcher's own form POST is outside the iframe, so this
	 * restriction does not block the initial load.
	 *
	 * `permissions` is the JSON-encoded array stored on the row per
	 * OCM-API#368 (e.g. `["read"]`).
	 */
	private function sandboxFor(string $permissions): string {
		$base = 'allow-scripts allow-same-origin allow-popups';
		$list = json_decode($permissions, true);
		if (!is_array($list)) {
			$list = [$permissions];
		}
		return $list === ['view'] ? $base : $base . ' allow-forms';
	}

}
