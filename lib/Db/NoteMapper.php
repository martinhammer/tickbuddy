<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Note>
 */
class NoteMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'tickbuddy_notes', Note::class);
	}

	/**
	 * @return Note[]
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
	 * The user's notes from `$from` to `$to`, newest first.
	 *
	 * @param ?int $trackId One track's only, or null for every track's; served by
	 *                      the (user_id, track_id, date) unique index either way
	 * @return Note[]
	 */
	public function findByUserAndDateRange(string $userId, string $from, string $to, ?int $trackId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->gte('date', $qb->createNamedParameter($from)))
			->andWhere($qb->expr()->lte('date', $qb->createNamedParameter($to)));
		if ($trackId !== null) {
			$qb->andWhere($qb->expr()->eq('track_id', $qb->createNamedParameter($trackId, IQueryBuilder::PARAM_INT)));
		}
		$qb->orderBy('date', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * First and last note date per track for a user.
	 *
	 * Aggregates in the database rather than returning rows: the result is
	 * bounded by the track limit, while the note history it summarises is not.
	 * Tracks without any notes are absent from the result, matching the sparse
	 * storage convention. Served index-only by the (user_id, track_id, date)
	 * unique index. Independent of ticks: a note can sit on a day without one.
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
	public function findByUserTrackDate(string $userId, int $trackId, string $date): Note {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('track_id', $qb->createNamedParameter($trackId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('date', $qb->createNamedParameter($date)));
		return $this->findEntity($qb);
	}

	/**
	 * Replace a note's text only if it still reads `$oldText`, so a writer that
	 * read the note can't overwrite another's change made since.
	 *
	 * The text column is compared as a string (PARAM_STR), which Nextcloud
	 * turns into `to_char(text)` on Oracle, where a CLOB can't be compared
	 * directly; notes are far below to_char's 4000-byte cap.
	 *
	 * @return int Rows changed: 0 when someone else wrote first
	 */
	public function replaceText(int $id, string $oldText, string $newText): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('text', $qb->createNamedParameter($newText))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('text', $qb->createNamedParameter($oldText), IQueryBuilder::PARAM_STR));
		return $qb->executeStatement();
	}

	public function deleteByUserTrackDate(string $userId, int $trackId, string $date): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('track_id', $qb->createNamedParameter($trackId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('date', $qb->createNamedParameter($date)));
		$qb->executeStatement();
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
