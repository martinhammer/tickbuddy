<?php

declare(strict_types=1);

namespace Controller;

use OCA\Tickbuddy\Controller\PublicLinkController;
use OCA\Tickbuddy\Db\IdempotencyKey;
use OCA\Tickbuddy\Db\IdempotencyKeyMapper;
use OCA\Tickbuddy\Db\PublicLink;
use OCA\Tickbuddy\Service\IdempotencyService;
use OCA\Tickbuddy\Service\InvalidValueException;
use OCA\Tickbuddy\Service\PublicLinkLimitReachedException;
use OCA\Tickbuddy\Service\PublicLinkService;
use OCA\Tickbuddy\Service\ReadOnlyTrackException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PublicLinkControllerTest extends TestCase {
	private const KEY = '2b0c8d4e-3f7a-4b1c-9d2e-5f6a7b8c9d0e';

	private IRequest $request;
	/** @var array<string, string> */
	private array $headers = [];
	private PublicLinkService $links;
	private IdempotencyKeyMapper $idempotencyMapper;
	private IURLGenerator $urlGenerator;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getHeader')->willReturnCallback(fn (string $name) => $this->headers[$name] ?? '');
		$this->links = $this->createMock(PublicLinkService::class);
		$this->idempotencyMapper = $this->createMock(IdempotencyKeyMapper::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('linkToOCSRouteAbsolute')
			->willReturnCallback(fn (string $route, array $args) => $route === 'tickbuddy.ping.call'
				? 'https://cloud.example.com/ocs/v2.php/apps/tickbuddy/ping/' . $args['token']
				: 'wrong route');
	}

	private function controller(): PublicLinkController {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_794_000_000);
		$idempotency = new IdempotencyService($this->request, $this->idempotencyMapper, $time, $this->createMock(LoggerInterface::class));
		return new PublicLinkController('tickbuddy', $this->request, $this->links, $idempotency, $this->urlGenerator, 'testuser');
	}

	private function makeLink(int $id = 7, ?int $lastUsedAt = 1_763_262_720): PublicLink {
		$link = new PublicLink();
		$link->setId($id);
		$link->setUserId('testuser');
		$link->setTrackId(3);
		$link->setToken('Hx4abcdefgijkmnopqrstwxyzABCDEFG');
		$link->setName('Backup server');
		$link->setPermission('tick');
		$link->setAllowedDays('activeAndPrevious');
		$link->setLastUsedAt($lastUsedAt);
		return $link;
	}

	private function assertError(DataResponse $response, int $status, string $message, string $reason): void {
		$this->assertSame($status, $response->getStatus());
		$this->assertSame(['message' => $message, 'reason' => $reason], $response->getData());
	}

	public function testListAnswersOneTracksLinksWithTheirAddress(): void {
		$this->links->expects($this->once())->method('list')->with('testuser', 3)
			->willReturn([$this->makeLink(), $this->makeLink(8, null)]);

		$response = $this->controller()->index(3);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([
			'id' => 7,
			'trackId' => 3,
			'name' => 'Backup server',
			'permission' => 'tick',
			'allowedDays' => 'activeAndPrevious',
			'url' => 'https://cloud.example.com/ocs/v2.php/apps/tickbuddy/ping/Hx4abcdefgijkmnopqrstwxyzABCDEFG',
			'lastUsedAt' => 1_763_262_720,
		], $response->getData()[0]);
		$this->assertNull($response->getData()[1]['lastUsedAt']);
	}

	public function testListOfEveryTrack(): void {
		$this->links->expects($this->once())->method('list')->with('testuser', null)->willReturn([]);

		$this->assertSame([], $this->controller()->index()->getData());
	}

	public function testListOfAnUnknownTrack(): void {
		$this->links->method('list')->willThrowException(new DoesNotExistException(''));

		$this->assertError($this->controller()->index(9), 404, 'Track not found', 'trackNotFound');
	}

	public function testCreateAnswers201WithTheLink(): void {
		$this->links->expects($this->once())->method('create')->with('testuser', 3, 'Backup server', 'tick', 'activeAndPrevious')
			->willReturn($this->makeLink(lastUsedAt: null));

		$response = $this->controller()->create(3, 'Backup server', 'tick', 'activeAndPrevious');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(7, $response->getData()['id']);
		$this->assertNull($response->getData()['lastUsedAt']);
	}

	public static function createErrorProvider(): array {
		return [
			'track' => [new DoesNotExistException(''), 404, 'Track not found', 'trackNotFound'],
			'archived' => [new ReadOnlyTrackException('Backup ran is archived, so nothing was changed', 'archived'), 423, 'Backup ran is archived, so nothing was changed', 'archived'],
			'name' => [new InvalidValueException('Link name cannot be empty'), 400, 'Link name cannot be empty', 'invalidValue'],
			'limit' => [new PublicLinkLimitReachedException('Maximum of 99 public links reached'), 403, 'Maximum of 99 public links reached', 'publicLinkLimitReached'],
		];
	}

	/**
	 * @dataProvider createErrorProvider
	 */
	public function testCreateErrors(\Throwable $e, int $status, string $message, string $reason): void {
		$this->links->method('create')->willThrowException($e);

		$this->assertError($this->controller()->create(3, 'x', 'tick', 'activeDay'), $status, $message, $reason);
	}

	public function testARepeatedCreateMakesOneLink(): void {
		$this->headers['Idempotency-Key'] = self::KEY;
		$this->idempotencyMapper->method('claim')->willThrowException(new class('duplicate') extends \OCP\DB\Exception {
			public function getReason(): ?int {
				return self::REASON_UNIQUE_CONSTRAINT_VIOLATION;
			}
		});
		$row = new IdempotencyKey();
		$row->setFingerprint(IdempotencyService::fingerprint('publicLinks.create', [3, 'Backup server', 'tick', 'activeDay']));
		$row->setStatus(201);
		$row->setResponse('{"id":7}');
		$this->idempotencyMapper->method('find')->willReturn($row);
		$this->links->expects($this->never())->method('create');

		$response = $this->controller()->create(3, 'Backup server', 'tick', 'activeDay');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['id' => 7], $response->getData());
	}

	public function testRename(): void {
		$renamed = $this->makeLink();
		$renamed->setName('NAS');
		$this->links->expects($this->once())->method('rename')->with('testuser', 7, 'NAS')->willReturn($renamed);

		$response = $this->controller()->update(7, 'NAS');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('NAS', $response->getData()['name']);
	}

	public function testRenameErrors(): void {
		$this->links->method('rename')->willThrowException(new DoesNotExistException(''));
		$this->assertError($this->controller()->update(7, 'NAS'), 404, 'Public link not found', 'publicLinkNotFound');
	}

	public function testRenameWithABadName(): void {
		$this->links->method('rename')->willThrowException(new InvalidValueException('Link names can be at most 255 characters'));
		$this->assertError($this->controller()->update(7, 'x'), 400, 'Link names can be at most 255 characters', 'invalidValue');
	}

	public function testRevoke(): void {
		$this->links->expects($this->once())->method('revoke')->with('testuser', 7);

		$this->assertSame(204, $this->controller()->destroy(7)->getStatus());
	}

	public function testRevokeOfAnotherUsersLink(): void {
		$this->links->method('revoke')->willThrowException(new DoesNotExistException(''));

		$this->assertError($this->controller()->destroy(7), 404, 'Public link not found', 'publicLinkNotFound');
	}
}
