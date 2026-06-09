<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add `media_types`: JSON-encoded MIME types the sender's webapp
 * can handle. Optional, so nullable; TEXT with no default.
 */
class Version1000Date20260609180000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('ocmremotewebapp_shares')) {
			return null;
		}
		$table = $schema->getTable('ocmremotewebapp_shares');

		if (!$table->hasColumn('media_types')) {
			$table->addColumn('media_types', Types::TEXT, [
				'notnull' => false,
			]);
		}

		return $schema;
	}
}
