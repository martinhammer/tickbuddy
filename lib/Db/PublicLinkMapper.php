<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Public links. "Live" is a link not revoked; revoked rows stay until their
 * track is deleted, so a call to one can be told from a guess.
 *
 * @extends QBMapper<PublicLink>
 */
class PublicLinkMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'tickbuddy_public_links', PublicLink::class);
	}

	/**
	 * The user's live links, oldest first.
	 *
	 * @param ?int $trackId One track's only, or null for every track's
	 * @return PublicLink[]
	 */
	public function findLiveByUser(string $userId, ?int $trackId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('revoked_at'));
		if ($trackId !== null) {
			$qb->andWhere($qb->expr()->eq('track_id', $qb->createNamedParameter($trackId, IQueryBuilder::PARAM_INT)));
		}
		$qb->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * A link by its token, live or revoked.
	 *
	 * @throws DoesNotExistException
	 */
	public function findByToken(string $token): PublicLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));
		return $this->findEntity($qb);
	}

	/**
	 * A link of the user's, live or revoked.
	 *
	 * @throws DoesNotExistException
	 */
	public function findByIdAndUser(int $id, string $userId): PublicLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}

	/**
	 * The number of live links per track, in one query. Tracks without any are
	 * left out.
	 *
	 * @return array<int, int> Track id => count
	 */
	public function countLiveByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('track_id')
			->selectAlias($qb->func()->count('*'), 'links')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('revoked_at'))
			->groupBy('track_id');

		$result = $qb->executeQuery();
		/** @var list<array{track_id: mixed, links: mixed}> $rows */
		$rows = $result->fetchAll();
		$result->closeCursor();

		$counts = [];
		foreach ($rows as $row) {
			$counts[(int)$row['track_id']] = (int)$row['links'];
		}
		return $counts;
	}

	public function countLiveByTrack(int $trackId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'links'))
			->from($this->getTableName())
			->where($qb->expr()->eq('track_id', $qb->createNamedParameter($trackId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('revoked_at'));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * Revoke every live link of a track (on archive).
	 */
	public function revokeByTrack(int $trackId, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('revoked_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('track_id', $qb->createNamedParameter($trackId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('revoked_at'));
		$qb->executeStatement();
	}

	/**
	 * Note a call. Only when the stored time is empty or over a minute old, in
	 * the statement itself, so a busy link writes at most once a minute and two
	 * calls at once write once.
	 */
	public function touch(int $id, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('last_used_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('last_used_at'),
				$qb->expr()->lt('last_used_at', $qb->createNamedParameter($now - 60, IQueryBuilder::PARAM_INT)),
			));
		$qb->executeStatement();
	}

	public function deleteByTrackId(int $trackId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('track_id', $qb->createNamedParameter($trackId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteAllByUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}
}
