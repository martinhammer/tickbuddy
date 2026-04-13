<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20260411000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('tickbuddy_tracks')) {
			$table = $schema->createTable('tickbuddy_tracks');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('name', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('type', Types::STRING, [
				'notnull' => true,
				'length' => 16,
				'default' => 'boolean',
			]);
			$table->addColumn('sort_order', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'tickbuddy_tracks_uid');
			$table->addUniqueIndex(['user_id', 'sort_order'], 'tickbuddy_tracks_uid_sort');
		}

		if (!$schema->hasTable('tickbuddy_ticks')) {
			$table = $schema->createTable('tickbuddy_ticks');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('track_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('date', Types::DATE, [
				'notnull' => true,
			]);
			$table->addColumn('value', Types::INTEGER, [
				'notnull' => true,
				'default' => 1,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id', 'track_id', 'date'], 'tickbuddy_ticks_uid_tid_d');
			$table->addIndex(['user_id', 'date'], 'tickbuddy_ticks_uid_date');
		}

		return $schema;
	}
}
