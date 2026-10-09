<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Widen `permissions` from varchar(16) to varchar(256). The column holds
 * the JSON-encoded OCM permissions array (read/write/share, per
 * OCM-API#368) — not a single enum value as the initial migration's
 * comment assumed. A multi-value grant like ["read","write","share"] is
 * 23 chars and overflowed the 16-char column, so the receiver rejected
 * the share with SQLSTATE 22001 (-> 400). 256 leaves generous headroom.
 */
class Version1000Date20260607130000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('ocmremotewebapp_shares')) {
			return null;
		}
		$table = $schema->getTable('ocmremotewebapp_shares');
		if (!$table->hasColumn('permissions')) {
			return null;
		}
		$column = $table->getColumn('permissions');
		if ($column->getLength() >= 256) {
			return null;
		}
		$column->setLength(256);

		return $schema;
	}
}
