<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drop `app_name` and `media_types`: OCM-API v08 (cs3org/OCM-API#423)
 * replaced both with the `apps` array, stored in the `apps` column; the
 * previous migration copied their contents there.
 */
class Version1000Date20261117120001 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('ocmremotewebapp_shares')) {
			return null;
		}
		$table = $schema->getTable('ocmremotewebapp_shares');

		if ($table->hasColumn('app_name')) {
			$table->dropColumn('app_name');
		}
		if ($table->hasColumn('media_types')) {
			$table->dropColumn('media_types');
		}

		return $schema;
	}
}
