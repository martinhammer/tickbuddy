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
 * @method ?string getArchivedAt()
 * @method void setArchivedAt(?string $archivedAt)
 * @method ?string getUnit()
 * @method void setUnit(?string $unit)
 * @method ?int getDecimalPlaces()
 * @method void setDecimalPlaces(?int $decimalPlaces)
 * @method ?int getAllowNegative()
 * @method void setAllowNegative(?int $allowNegative)
 * @method ?int getOrderedScale()
 * @method void setOrderedScale(?int $orderedScale)
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
	// The archive date as YYYY-MM-DD (a DATE column, mapped as a string like
	// Tick's `date`); null while the track is active.
	protected ?string $archivedAt = null;
	// A Number's settings, null on every other type. The unit is null when it
	// has none (Oracle stores '' as NULL); allowNegative is 0/1 as `private` is.
	protected ?string $unit = null;
	protected ?int $decimalPlaces = null;
	protected ?int $allowNegative = null;
	// A Choice's setting (0/1), null on every other type
	protected ?int $orderedScale = null;

	public function __construct() {
		$this->addType('userId', 'string');
		$this->addType('name', 'string');
		$this->addType('type', 'string');
		$this->addType('sortOrder', 'integer');
		$this->addType('private', 'integer');
		$this->addType('archivedAt', 'string');
		$this->addType('unit', 'string');
		$this->addType('decimalPlaces', 'integer');
		$this->addType('allowNegative', 'integer');
		$this->addType('orderedScale', 'integer');
	}
}
