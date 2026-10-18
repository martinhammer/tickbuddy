<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getTrackId()
 * @method void setTrackId(int $trackId)
 * @method string getDate()
 * @method void setDate(string $date)
 * @method string getText()
 * @method void setText(string $text)
 */
class Note extends Entity {
	protected string $userId = '';
	protected int $trackId = 0;
	protected string $date = '';
	protected string $text = '';

	public function __construct() {
		$this->addType('userId', 'string');
		$this->addType('trackId', 'integer');
		$this->addType('date', 'string');
		$this->addType('text', 'string');
	}
}
