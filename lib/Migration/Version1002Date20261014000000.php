<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `archived_at` to tracks: null means active, a date means archived on that day.
 */
class Version1002Date20261014000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('tickbuddy_tracks');
		if (!$table->hasColumn('archived_at')) {
			$table->addColumn('archived_at', Types::DATE, [
				'notnull' => false,
				'default' => null,
			]);
		}

		return $schema;
	}
}
