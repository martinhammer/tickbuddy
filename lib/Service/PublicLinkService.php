<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Service;

use OCA\Tickbuddy\AppInfo\Application;
use OCA\Tickbuddy\Db\PublicLink;
use OCA\Tickbuddy\Db\PublicLinkMapper;
use OCA\Tickbuddy\Db\TrackMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\DB\Exception as DbException;
use OCP\IDateTimeZone;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;

/**
 * Public links: web addresses that tick or read one track of one user
 * without signing in. The token is the credential. Revoking keeps the row,
 * marked revoked, so a call to a revoked link isn't counted as a guess.
 */
class PublicLinkService {
	/** The track limit's number */
	public const MAX_LINKS_PER_TRACK = 99;
	/** The name column's size, as for track names */
	public const MAX_NAME_LENGTH = 255;
	/** About 180 bits from Nextcloud's human-readable alphabet, as Files' share links use */
	public const TOKEN_LENGTH = 32;
	/** Inserts tried when a new token clashes with a stored one (practically never) */
	private const TOKEN_ATTEMPTS = 3;
	/** "Last used" is written at most this often per link */
	private const TOUCH_SECONDS = 60;

	public function __construct(
		private PublicLinkMapper $linkMapper,
		private TrackMapper $trackMapper,
		private ISecureRandom $random,
		private ITimeFactory $timeFactory,
		private IUserManager $userManager,
		private IAppManager $appManager,
		private IUserConfig $userConfig,
		private IDateTimeZone $dateTimeZone,
	) {
	}

	/**
	 * The user's live links, oldest first: every track's, or one track's.
	 *
	 * @param ?int $trackId One track only, or null for every track
	 * @return PublicLink[]
	 * @throws DoesNotExistException if the track isn't the user's
	 */
	public function list(string $userId, ?int $trackId): array {
		if ($trackId !== null) {
			$this->trackMapper->findByIdAndUser($trackId, $userId);
		}
		return $this->linkMapper->findLiveByUser($userId, $trackId);
	}

	/**
	 * Create a link. Checks run in the order: track, archived, name,
	 * permission, allowed days, the limit. The limit is a count before the
	 * insert, as the track limit is, so two creates at once can end at 100.
	 *
	 * @throws DoesNotExistException if the track isn't the user's
	 * @throws ReadOnlyTrackException if the track is archived, for every client:
	 *                                only clients that predate 423 get the quiet 200, and none calls this
	 * @throws InvalidValueException for a bad name, permission or allowed days
	 * @throws PublicLinkLimitReachedException
	 * @throws DbException if new tokens kept clashing
	 */
	public function create(string $userId, int $trackId, string $name, string $permission, string $allowedDays): PublicLink {
		$track = $this->trackMapper->findByIdAndUser($trackId, $userId);
		if ($track->getArchivedAt() !== null) {
			throw new ReadOnlyTrackException($track->getName() . ' is archived, so nothing was changed', ErrorReason::ARCHIVED);
		}
		$name = self::cleanName($name);
		if (!in_array($permission, PublicLink::PERMISSIONS, true)) {
			throw new InvalidValueException('Unknown value "' . $permission . '" for permission');
		}
		if (!in_array($allowedDays, EditableDays::VALUES, true)) {
			throw new InvalidValueException('Unknown value "' . $allowedDays . '" for allowedDays');
		}
		if ($this->linkMapper->countLiveByTrack($trackId) >= self::MAX_LINKS_PER_TRACK) {
			throw new PublicLinkLimitReachedException('Maximum of ' . self::MAX_LINKS_PER_TRACK . ' public links reached');
		}

		for ($attempt = 1; ; $attempt++) {
			$link = new PublicLink();
			$link->setUserId($userId);
			$link->setTrackId($trackId);
			$link->setToken($this->random->generate(self::TOKEN_LENGTH, ISecureRandom::CHAR_HUMAN_READABLE));
			$link->setName($name);
			$link->setPermission($permission);
			$link->setAllowedDays($allowedDays);
			try {
				return $this->linkMapper->insert($link);
			} catch (DbException $e) {
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION || $attempt >= self::TOKEN_ATTEMPTS) {
					throw $e;
				}
			}
		}
	}

	/**
	 * @throws DoesNotExistException if the link isn't the user's, or is revoked
	 * @throws InvalidValueException for a bad name
	 */
	public function rename(string $userId, int $id, string $name): PublicLink {
		$link = $this->linkMapper->findByIdAndUser($id, $userId);
		if ($link->getRevokedAt() !== null) {
			throw new DoesNotExistException('Public link revoked');
		}
		$link->setName(self::cleanName($name));
		return $this->linkMapper->update($link);
	}

	/**
	 * Revoke a link; a revoked one stays as it is.
	 *
	 * @throws DoesNotExistException if the link isn't the user's
	 */
	public function revoke(string $userId, int $id): void {
		$link = $this->linkMapper->findByIdAndUser($id, $userId);
		if ($link->getRevokedAt() !== null) {
			return;
		}
		$link->setRevokedAt($this->timeFactory->getTime());
		$this->linkMapper->update($link);
	}

	/**
	 * The link holding a token, live or revoked; null when the token was never
	 * a link (a guess, which the caller throttles).
	 */
	public function findByToken(string $token): ?PublicLink {
		// Anything else was never handed out, so needs no query
		if (strlen($token) !== self::TOKEN_LENGTH || strspn($token, ISecureRandom::CHAR_HUMAN_READABLE) !== self::TOKEN_LENGTH) {
			return null;
		}
		try {
			return $this->linkMapper->findByToken($token);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Whether a link answers calls: not revoked, and its owner can sign in
	 * (exists, is enabled, has Tickbuddy enabled). A public page skips
	 * Nextcloud's own app check, and a deleted user's links aren't deleted.
	 */
	public function isLive(PublicLink $link): bool {
		if ($link->getRevokedAt() !== null) {
			return false;
		}
		$owner = $this->userManager->get($link->getUserId());
		return $owner !== null
			&& $owner->isEnabled()
			&& $this->appManager->isEnabledForUser(Application::APP_ID, $owner);
	}

	/**
	 * Note a call for "Last used", at most once a minute.
	 */
	public function touch(PublicLink $link): void {
		$now = $this->timeFactory->getTime();
		$last = $link->getLastUsedAt();
		if ($last !== null && $last >= $now - self::TOUCH_SECONDS) {
			return;
		}
		$this->linkMapper->touch($link->getId(), $now);
	}

	/**
	 * Today in the user's Nextcloud time zone (Personal settings), else the
	 * server's default. Not IDateTimeZone::getTimeZone($ts, $userId): for a user
	 * with no zone set, it falls back to the calling session's browser offset,
	 * which on a public page is whoever called.
	 *
	 * @return string YYYY-MM-DD
	 */
	public function todayFor(string $userId): string {
		$zone = null;
		$name = $this->userConfig->getValueString($userId, 'core', 'timezone', '');
		if ($name !== '') {
			try {
				$zone = new \DateTimeZone($name);
			} catch (\Exception) {
				// Not a zone PHP knows: the server's default
			}
		}
		$zone ??= $this->dateTimeZone->getDefaultTimeZone();
		return $this->timeFactory->getDateTime('now', $zone)->format('Y-m-d');
	}

	/**
	 * @return array<int, int> Track id => live links, tracks without any left out
	 */
	public function countsByTrack(string $userId): array {
		return $this->linkMapper->countLiveByUser($userId);
	}

	public function countForTrack(int $trackId): int {
		return $this->linkMapper->countLiveByTrack($trackId);
	}

	/**
	 * A link name trimmed, refused when blank or longer than the column. Names may repeat.
	 *
	 * @throws InvalidValueException
	 */
	private static function cleanName(string $name): string {
		$name = trim($name);
		if ($name === '') {
			throw new InvalidValueException('Link name cannot be empty');
		}
		if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
			throw new InvalidValueException('Link names can be at most ' . self::MAX_NAME_LENGTH . ' characters');
		}
		return $name;
	}
}
