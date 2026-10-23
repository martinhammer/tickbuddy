<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getIdemKey()
 * @method void setIdemKey(string $idemKey)
 * @method string getFingerprint()
 * @method void setFingerprint(string $fingerprint)
 * @method ?int getStatus()
 * @method void setStatus(?int $status)
 * @method ?string getResponse()
 * @method void setResponse(?string $response)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class IdempotencyKey extends Entity {
	protected string $userId = '';
	protected string $idemKey = '';
	protected string $fingerprint = '';
	// Null until the first request's answer is stored
	protected ?int $status = null;
	protected ?string $response = null;
	protected int $createdAt = 0;

	public function __construct() {
		$this->addType('userId', 'string');
		$this->addType('idemKey', 'string');
		$this->addType('fingerprint', 'string');
		$this->addType('status', 'integer');
		$this->addType('response', 'string');
		$this->addType('createdAt', 'integer');
	}
}
