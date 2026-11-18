<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<TrackOption>
 */
class TrackOptionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'tickbuddy_track_options', TrackOption::class);
	}

	/**
	 * One track's options, in place order.
	 *
	 * @return TrackOption[]
	 */
	public function findByTrack(int $trackId, string $userId): array {
		return $this->findByTracks([$trackId], $userId)[$trackId] ?? [];
	}

	/**
	 * Several tracks' options in one query, as track id => options in place
	 * order. Tracks without options are absent.
	 *
	 * @param int[] $trackIds
	 * @return array<int, list<TrackOption>>
	 */
	public function findByTracks(array $trackIds, string $userId): array {
		if ($trackIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('track_id', $qb->createNamedParameter(array_values($trackIds), IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('track_id', 'ASC')
			->addOrderBy('place', 'ASC');
		$byTrack = [];
		foreach ($this->findEntities($qb) as $option) {
			$byTrack[$option->getTrackId()][] = $option;
		}
		return $byTrack;
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findOne(int $trackId, string $userId, int $place): TrackOption {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('track_id', $qb->createNamedParameter($trackId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('place', $qb->createNamedParameter($place, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
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
