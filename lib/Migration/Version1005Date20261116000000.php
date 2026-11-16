<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `tickbuddy_public_links`: web addresses that tick or read one track
 * without signing in. A revoked link keeps its row (`revoked_at` set), so a
 * call to it isn't counted as a guess by the brute-force protection.
 */
class Version1005Date20261116000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('tickbuddy_public_links')) {
			$table = $schema->createTable('tickbuddy_public_links');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			// The owner: a call ticks or reads as this user
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('track_id', Types::BIGINT, [
				'notnull' => true,
			]);
			// The credential, kept as it is so the link can be copied again
			$table->addColumn('token', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('name', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			// 'tick' or 'view'. The defaults match the entity's starting values:
			// its setter skips a value equal to the one it holds, so the INSERT
			// leaves the column out (as tracks' type has 'boolean')
			$table->addColumn('permission', Types::STRING, [
				'notnull' => true,
				'length' => 16,
				'default' => 'tick',
			]);
			// One of Editable days' values (activeDay, activeAndPrevious, oneWeek, allDays)
			$table->addColumn('allowed_days', Types::STRING, [
				'notnull' => true,
				'length' => 32,
				'default' => 'activeAndPrevious',
			]);
			// Unix seconds; null until the first call
			$table->addColumn('last_used_at', Types::BIGINT, [
				'notnull' => false,
			]);
			// Unix seconds; null while the link works
			$table->addColumn('revoked_at', Types::BIGINT, [
				'notnull' => false,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['token'], 'tickbuddy_plinks_token');
			$table->addIndex(['user_id', 'track_id'], 'tickbuddy_plinks_uid_tid');
		}

		return $schema;
	}
}
