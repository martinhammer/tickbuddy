<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getTrackId()
 * @method void setTrackId(int $trackId)
 * @method string getToken()
 * @method void setToken(string $token)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getPermission()
 * @method void setPermission(string $permission)
 * @method string getAllowedDays()
 * @method void setAllowedDays(string $allowedDays)
 * @method ?int getLastUsedAt()
 * @method void setLastUsedAt(?int $lastUsedAt)
 * @method ?int getRevokedAt()
 * @method void setRevokedAt(?int $revokedAt)
 */
class PublicLink extends Entity {
	public const PERMISSION_TICK = 'tick';
	public const PERMISSION_VIEW = 'view';
	public const PERMISSIONS = [self::PERMISSION_TICK, self::PERMISSION_VIEW];

	protected string $userId = '';
	protected int $trackId = 0;
	protected string $token = '';
	protected string $name = '';
	// The migration gives these two columns the same defaults: the setter skips a
	// value equal to this one, so the INSERT leaves the column out
	protected string $permission = self::PERMISSION_TICK;
	protected string $allowedDays = 'activeAndPrevious';
	// Unix seconds; null until the first call
	protected ?int $lastUsedAt = null;
	// Unix seconds; null while the link works. Revoked rows stay so that calling
	// one isn't counted as a guess.
	protected ?int $revokedAt = null;

	public function __construct() {
		$this->addType('userId', 'string');
		$this->addType('trackId', 'integer');
		$this->addType('token', 'string');
		$this->addType('name', 'string');
		$this->addType('permission', 'string');
		$this->addType('allowedDays', 'string');
		$this->addType('lastUsedAt', 'integer');
		$this->addType('revokedAt', 'integer');
	}
}
