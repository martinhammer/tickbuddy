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
 */
class Track extends Entity {
	protected string $userId = '';
	protected string $name = '';
	protected string $type = 'boolean';
	protected int $sortOrder = 0;

	public function __construct() {
		$this->addType('userId', 'string');
		$this->addType('name', 'string');
		$this->addType('type', 'string');
		$this->addType('sortOrder', 'integer');
	}
}
