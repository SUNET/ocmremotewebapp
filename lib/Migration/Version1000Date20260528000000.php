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
		// Enum view/read/write/share (post-#367 `permissions`).
		$table->addColumn('permissions', Types::STRING, [
			'notnull' => true,
			'length' => 16,
			'default' => 'view',
		]);
		// JSON-encoded `targets` array (subset of blank/iframe/popup).
		// TEXT keeps us forward-compat if the spec adds more values; no
		// default per the Oracle-CLOB rule. Entity initialises to '[]'.
		$table->addColumn('targets', Types::TEXT, [
			'notnull' => true,
		]);
		// Long-lived OAuth2 authorization code — the value the wire
		// calls `sharedSecret`. Exchanged at the sender's tokenEndPoint
		// for a short-lived JWT access_token (see access_token column).
		// TEXT, no default per Oracle-CLOB rule.
		$table->addColumn('refresh_token', Types::TEXT, [
			'notnull' => true,
		]);
		// Cached JWT minted by TokenExchanger. NULL means "no cached
		// token, exchange before next launch". Populated lazily on first
		// launch and refreshed on expiry.
		$table->addColumn('access_token', Types::TEXT, [
			'notnull' => false,
		]);
		// JWT `exp` claim (unix seconds). NULL when access_token is NULL.
		$table->addColumn('access_token_expires', Types::BIGINT, [
			'notnull' => false,
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
		$table->addColumn('app_name', Types::STRING, [
			'notnull' => true,
			'length' => 255,
			'default' => '',
		]);
		// TEXT for data: URIs (icons can be kilobytes); no default per
		// Oracle-CLOB rule.
		$table->addColumn('app_icon', Types::TEXT, [
			'notnull' => true,
		]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['local_uid'], 'ocmrw_shares_uid_idx');
		$table->addUniqueIndex(['local_uid', 'token'], 'ocmrw_shares_uid_tok_uniq');

		return $schema;
	}
}
