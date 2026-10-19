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
	 * @return Note[]
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
