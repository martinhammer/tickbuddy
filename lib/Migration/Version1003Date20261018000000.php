<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `tickbuddy_notes`: one short text per track per day, kept apart from
 * ticks so unticking (which deletes the tick row) never deletes a note.
 */
class Version1003Date20261018000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('tickbuddy_notes')) {
			$table = $schema->createTable('tickbuddy_notes');
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
			// TEXT, so the length limit (NoteService::MAX_LENGTH) can change without a migration
			$table->addColumn('text', Types::TEXT, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id', 'track_id', 'date'], 'tickbuddy_notes_uid_tid_d');
			$table->addIndex(['user_id', 'date'], 'tickbuddy_notes_uid_date');
		}

		return $schema;
	}
}
