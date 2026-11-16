<?php

declare(strict_types=1);

namespace Service;

use OCA\Tickbuddy\Db\PublicLink;
use OCA\Tickbuddy\Db\PublicLinkMapper;
use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\Db\TrackMapper;
use OCA\Tickbuddy\Service\InvalidValueException;
use OCA\Tickbuddy\Service\PublicLinkLimitReachedException;
use OCA\Tickbuddy\Service\PublicLinkService;
use OCA\Tickbuddy\Service\ReadOnlyTrackException;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\DB\Exception as DbException;
use OCP\IDateTimeZone;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

final class PublicLinkServiceTest extends TestCase {
	private const NOW = 1_794_000_000;

	private PublicLinkMapper $linkMapper;
	private TrackMapper $trackMapper;
	private ISecureRandom $random;
	private ITimeFactory $timeFactory;
	private IUserManager $userManager;
	private IAppManager $appManager;
	private IUserConfig $userConfig;
	private IDateTimeZone $dateTimeZone;
	private PublicLinkService $service;
	private string $userId = 'testuser';

	protected function setUp(): void {
		$this->linkMapper = $this->createMock(PublicLinkMapper::class);
		$this->trackMapper = $this->createMock(TrackMapper::class);
		$this->random = $this->createMock(ISecureRandom::class);
		$this->random->method('generate')->willReturnCallback(fn (int $length, string $chars) => str_repeat($chars[0], $length));
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getTime')->willReturn(self::NOW);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->userConfig = $this->createMock(IUserConfig::class);
		$this->dateTimeZone = $this->createMock(IDateTimeZone::class);
		$this->service = new PublicLinkService($this->linkMapper, $this->trackMapper, $this->random, $this->timeFactory,
			$this->userManager, $this->appManager, $this->userConfig, $this->dateTimeZone);
	}

	private function givenTrack(?string $archivedAt = null): void {
		$track = new Track();
		$track->setId(3);
		$track->setName('Backup ran');
		$track->setArchivedAt($archivedAt);
		$this->trackMapper->method('findByIdAndUser')->willReturn($track);
	}

	private function makeLink(?int $revokedAt = null, ?int $lastUsedAt = null): PublicLink {
		$link = new PublicLink();
		$link->setId(7);
		$link->setUserId($this->userId);
		$link->setTrackId(3);
		$link->setName('Backup server');
		$link->setRevokedAt($revokedAt);
		$link->setLastUsedAt($lastUsedAt);
		return $link;
	}

	private function uniqueViolation(): DbException {
		return new class('duplicate') extends DbException {
			public function getReason(): ?int {
				return DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION;
			}
		};
	}

	// --- create ---

	public function testCreateMakesATokenOfThirtyTwoHumanReadableCharacters(): void {
		$this->givenTrack();
		$this->random->expects($this->once())->method('generate')->with(32, ISecureRandom::CHAR_HUMAN_READABLE);
		$this->linkMapper->method('insert')->willReturnCallback(fn (PublicLink $l) => $l);

		$link = $this->service->create($this->userId, 3, '  Backup server ', 'tick', 'activeAndPrevious');

		$this->assertSame(32, strlen($link->getToken()));
		$this->assertSame('Backup server', $link->getName());
		$this->assertSame('tick', $link->getPermission());
		$this->assertSame('activeAndPrevious', $link->getAllowedDays());
		$this->assertSame($this->userId, $link->getUserId());
		$this->assertSame(3, $link->getTrackId());
		$this->assertNull($link->getRevokedAt());
	}

	public static function eachKindProvider(): array {
		return [
			// The entity's starting values: the INSERT leaves both columns out, so
			// the migration's defaults must hold the same ones
			'Tick only, +1 day' => ['tick', 'activeAndPrevious'],
			'View only, 1 week' => ['view', 'oneWeek'],
		];
	}

	/**
	 * @dataProvider eachKindProvider
	 */
	public function testCreateMakesALinkOfEachKind(string $permission, string $allowedDays): void {
		$this->givenTrack();
		$this->linkMapper->expects($this->once())->method('insert')->willReturnCallback(fn (PublicLink $l) => $l);

		$link = $this->service->create($this->userId, 3, 'Backup server', $permission, $allowedDays);

		$this->assertSame($permission, $link->getPermission());
		$this->assertSame($allowedDays, $link->getAllowedDays());
	}

	public function testCreateTriesANewTokenWhenOneClashes(): void {
		$this->givenTrack();
		$calls = 0;
		$this->linkMapper->expects($this->exactly(2))->method('insert')
			->willReturnCallback(function (PublicLink $l) use (&$calls): PublicLink {
				if (++$calls === 1) {
					throw $this->uniqueViolation();
				}
				return $l;
			});

		$this->service->create($this->userId, 3, 'Backup server', 'view', 'oneWeek');
	}

	public function testCreateGivesUpAfterThreeClashes(): void {
		$this->givenTrack();
		$this->linkMapper->expects($this->exactly(3))->method('insert')->willThrowException($this->uniqueViolation());

		$this->expectException(DbException::class);
		$this->service->create($this->userId, 3, 'Backup server', 'view', 'oneWeek');
	}

	public static function invalidCreateProvider(): array {
		return [
			'blank name' => ['  ', 'tick', 'activeDay', 'Link name cannot be empty'],
			'256 characters' => [str_repeat('é', 256), 'tick', 'activeDay', 'Link names can be at most 255 characters'],
			'unknown permission' => ['Backup server', 'write', 'activeDay', 'Unknown value "write" for permission'],
			'unknown allowed days' => ['Backup server', 'tick', 'always', 'Unknown value "always" for allowedDays'],
			'empty allowed days' => ['Backup server', 'view', '', 'Unknown value "" for allowedDays'],
		];
	}

	/**
	 * @dataProvider invalidCreateProvider
	 */
	public function testCreateRefusesBadValues(string $name, string $permission, string $allowedDays, string $message): void {
		$this->givenTrack();
		$this->linkMapper->expects($this->never())->method('insert');

		$this->expectExceptionObject(new InvalidValueException($message));
		$this->service->create($this->userId, 3, $name, $permission, $allowedDays);
	}

	public function testCreateTakesNamesOf255Characters(): void {
		$this->givenTrack();
		$this->linkMapper->method('insert')->willReturnCallback(fn (PublicLink $l) => $l);

		$this->assertSame(255, mb_strlen($this->service->create($this->userId, 3, str_repeat('é', 255), 'tick', 'activeDay')->getName()));
	}

	public function testCreateRefusesTheHundredthLink(): void {
		$this->givenTrack();
		$this->linkMapper->method('countLiveByTrack')->with(3)->willReturn(99);
		$this->linkMapper->expects($this->never())->method('insert');

		$this->expectExceptionObject(new PublicLinkLimitReachedException('Maximum of 99 public links reached'));
		$this->service->create($this->userId, 3, 'Backup server', 'tick', 'activeDay');
	}

	public function testCreateTakesTheNinetyNinthLink(): void {
		$this->givenTrack();
		$this->linkMapper->method('countLiveByTrack')->willReturn(98);
		$this->linkMapper->expects($this->once())->method('insert')->willReturnCallback(fn (PublicLink $l) => $l);

		$this->service->create($this->userId, 3, 'Backup server', 'tick', 'activeDay');
	}

	/** Every client, whether or not it sent Prefer: handling=strict */
	public function testCreateOnAnArchivedTrackIsRefused(): void {
		$this->givenTrack('2026-11-01');
		$this->linkMapper->expects($this->never())->method('insert');

		try {
			$this->service->create($this->userId, 3, 'Backup server', 'tick', 'activeDay');
			$this->fail('Expected ReadOnlyTrackException');
		} catch (ReadOnlyTrackException $e) {
			$this->assertSame('Backup ran is archived, so nothing was changed', $e->getMessage());
			$this->assertSame('archived', $e->getReason());
		}
	}

	public function testCreateOnAnUnknownTrackIsNotFound(): void {
		$this->trackMapper->method('findByIdAndUser')->willThrowException(new DoesNotExistException(''));

		$this->expectException(DoesNotExistException::class);
		$this->service->create($this->userId, 3, 'Backup server', 'tick', 'activeDay');
	}

	// --- list, rename, revoke ---

	public function testListChecksTheTrack(): void {
		$this->trackMapper->expects($this->once())->method('findByIdAndUser')->with(3, $this->userId)->willThrowException(new DoesNotExistException(''));

		$this->expectException(DoesNotExistException::class);
		$this->service->list($this->userId, 3);
	}

	public function testListOfEveryTrack(): void {
		$this->trackMapper->expects($this->never())->method('findByIdAndUser');
		$this->linkMapper->expects($this->once())->method('findLiveByUser')->with($this->userId, null)->willReturn([]);

		$this->assertSame([], $this->service->list($this->userId, null));
	}

	public function testRenameTrimsTheName(): void {
		$this->linkMapper->method('findByIdAndUser')->with(7, $this->userId)->willReturn($this->makeLink());
		$this->linkMapper->expects($this->once())->method('update')->willReturnArgument(0);

		$this->assertSame('NAS', $this->service->rename($this->userId, 7, ' NAS ')->getName());
	}

	public function testRenameRefusesABlankName(): void {
		$this->linkMapper->method('findByIdAndUser')->willReturn($this->makeLink());
		$this->linkMapper->expects($this->never())->method('update');

		$this->expectExceptionObject(new InvalidValueException('Link name cannot be empty'));
		$this->service->rename($this->userId, 7, '');
	}

	public function testRenameOfARevokedLinkIsNotFound(): void {
		$this->linkMapper->method('findByIdAndUser')->willReturn($this->makeLink(revokedAt: self::NOW - 10));
		$this->linkMapper->expects($this->never())->method('update');

		$this->expectException(DoesNotExistException::class);
		$this->service->rename($this->userId, 7, 'NAS');
	}

	public function testRevokeMarksTheLinkAndKeepsTheRow(): void {
		$this->linkMapper->method('findByIdAndUser')->willReturn($this->makeLink());
		$this->linkMapper->expects($this->never())->method('delete');
		$this->linkMapper->expects($this->once())->method('update')
			->with($this->callback(fn (PublicLink $l) => $l->getRevokedAt() === self::NOW))
			->willReturnArgument(0);

		$this->service->revoke($this->userId, 7);
	}

	public function testRevokingTwiceKeepsTheFirstTime(): void {
		$this->linkMapper->method('findByIdAndUser')->willReturn($this->makeLink(revokedAt: self::NOW - 100));
		$this->linkMapper->expects($this->never())->method('update');

		$this->service->revoke($this->userId, 7);
	}

	public function testRevokeOfAnotherUsersLinkIsNotFound(): void {
		$this->linkMapper->method('findByIdAndUser')->with(7, $this->userId)->willThrowException(new DoesNotExistException(''));

		$this->expectException(DoesNotExistException::class);
		$this->service->revoke($this->userId, 7);
	}

	// --- calls ---

	public function testFindByTokenAnswersLiveAndRevokedLinks(): void {
		$token = str_repeat('a', 32);
		$revoked = $this->makeLink(revokedAt: self::NOW);
		$this->linkMapper->method('findByToken')->with($token)->willReturn($revoked);

		$this->assertSame($revoked, $this->service->findByToken($token));
	}

	public function testFindByTokenOfAnUnknownToken(): void {
		$this->linkMapper->method('findByToken')->willThrowException(new DoesNotExistException(''));

		$this->assertNull($this->service->findByToken(str_repeat('a', 32)));
	}

	public static function impossibleTokenProvider(): array {
		return [
			'too short' => ['abc'],
			'too long' => [str_repeat('a', 33)],
			// 0, 1, l, O and others are not in the alphabet
			'outside the alphabet' => [str_repeat('0', 32)],
		];
	}

	/**
	 * @dataProvider impossibleTokenProvider
	 */
	public function testFindByTokenSkipsTheQueryForATokenNeverHandedOut(string $token): void {
		$this->linkMapper->expects($this->never())->method('findByToken');

		$this->assertNull($this->service->findByToken($token));
	}

	private function givenOwner(?bool $enabled, bool $appEnabled = true): void {
		if ($enabled === null) {
			$this->userManager->method('get')->with($this->userId)->willReturn(null);
			return;
		}
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn($enabled);
		$this->userManager->method('get')->with($this->userId)->willReturn($user);
		$this->appManager->method('isEnabledForUser')->with('tickbuddy', $user)->willReturn($appEnabled);
	}

	public function testALinkOfAnOwnerWhoCanSignInIsLive(): void {
		$this->givenOwner(true);

		$this->assertTrue($this->service->isLive($this->makeLink()));
	}

	public function testARevokedLinkIsNotLive(): void {
		$this->givenOwner(true);

		$this->assertFalse($this->service->isLive($this->makeLink(revokedAt: self::NOW)));
	}

	public function testALinkOfADeletedOwnerIsNotLive(): void {
		$this->givenOwner(null);

		$this->assertFalse($this->service->isLive($this->makeLink()));
	}

	public function testALinkOfADisabledOwnerIsNotLive(): void {
		$this->givenOwner(false);

		$this->assertFalse($this->service->isLive($this->makeLink()));
	}

	public function testALinkOfAnOwnerWithoutTickbuddyIsNotLive(): void {
		$this->givenOwner(true, false);

		$this->assertFalse($this->service->isLive($this->makeLink()));
	}

	public static function touchProvider(): array {
		return [
			'never used' => [null, true],
			'61 seconds ago' => [self::NOW - 61, true],
			'60 seconds ago' => [self::NOW - 60, false],
			'just now' => [self::NOW, false],
		];
	}

	/**
	 * @dataProvider touchProvider
	 */
	public function testTouchWritesAtMostOnceAMinute(?int $lastUsedAt, bool $writes): void {
		$this->linkMapper->expects($writes ? $this->once() : $this->never())->method('touch')->with(7, self::NOW);

		$this->service->touch($this->makeLink(lastUsedAt: $lastUsedAt));
	}

	// --- today in the owner's time zone ---

	private function givenServerTime(string $utc): void {
		$this->timeFactory->method('getDateTime')
			->willReturnCallback(fn (string $time, ?\DateTimeZone $zone) => (new \DateTime($utc, new \DateTimeZone('UTC')))->setTimezone($zone ?? new \DateTimeZone('UTC')));
	}

	public function testTodayIsTheOwnersDayInTheirTimeZone(): void {
		$this->givenServerTime('2026-11-15 23:30:00');
		$this->userConfig->method('getValueString')->with($this->userId, 'core', 'timezone', '')->willReturn('Europe/Brussels');
		$this->dateTimeZone->expects($this->never())->method('getTimeZone');

		$this->assertSame('2026-11-16', $this->service->todayFor($this->userId));
	}

	public function testTodayWithoutAZoneIsTheServersDefault(): void {
		$this->givenServerTime('2026-11-15 23:30:00');
		$this->userConfig->method('getValueString')->willReturn('');
		$this->dateTimeZone->method('getDefaultTimeZone')->willReturn(new \DateTimeZone('America/New_York'));
		// Not the calling session's browser offset
		$this->dateTimeZone->expects($this->never())->method('getTimeZone');

		$this->assertSame('2026-11-15', $this->service->todayFor($this->userId));
	}

	public function testTodayWithAnUnknownZoneIsTheServersDefault(): void {
		$this->givenServerTime('2026-11-15 23:30:00');
		$this->userConfig->method('getValueString')->willReturn('Mars/Olympus_Mons');
		$this->dateTimeZone->method('getDefaultTimeZone')->willReturn(new \DateTimeZone('UTC'));

		$this->assertSame('2026-11-15', $this->service->todayFor($this->userId));
	}
}
