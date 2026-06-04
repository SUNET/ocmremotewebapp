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
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class PageController extends Controller {

	// Wire targets (OCM-API#368) this receiver can render. Ordered by
	// preference so the first one that a share also offers is the default.
	private const SUPPORTED_TARGETS = ['iframe', 'blank', 'redirect'];
	// Re-exchange when the cached JWT has less than this much life left,
	// so it doesn't expire mid-redirect.
	private const ACCESS_TOKEN_SLACK_SECONDS = 30;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private IInitialState $initialState,
		private WebappShareMapper $mapper,
		private TokenExchanger $tokenExchanger,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * App landing page. Hydrates the Vue front-end with the user's share
	 * list and the targets this receiver supports so first paint doesn't
	 * need a round-trip.
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function index(): TemplateResponse {
		$uid = $this->userId ?? '';

		$shares = $uid !== ''
			? array_map(fn ($s) => $s->toApiArray(), $this->mapper->findAllByUid($uid))
			: [];

		$this->initialState->provideInitialState('shares', $shares);
		$this->initialState->provideInitialState('supportedTargets', self::SUPPORTED_TARGETS);

		return new TemplateResponse(Application::APP_ID, 'index');
	}

	/**
	 * Launcher. Resolves the share by (uid, token), ensures a fresh
	 * `access_token` JWT is cached, then renders the surface for the
	 * requested target (validated against what both ends support).
	 */
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function open(string $token, string $target = ''): Response {
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

		$resolved = $this->resolveTarget($share, $target);

		// 'blank' (open in a new window/tab) is initiated client-side; the
		// new tab still loads a same-tab redirect surface here.
		return match ($resolved) {
			'iframe' => $this->renderEmbed($share, $accessToken),
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

	/**
	 * Pick the wire target to render: the requested one when both this
	 * receiver and the share offer it, else the first target they share,
	 * else a safe fallback. `targets` on the row is the sender's offered
	 * set (OCM-API#368 wire vocabulary: blank/redirect/iframe).
	 */
	private function resolveTarget(WebappShare $share, string $requested): string {
		$shareTargets = json_decode($share->getTargets(), true);
		if (!is_array($shareTargets) || $shareTargets === []) {
			$shareTargets = self::SUPPORTED_TARGETS;
		}
		$available = array_values(array_intersect(self::SUPPORTED_TARGETS, $shareTargets));
		if ($available === []) {
			return 'redirect';
		}
		if ($requested !== '' && in_array($requested, $available, true)) {
			return $requested;
		}
		return $available[0];
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
