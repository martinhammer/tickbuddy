<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<IdempotencyKey>
 */
class IdempotencyKeyMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'tickbuddy_idempotency', IdempotencyKey::class);
	}

	/**
	 * Claim a key for a request that is about to run. The unique index on
	 * (user_id, idem_key) makes this the lock.
	 *
	 * @throws DbException A unique violation when the key is already claimed
	 */
	public function claim(string $userId, string $key, string $fingerprint, int $now): IdempotencyKey {
		$row = new IdempotencyKey();
		$row->setUserId($userId);
		$row->setIdemKey($key);
		$row->setFingerprint($fingerprint);
		$row->setCreatedAt($now);
		return $this->insert($row);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(string $userId, string $key): IdempotencyKey {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('idem_key', $qb->createNamedParameter($key)));
		return $this->findEntity($qb);
	}

	public function storeAnswer(int $id, int $status, string $response): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter($status, IQueryBuilder::PARAM_INT))
			->set('response', $qb->createNamedParameter($response))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Delete a claim whose request died: only if it is still unanswered and
	 * was made before `$olderThan`, so a repeat racing another can't delete
	 * the claim the other just made.
	 */
	public function releaseStale(int $id, int $olderThan): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('status'))
			->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($olderThan, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteOlderThan(int $olderThan): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($olderThan, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
