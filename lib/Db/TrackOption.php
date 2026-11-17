<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One option of a Choice track. Its place (1 to n) is the value a day stores,
 * fixed once the track is created; only the label can change.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getTrackId()
 * @method void setTrackId(int $trackId)
 * @method int getPlace()
 * @method void setPlace(int $place)
 * @method string getLabel()
 * @method void setLabel(string $label)
 */
class TrackOption extends Entity {
	protected string $userId = '';
	protected int $trackId = 0;
	protected int $place = 0;
	protected string $label = '';

	public function __construct() {
		$this->addType('userId', 'string');
		$this->addType('trackId', 'integer');
		$this->addType('place', 'integer');
		$this->addType('label', 'string');
	}
}
