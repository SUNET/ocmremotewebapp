<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds file_share_id: the id of the NC federated external share (the
 * Files mount) created from the webapp share's webdav protocol entry, so
 * accepting/declining the webapp share in the UI can accept/decline the
 * paired Files mount too. Nullable — a webapp share without a usable
 * webdav entry simply has no associated mount.
 */
class Version1000Date20260604120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('ocmremotewebapp_shares')) {
			return null;
		}
		$table = $schema->getTable('ocmremotewebapp_shares');
		if ($table->hasColumn('file_share_id')) {
			return null;
		}
		$table->addColumn('file_share_id', Types::STRING, [
			'notnull' => false,
			'length' => 64,
		]);

		return $schema;
	}
}
