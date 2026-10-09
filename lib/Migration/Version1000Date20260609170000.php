<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Rename `media_type` → `app_icon_hint` to track the OCM-API rename.
 * Column added one day earlier; no data preservation needed.
 */
class Version1000Date20260609170000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('ocmremotewebapp_shares')) {
			return null;
		}
		$table = $schema->getTable('ocmremotewebapp_shares');

		if (!$table->hasColumn('app_icon_hint')) {
			$table->addColumn('app_icon_hint', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
		}
		if ($table->hasColumn('media_type')) {
			$table->dropColumn('media_type');
		}

		return $schema;
	}
}
