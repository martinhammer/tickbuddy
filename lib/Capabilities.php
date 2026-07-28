<?php

declare(strict_types=1);

namespace OCA\Tickbuddy;

use OCA\Tickbuddy\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Capabilities\ICapability;

/**
 * Advertises Tickbuddy to clients through the capabilities endpoint
 * (GET /ocs/v2.php/cloud/capabilities -> data.capabilities.tickbuddy).
 *
 * Mobile clients read this on connect to discover the installed app
 * version and which optional API features the server supports, instead
 * of sniffing behaviour or the app version string.
 *
 * Note: this is a core Nextcloud route, not a Tickbuddy OCS #[ApiRoute],
 * so the return shape is inlined below rather than declared as a named
 * @psalm-type — the openapi-extractor only resolves named types from
 * ResponseDefinitions and would fatal on one declared here.
 *
 * @psalm-suppress UnusedClass
 */
class Capabilities implements ICapability {
	/**
	 * Version of the Tickbuddy client-facing API contract. Bump this when
	 * the API changes in a way clients must react to; the app version
	 * ({@see getCapabilities}) tracks the installed build, this tracks
	 * what a client may assume.
	 */
	public const API_VERSION = 1;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IAppManager $appManager,
	) {
	}

	/**
	 * @return array{
	 *     tickbuddy: array{
	 *         version: string,
	 *         apiVersion: int,
	 *         features: array{
	 *             import: bool,
	 *             export: bool,
	 *             counterTracks: bool,
	 *             privateTracks: bool,
	 *             tickBounds: bool,
	 *             syncDelta: bool,
	 *             counterIncrement: bool,
	 *         },
	 *     },
	 * }
	 */
	public function getCapabilities(): array {
		return [
			'tickbuddy' => [
				'version' => $this->appManager->getAppVersion(Application::APP_ID),
				'apiVersion' => self::API_VERSION,
				'features' => [
					// Import/export of Tickmate .db and Tickbuddy .json.
					'import' => true,
					'export' => true,
					// Track types and per-track privacy flag.
					'counterTracks' => true,
					'privateTracks' => true,
					// GET /api/ticks/bounds — first/last tick date per track.
					// Absent on older servers, where the route 404s; clients
					// fall back to deriving bounds from a wide range fetch.
					'tickBounds' => true,
					// Known gaps (see CLAUDE.md): no "changed since" delta
					// endpoint and no commutative counter increment yet.
					// Flip to true here when the endpoints land.
					'syncDelta' => false,
					'counterIncrement' => false,
				],
			],
		];
	}
}
