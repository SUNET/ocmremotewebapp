<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<WebappShare>
 */
class WebappShareMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ocmremotewebapp_shares', WebappShare::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function findByToken(string $token, string $uid): WebappShare {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('local_uid', $qb->createNamedParameter($uid, IQueryBuilder::PARAM_STR)));
		return $this->findEntity($qb);
	}

	/**
	 * @return WebappShare[]
	 */
	public function findAllByUid(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('local_uid', $qb->createNamedParameter($uid, IQueryBuilder::PARAM_STR)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Uid-scoped delete used by the REST API (so a user can only remove their
	 * own rows). The federation-driven SHARE_UNSHARED path in
	 * WebappCloudFederationProvider uses findById + delete instead because
	 * NC is the authority there and the uid is not in the call context.
	 */
	public function deleteById(int $id, string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('local_uid', $qb->createNamedParameter($uid, IQueryBuilder::PARAM_STR)));
		$qb->executeStatement();
	}
}
