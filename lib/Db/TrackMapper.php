<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Track>
 */
class TrackMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'tickbuddy_tracks', Track::class);
	}

	/**
	 * @return Track[]
	 */
	public function findAllByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('sort_order', 'ASC');
		return $this->findEntities($qb);
	}

	public function countByUser(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	public function getMaxSortOrder(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('sort_order'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();
		return $max === false || $max === null ? 0 : (int)$max;
	}

	/**
	 * @param int[] $trackIds Ordered list of track IDs
	 */
	public function reorder(string $userId, array $trackIds): void {
		// Use negative sort_order first to avoid unique constraint violations
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('sort_order', $qb->createFunction('-(' . $qb->getColumnName('sort_order') . ' + 1)'))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();

		foreach ($trackIds as $index => $trackId) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set('sort_order', $qb->createNamedParameter($index + 1, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($trackId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
			$qb->executeStatement();
		}
	}

	public function findByIdAndUser(int $id, string $userId): Track {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}
}
