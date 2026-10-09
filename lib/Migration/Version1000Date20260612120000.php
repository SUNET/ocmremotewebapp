<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add `remote_provider_id`: the wire `providerId`, i.e. the share's id at
 * the sending server. SHARE_UNSHARED notifications identify the share by
 * it, so it is the lookup key for sender-driven revocation. Empty for
 * rows created before this migration (those never matched anyway, since
 * the old code wrongly compared the wire providerId to the local row id).
 */
class Version1000Date20260612120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('ocmremotewebapp_shares')) {
			return null;
		}
		$table = $schema->getTable('ocmremotewebapp_shares');

		if (!$table->hasColumn('remote_provider_id')) {
			$table->addColumn('remote_provider_id', Types::STRING, [
				'notnull' => false,
				'default' => '',
				'length' => 255,
			]);
		}

		return $schema;
	}
}
