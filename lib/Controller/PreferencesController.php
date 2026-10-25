<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Controller;

use OCA\Tickbuddy\AppInfo\Application;
use OCA\Tickbuddy\ResponseDefinitions;
use OCA\Tickbuddy\Service\ErrorReason;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Config\IUserConfig;
use OCP\IRequest;

/**
 * The web's toolbar choices, one value per device class (desktop, mobile).
 * Only the web reads these; other clients keep their own.
 *
 * @psalm-import-type TickbuddyError from ResponseDefinitions
 * @psalm-import-type TickbuddyPreferences from ResponseDefinitions
 *
 * @psalm-suppress UnusedClass
 */
class PreferencesController extends OCSController {
	private const LAYOUTS = ['daysDown', 'tracksDown'];
	private const EDITABLE_DAYS = ['activeDay', 'activeAndPrevious', 'oneWeek', 'allDays'];

	/**
	 * Each choice that takes a name: its API field, user config key, allowed
	 * values and starting value (the first one listed for a field stored
	 * nowhere yet)
	 */
	private const STRING_FIELDS = [
		'journalLayoutDesktop' => ['journal_layout_desktop', self::LAYOUTS, 'daysDown'],
		'journalLayoutMobile' => ['journal_layout_mobile', self::LAYOUTS, 'daysDown'],
		'editableDaysDesktop' => ['editable_days_desktop', self::EDITABLE_DAYS, 'allDays'],
		'editableDaysMobile' => ['editable_days_mobile', self::EDITABLE_DAYS, 'activeDay'],
	];

	/** Each yes/no choice: its API field and user config key. Both start off. */
	private const BOOL_FIELDS = [
		'showPrivateDesktop' => 'show_private_desktop',
		'showPrivateMobile' => 'show_private_mobile',
	];

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserConfig $config,
		private string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Get the current user's preferences
	 *
	 * @return DataResponse<Http::STATUS_OK, TickbuddyPreferences, array{}>
	 *
	 * 200: Preferences returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/preferences')]
	public function index(): DataResponse {
		return new DataResponse($this->current());
	}

	/**
	 * Update the current user's preferences
	 *
	 * Only the fields sent change. Every value is checked before anything is
	 * saved, so one unknown value saves nothing.
	 *
	 * @param ?string $journalLayoutDesktop Journal layout on desktop: "daysDown" or "tracksDown"
	 * @param ?string $journalLayoutMobile Journal layout on mobile: "daysDown" or "tracksDown"
	 * @param ?bool $showPrivateDesktop Whether private tracks are shown on desktop
	 * @param ?bool $showPrivateMobile Whether private tracks are shown on mobile
	 * @param ?string $editableDaysDesktop Days a click can change on desktop: "activeDay", "activeAndPrevious", "oneWeek" or "allDays"
	 * @param ?string $editableDaysMobile Days a click can change on mobile: "activeDay", "activeAndPrevious", "oneWeek" or "allDays"
	 * @return DataResponse<Http::STATUS_OK, TickbuddyPreferences, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, TickbuddyError, array{}>
	 *
	 * 200: Preferences updated, all of them returned
	 * 400: A value isn't one of the allowed ones; nothing saved
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/preferences')]
	public function update(
		?string $journalLayoutDesktop = null,
		?string $journalLayoutMobile = null,
		?bool $showPrivateDesktop = null,
		?bool $showPrivateMobile = null,
		?string $editableDaysDesktop = null,
		?string $editableDaysMobile = null,
	): DataResponse {
		$strings = [
			'journalLayoutDesktop' => $journalLayoutDesktop,
			'journalLayoutMobile' => $journalLayoutMobile,
			'editableDaysDesktop' => $editableDaysDesktop,
			'editableDaysMobile' => $editableDaysMobile,
		];
		$bools = [
			'showPrivateDesktop' => $showPrivateDesktop,
			'showPrivateMobile' => $showPrivateMobile,
		];

		foreach ($strings as $field => $value) {
			if ($value !== null && !in_array($value, self::STRING_FIELDS[$field][1], true)) {
				return new DataResponse([
					'message' => sprintf('Unknown value "%s" for %s', $value, $field),
					'reason' => ErrorReason::INVALID_VALUE,
				], Http::STATUS_BAD_REQUEST);
			}
		}

		foreach ($strings as $field => $value) {
			if ($value !== null) {
				$this->config->setValueString($this->userId, Application::APP_ID, self::STRING_FIELDS[$field][0], $value);
			}
		}
		foreach ($bools as $field => $value) {
			if ($value !== null) {
				$this->config->setValueBool($this->userId, Application::APP_ID, self::BOOL_FIELDS[$field], $value);
			}
		}

		return new DataResponse($this->current());
	}

	/**
	 * Every preference as stored, a missing or no longer valid value answering
	 * its starting value
	 *
	 * @return TickbuddyPreferences
	 */
	private function current(): array {
		$string = function (string $field): string {
			[$key, $allowed, $default] = self::STRING_FIELDS[$field];
			$value = $this->config->getValueString($this->userId, Application::APP_ID, $key, $default);
			return in_array($value, $allowed, true) ? $value : $default;
		};
		$bool = fn (string $field): bool => $this->config->getValueBool($this->userId, Application::APP_ID, self::BOOL_FIELDS[$field], false);

		return [
			'journalLayoutDesktop' => $string('journalLayoutDesktop'),
			'journalLayoutMobile' => $string('journalLayoutMobile'),
			'showPrivateDesktop' => $bool('showPrivateDesktop'),
			'showPrivateMobile' => $bool('showPrivateMobile'),
			'editableDaysDesktop' => $string('editableDaysDesktop'),
			'editableDaysMobile' => $string('editableDaysMobile'),
		];
	}
}
