<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1001Date20260418000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('tickbuddy_tracks');
		if (!$table->hasColumn('private')) {
			$table->addColumn('private', Types::BOOLEAN, [
				'notnull' => true,
				'default' => false,
			]);
		}

		return $schema;
	}
}
