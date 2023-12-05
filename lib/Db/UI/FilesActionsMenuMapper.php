<?php

declare(strict_types=1);

namespace OCA\AppAPI\Db\UI;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<FilesActionsMenu>
 */
class FilesActionsMenuMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ex_files_actions_menu');
	}

	/**
	 * @throws Exception
	 */
	public function findAllEnabled(): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select(
			'ex_files_actions_menu.appid',
			'ex_files_actions_menu.name',
			'ex_files_actions_menu.display_name',
			'ex_files_actions_menu.mime',
			'ex_files_actions_menu.permissions',
			'ex_files_actions_menu.order',
			'ex_files_actions_menu.icon',
			'ex_files_actions_menu.action_handler',
		)
			->from($this->tableName, 'ex_files_actions_menu')
			->innerJoin('ex_files_actions_menu', 'ex_apps', 'exa', 'exa.appid = ex_files_actions_menu.appid')
			->where(
				$qb->expr()->eq('exa.enabled', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			)
			->executeQuery();
		return $result->fetchAll();
	}

	/**
	 * @param string $appId
	 * @param string $name
	 *
	 * @return FilesActionsMenu
	 * @throws DoesNotExistException if not found
	 * @throws Exception
	 *
	 * @throws MultipleObjectsReturnedException if more than one result
	 */
	public function findByAppidName(string $appId, string $name): FilesActionsMenu {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('name', $qb->createNamedParameter($name, IQueryBuilder::PARAM_STR)),
			);
		return $this->findEntity($qb);
	}

	/**
	 * @param string $name
	 *
	 *
	 * @return FilesActionsMenu
	 * @throws DoesNotExistException|Exception if not found
	 * @throws Exception
	 *
	 * @throws MultipleObjectsReturnedException if more than one result
	 */
	public function findByName(string $name): FilesActionsMenu {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('name', $qb->createNamedParameter($name, IQueryBuilder::PARAM_STR))
			);
		return $this->findEntity($qb);
	}

	/**
	 * @throws Exception
	 */
	public function removeAllByAppId(string $appId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->tableName)
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR))
			);
		return $qb->executeStatement();
	}
}
