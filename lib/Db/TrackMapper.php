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
	 * @param bool $forUpdate Lock the rows until the transaction ends (ignored on SQLite,
	 *                        which locks the whole database for a write transaction)
	 * @return Track[]
	 */
	public function findAllByUser(string $userId, bool $forUpdate = false): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('sort_order', 'ASC');
		if ($forUpdate) {
			$qb->forUpdate();
		}
		return $this->findEntities($qb);
	}

	/**
	 * Tracks that aren't archived.
	 *
	 * @return Track[]
	 */
	public function findActiveByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('archived_at'))
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
		/** @var false|int|string|null $max */
		$max = $result->fetchOne();
		$result->closeCursor();
		return $max === false || $max === null ? 0 : (int)$max;
	}

	/**
	 * Number the given tracks 1..n in the order given. Pass every track of the
	 * user ({@see \OCA\Tickbuddy\Service\TrackService::reorder} does), in a
	 * transaction: a failure halfway leaves the tracks moved below 1.
	 *
	 * Two steps, each keeping every value unique at every row, as MySQL and
	 * SQLite check the unique index row by row rather than at the end of the
	 * statement: first move the tracks below both 1 and their lowest value,
	 * keeping their order, then number them. Any starting values work, even
	 * a mix of positive and negative ones, so an order an older version left
	 * halfway done is repaired too.
	 *
	 * @param int[] $trackIds Ordered list of track IDs
	 */
	public function reorder(string $userId, array $trackIds): void {
		if ($trackIds === []) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->min('sort_order'), 'lowest')
			->selectAlias($qb->func()->max('sort_order'), 'highest')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('id', $qb->createNamedParameter($trackIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$result = $qb->executeQuery();
		/** @var array{lowest: int|string|null, highest: int|string|null}|false $row */
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false || $row['lowest'] === null || $row['highest'] === null) {
			return;
		}
		// Every moved value ends up below min(lowest, 1), so it meets neither a
		// row still to move nor a number the second step hands out
		$shift = (int)$row['highest'] - min((int)$row['lowest'], 1) + 1;

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('sort_order', $qb->createFunction($qb->getColumnName('sort_order') . ' - ' . $qb->createNamedParameter($shift, IQueryBuilder::PARAM_INT)))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('id', $qb->createNamedParameter($trackIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$qb->executeStatement();

		foreach ($trackIds as $index => $trackId) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set('sort_order', $qb->createNamedParameter((int)$index + 1, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($trackId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
			$qb->executeStatement();
		}
	}

	public function deleteAllByUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
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
