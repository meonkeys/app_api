<?php

declare(strict_types=1);

/**
 *
 * Nextcloud - App Ecosystem V2
 *
 * @copyright Copyright (c) 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @copyright Copyright (c) 2023 Alexander Piskun <bigcat88@icloud.com>
 *
 * @author 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\AppEcosystemV2\Service;

use OCA\AppEcosystemV2\AppInfo\Application;
use Psr\Log\LoggerInterface;

use OCP\IConfig;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IClient;

use OCP\AppFramework\Db\Entity;
use OCA\AppEcosystemV2\Db\ExApp;
use OCA\AppEcosystemV2\Db\ExAppMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

class AppEcosystemV2Service {
	/** @var IConfig */
	private $config;

	/** @var LoggerInterface */
	private $logger;

	/** @var IClient */
	private $client;

	/** @var ExAppMapper */
	private $exAppMapper;

	/** @var IL10N */
	private $l10n;

	/** @var IAppManager */
	private $appManager;

	public function __construct(
		IConfig $config,
		LoggerInterface $logger,
		IClientService $clientService,
		ExAppMapper $exAppMapper,
		IL10N $l10n,
		IAppManager $appManager,
	) {
		$this->config = $config;
		$this->logger = $logger;
		$this->client = $clientService->newClient();
		$this->exAppMapper = $exAppMapper;
		$this->l10n = $l10n;
		$this->appManager = $appManager;
	}

	public function getExApp(string $exAppId): ?Entity {
		try {
			return $this->exAppMapper->findByAppId($exAppId);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function detectDefaultExApp() {
		// TODO: Check default ex app host and port connection and register it if not exists yet
		$protocol = 'https';
		$host = 'localhost';
		$port = '8063';
		$exAppUrl = $protocol . '://' . $host . ':' . $port;
		$result = $this->checkExAppConnection($exAppUrl);
		if ($result) {
			$this->registerExApp($result['appid'] ?? '', [
				'appid' => $result['appid'] ?? '',
				'version' => $result['version'] ?? '',
				'name' => 'Default Ex App',
				'config' => [
					'protocol' => $protocol,
					'host' => $host,
					'port' => $port,
				],
				'secret' => '',
				'status' => 'active',
				'created_time' => time(),
				'last_response_time' => time(),
			]);
		}
		return $result;
	}

	public function checkExAppConnection(
		string $exAppUrl,
		string $exAppToken = '', // TODO: temporal app token to receive valid response from ex app
		string $exAppSecret = '' // TODO: one-time created secret
	) {
		$response = $this->client->post($exAppUrl, [
			'headers' => [
				'Authorization' => 'Bearer ' . $exAppToken,
				'X-App-Secret' => $exAppSecret
			]
		]);
		if ($response->getStatusCode() === 200) {
			return true;
		}
		return false;
	}

	public function registerExApp(string $appId, array $appData) {
		try {
			$exApp = $this->exAppMapper->findByAppId($appId);
			if ($exApp !== null) {
				$exApp->setVersion($appData['version']);
				$exApp->setName($appData['name']);
				$exApp->setConfig($appData['config']);
				$exApp->setSecret($appData['secret']); // TODO: Implement secret generation and verification
				$exApp->setStatus($appData['status']);
				$exApp->setLastResponseTime(time());
				try {
					$exApp = $this->exAppMapper->update($exApp);
				} catch (\Exception $e) {
					$this->logger->error('Error while updating ex app: ' . $e->getMessage());
					return false;
				}
			}
		} catch (DoesNotExistException) {
			$exApp = new ExApp();
			$exApp->setAppId($appId);
			$exApp->setVersion($appData['version']);
			$exApp->setName($appData['name']);
			$exApp->setConfig($appData['config']);
			$exApp->setSecret($appData['secret']); // TODO: Implement secret generation and verification
			$exApp->setStatus($appData['status']);
			$exApp->setCreatedTime(time());
			$exApp->setLastResponseTime(time());
			try {
				$exApp = $this->exAppMapper->insert($exApp);
			} catch (\Exception $e) {
				$this->logger->error('Error while registering ex app: ' . $e->getMessage());
				return false;
			}
		}
	}

	/**
	 * Unregister ex app
	 *
	 * @param string $appId
	 *
	 * @return Entity|null
	 */
	public function unregisterExApp(string $appId): ?Entity {
		try {
			/** @var ExApp $exApp */
			$exApp = $this->exAppMapper->findByAppId($appId);
			if ($this->exAppMapper->deleteExApp($exApp) !== 1) {
				$this->logger->error('Error while unregistering ex app: ' . $appId);
				return false;
			}
			return $exApp;
		} catch (DoesNotExistException $e) {
			$this->logger->error('Error while unregistering ex app: ' . $e->getMessage());
			return null;
		}
	}

	public function enableExApp(ExApp $exApp) {
		// TODO
	}

	public function disableExApp(ExApp $exApp) {
		// TODO
	}

	/**
	 * Send status check request to ex app (after verify app registration)
	 *
	 * @param string $appId
	 *
	 * @return array
	 */
	public function getAppStatus(string $appId): array {
		// TODO
		return [];
	}

	public function requestToExApp(ExApp $exApp, string $route, string $method = 'POST', array $params = []) {
		try {
 			$exAppConfig = json_decode($exApp->getConfig(), true);
			$url = $exAppConfig['protocol'] . '://' . $exAppConfig['host'] . ':' . $exAppConfig['port'] . $route;
			$options = [
				'headers' => [
					// TODO: Add authorization headers
					'NC-VERSION' => $this->config->getSystemValue('version'),
					'APP-ECOSYSTEM-VERSION' => $this->appManager->getAppVersion(Application::APP_ID, false),
				],
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					// manage array parameters
					$paramsContent = '';
					foreach ($params as $key => $value) {
						if (is_array($value)) {
							foreach ($value as $oneArrayValue) {
								$paramsContent .= $key . '[]=' . urlencode($oneArrayValue) . '&';
							}
							unset($params[$key]);
						}
					}
					$paramsContent .= http_build_query($params);

					$url .= '?' . $paramsContent;
				} else {
					$options['json'] = $params;
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			} else {
				return ['error' => $this->l10n->t('Bad HTTP method')];
			}
			return $response;
		} catch (\Exception $e) {
			return ['error' => $e->getMessage()];
		}
	}
}
