<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getType()
 * @method void setType(string $type)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method int getPrivate()
 * @method void setPrivate(int $private)
 */
class Track extends Entity {
	protected string $userId = '';
	protected string $name = '';
	protected string $type = 'boolean';
	protected int $sortOrder = 0;
	// Stored as an integer (0/1), not a boolean: the physical column is INTEGER
	// on all databases, and binding a boolean parameter renders as 't'/'f' on
	// Postgres, which the integer column rejects. Bool lives only at the API boundary.
	protected int $private = 0;

	public function __construct() {
		$this->addType('userId', 'string');
		$this->addType('name', 'string');
		$this->addType('type', 'string');
		$this->addType('sortOrder', 'integer');
		$this->addType('private', 'integer');
	}
}
