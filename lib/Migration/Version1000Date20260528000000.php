<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20260528000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('ocmremotewebapp_shares')) {
			return null;
		}

		$table = $schema->createTable('ocmremotewebapp_shares');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
		]);
		$table->addColumn('local_uid', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('token', Types::STRING, [
			'notnull' => true,
			'length' => 255,
		]);
		$table->addColumn('remote_owner', Types::STRING, [
			'notnull' => true,
			'length' => 255,
			'default' => '',
		]);
		$table->addColumn('remote_shared_by', Types::STRING, [
			'notnull' => true,
			'length' => 255,
			'default' => '',
		]);
		$table->addColumn('resource_name', Types::STRING, [
			'notnull' => true,
			'length' => 512,
			'default' => '',
		]);
		$table->addColumn('uri', Types::STRING, [
			'notnull' => true,
			'length' => 2048,
		]);
		// v1 senders supply `viewMode`, v2 senders `permissions` (PR #367);
		// the wire value is stored verbatim — enum is the union of both:
		// `view`/`read`/`write`/`share`.
		$table->addColumn('permissions', Types::STRING, [
			'notnull' => true,
			'length' => 16,
			'default' => 'view',
		]);
		// JSON-encoded v2 `targets` array (subset of blank/iframe/popup).
		// Empty string for v1 rows that don't carry the field. TEXT keeps
		// us forward-compat if the spec adds more values; no default per
		// the Oracle-CLOB rule.
		$table->addColumn('targets', Types::TEXT, [
			'notnull' => true,
		]);
		// TEXT, no default — Oracle doesn't allow defaults on CLOB. The
		// entity initialises the field to '' so INSERTs always supply a
		// value.
		$table->addColumn('shared_secret', Types::TEXT, [
			'notnull' => true,
		]);
		$table->addColumn('state', Types::STRING, [
			'notnull' => true,
			'length' => 16,
			'default' => 'pending',
		]);
		$table->addColumn('created_at', Types::BIGINT, [
			'notnull' => true,
			'default' => 0,
		]);
		$table->addColumn('protocol_version', Types::STRING, [
			'notnull' => true,
			'length' => 4,
			'default' => 'v1',
		]);
		$table->addColumn('app_name', Types::STRING, [
			'notnull' => true,
			'length' => 255,
			'default' => '',
		]);
		// TEXT for data: URIs (icons can be kilobytes); same no-default
		// rule as shared_secret.
		$table->addColumn('app_icon', Types::TEXT, [
			'notnull' => true,
		]);
		$table->addColumn('token_endpoint', Types::STRING, [
			'notnull' => true,
			'length' => 512,
			'default' => '',
		]);
		// Cache of the JWT minted via §4.10.3 token exchange. TEXT (no
		// default per Oracle-CLOB rule). Populated lazily on launch
		// under PR #365; unused for v1 / v2-without-#365 shares.
		$table->addColumn('access_token', Types::TEXT, [
			'notnull' => true,
		]);
		// JWT `exp` claim (unix seconds). 0 means "no cached token,
		// must exchange before next launch".
		$table->addColumn('access_token_expires_at', Types::BIGINT, [
			'notnull' => true,
			'default' => 0,
		]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['local_uid'], 'ocmrw_shares_uid_idx');
		$table->addUniqueIndex(['local_uid', 'token'], 'ocmrw_shares_uid_tok_uniq');

		return $schema;
	}
}
