<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Replace `app_icon` with `media_type` (OCM-API#368). The sender no longer
 * ships an icon URL — it sends the share's media type and the receiver picks
 * a themed icon from it, so a custom icon can't clash with local theming.
 */
class Version1000Date20260608120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('ocmremotewebapp_shares')) {
			return null;
		}
		$table = $schema->getTable('ocmremotewebapp_shares');

		if ($table->hasColumn('app_icon')) {
			$table->dropColumn('app_icon');
		}
		if (!$table->hasColumn('media_type')) {
			// e.g. application/vnd.jupyter. Optional, so nullable.
			$table->addColumn('media_type', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
		}

		return $schema;
	}
}
