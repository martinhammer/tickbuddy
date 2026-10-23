<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `tickbuddy_idempotency`: the `Idempotency-Key`s clients sent with writes
 * that aren't safe to repeat, and the answer each got, so a retry is answered
 * as the first request was instead of being done again.
 */
class Version1004Date20261023000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('tickbuddy_idempotency')) {
			$table = $schema->createTable('tickbuddy_idempotency');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			// The key as a lower-case UUID
			$table->addColumn('idem_key', Types::STRING, [
				'notnull' => true,
				'length' => 36,
			]);
			// SHA-256 hex of the endpoint and its parameters
			$table->addColumn('fingerprint', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			// The answer's status; null while the first request is still running
			$table->addColumn('status', Types::INTEGER, [
				'notnull' => false,
			]);
			// The answer's data as JSON
			$table->addColumn('response', Types::TEXT, [
				'notnull' => false,
			]);
			// Unix seconds
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id', 'idem_key'], 'tickbuddy_idem_uid_key');
			$table->addIndex(['created_at'], 'tickbuddy_idem_created');
		}

		return $schema;
	}
}
