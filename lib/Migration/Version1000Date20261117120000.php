<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * OCM-API v08 (cs3org/OCM-API#423) replaces the webapp protocol's
 * `appName` + `mediaTypes` fields with an `apps` array of
 * {name, mediaTypes} entries. Store it as one JSON-encoded TEXT column.
 *
 * Existing rows keep their label: `app_name` + `media_types` are copied
 * into a single synthesized `apps` entry (in postSchemaChange, while the
 * old columns still exist); the old columns are dropped by the next
 * migration.
 */
class Version1000Date20261117120000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('ocmremotewebapp_shares')) {
			return null;
		}
		$table = $schema->getTable('ocmremotewebapp_shares');

		if (!$table->hasColumn('apps')) {
			$table->addColumn('apps', Types::TEXT, [
				'notnull' => false,
			]);
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$select = $this->db->getQueryBuilder();
		$select->select('id', 'app_name', 'media_types')
			->from('ocmremotewebapp_shares')
			->where($select->expr()->isNull('apps'));
		$result = $select->executeQuery();

		$update = $this->db->getQueryBuilder();
		while (is_array($row = $result->fetch())) {
			$appName = $row['app_name'] ?? null;
			if (!is_string($appName) || $appName === '') {
				continue;
			}
			$rowMediaTypes = $row['media_types'] ?? null;
			$mediaTypes = is_string($rowMediaTypes) ? json_decode($rowMediaTypes, true) : null;
			$cleanMediaTypes = is_array($mediaTypes)
				? array_values(array_filter($mediaTypes, fn ($t) => is_string($t) && $t !== ''))
				: [];
			$rowId = $row['id'] ?? null;
			if (!is_numeric($rowId)) {
				continue;
			}
			$update->update('ocmremotewebapp_shares')
				->set('apps', $update->createNamedParameter((string)json_encode([
					['name' => $appName, 'mediaTypes' => $cleanMediaTypes],
				])))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$rowId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		$result->closeCursor();
	}
}
