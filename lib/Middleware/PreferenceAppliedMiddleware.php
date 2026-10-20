<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Middleware;

use OCA\Tickbuddy\Service\StrictHandling;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\OCSController;

/**
 * Confirm `Prefer: handling=strict` (RFC 7240 §3) on every response of the app's API
 * to a request that sent it, so a client can tell this server from an older one (or
 * a proxy that dropped the header).
 *
 * Runs before core's OCS middleware wraps the DataResponse, whose headers the OCS
 * response keeps.
 */
class PreferenceAppliedMiddleware extends Middleware {
	/** @psalm-suppress PossiblyUnusedMethod Built by Nextcloud's DI container */
	public function __construct(
		private StrictHandling $strictHandling,
	) {
	}

	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		if ($controller instanceof OCSController && $this->strictHandling->requested()) {
			$response->addHeader('Preference-Applied', 'handling=strict');
		}
		return $response;
	}
}
