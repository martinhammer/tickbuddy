<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Tick>
 */
class TickMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'tickbuddy_ticks', Tick::class);
	}

	/**
	 * @return Tick[]
	 */
	public function findAllByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('date', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * @return Tick[]
	 */
	public function findByUserAndDateRange(string $userId, string $from, string $to): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->gte('date', $qb->createNamedParameter($from)))
			->andWhere($qb->expr()->lte('date', $qb->createNamedParameter($to)))
			->orderBy('date', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * First and last tick date per track for a user.
	 *
	 * Aggregates in the database rather than returning rows: the result is
	 * bounded by the track limit, while the tick history it summarises is not.
	 * Tracks without any ticks are absent from the result, matching the sparse
	 * storage convention. Served index-only by the (user_id, track_id, date)
	 * unique index.
	 *
	 * @return list<array{trackId: int, oldest: string, newest: string}>
	 */
	public function findBoundsByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('track_id')
			->selectAlias($qb->func()->min('date'), 'oldest')
			->selectAlias($qb->func()->max('date'), 'newest')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->groupBy('track_id')
			->orderBy('track_id', 'ASC');

		$result = $qb->executeQuery();
		/** @var list<array{track_id: mixed, oldest: mixed, newest: mixed}> $rows */
		$rows = $result->fetchAll();
		$result->closeCursor();

		$bounds = [];
		foreach ($rows as $row) {
			$bounds[] = [
				'trackId' => (int)$row['track_id'],
				'oldest' => (string)$row['oldest'],
				'newest' => (string)$row['newest'],
			];
		}

		return $bounds;
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByUserTrackDate(string $userId, int $trackId, string $date): Tick {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('track_id', $qb->createNamedParameter($trackId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('date', $qb->createNamedParameter($date)));
		return $this->findEntity($qb);
	}

	public function deleteAllByUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	public function deleteByTrackId(int $trackId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('track_id', $qb->createNamedParameter($trackId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
