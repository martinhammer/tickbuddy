<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Number and Choice tracks: a Number's settings on `tickbuddy_tracks`, and a
 * Choice's options in `tickbuddy_track_options`. Ticks keep their INTEGER
 * `value`: a Number is stored there in thousandths, a Choice as its place.
 *
 * The new track columns are nullable (null on every other type), so they
 * need no default.
 */
class Version1006Date20261117000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$tracks = $schema->getTable('tickbuddy_tracks');
		// A Number's unit; null when it has none (Oracle stores '' as NULL anyway)
		if (!$tracks->hasColumn('unit')) {
			$tracks->addColumn('unit', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
		}
		// A Number's decimal places, 0 to 3
		if (!$tracks->hasColumn('decimal_places')) {
			$tracks->addColumn('decimal_places', Types::SMALLINT, [
				'notnull' => false,
			]);
		}
		// 0/1 as INTEGER, as `private`: never BOOLEAN (see CLAUDE.md)
		if (!$tracks->hasColumn('allow_negative')) {
			$tracks->addColumn('allow_negative', Types::INTEGER, [
				'notnull' => false,
			]);
		}
		if (!$tracks->hasColumn('ordered_scale')) {
			$tracks->addColumn('ordered_scale', Types::INTEGER, [
				'notnull' => false,
			]);
		}

		if (!$schema->hasTable('tickbuddy_track_options')) {
			$table = $schema->createTable('tickbuddy_track_options');
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
			]);
			// The option's value: 1 to the number of options, fixed once created
			$table->addColumn('place', Types::SMALLINT, [
				'notnull' => true,
			]);
			$table->addColumn('label', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['track_id', 'place'], 'tickbuddy_topts_tid_place');
			$table->addIndex(['user_id'], 'tickbuddy_topts_uid');
		}

		return $schema;
	}
}
