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

	public function deleteByTrackId(int $trackId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('track_id', $qb->createNamedParameter($trackId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
