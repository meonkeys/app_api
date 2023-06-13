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
use OCA\AppEcosystemV2\Db\ExAppScope;
use OCA\AppEcosystemV2\Db\ExAppScopeMapper;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\Http\Client\IResponse;
use OCP\IUser;
use Psr\Log\LoggerInterface;

use OCP\Http\Client\IClientService;
use OCP\Http\Client\IClient;

use OCP\AppFramework\Db\Entity;
use OCA\AppEcosystemV2\Db\ExApp;
use OCA\AppEcosystemV2\Db\ExAppApiScope;
use OCA\AppEcosystemV2\Db\ExAppApiScopeMapper;
use OCA\AppEcosystemV2\Db\ExAppMapper;
use OCA\AppEcosystemV2\Db\ExAppUser;
use OCA\AppEcosystemV2\Db\ExAppUserMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;

class AppEcosystemV2Service {
	public const INIT_API_SCOPE = 1;
	public const SYSTEM_API_SCOPE = 2;
	const MAX_SIGN_TIME_DIFF = 60 * 5; // 5 min
	private LoggerInterface $logger;
	private IClient $client;
	private ExAppMapper $exAppMapper;
	private IL10N $l10n;
	private IAppManager $appManager;
	private ExAppUserMapper $exAppUserMapper;
	private ISecureRandom $random;
	private IUserSession $userSession;
	private IUserManager $userManager;
	private ExAppApiScopeMapper $exAppApiScopeMapper;
	private ExAppScopeMapper $exAppScopeMapper;

	public function __construct(
		LoggerInterface $logger,
		IClientService $clientService,
		ExAppMapper $exAppMapper,
		IL10N $l10n,
		IAppManager $appManager,
		ExAppUserMapper $exAppUserMapper,
		ExAppApiScopeMapper $exAppApiScopeMapper,
		ExAppScopeMapper $exAppScopeMapper,
		ISecureRandom $random,
		IUserSession $userSession,
		IUserManager $userManager,
	) {
		$this->logger = $logger;
		$this->client = $clientService->newClient();
		$this->exAppMapper = $exAppMapper;
		$this->l10n = $l10n;
		$this->appManager = $appManager;
		$this->exAppUserMapper = $exAppUserMapper;
		$this->random = $random;
		$this->userSession = $userSession;
		$this->userManager = $userManager;
		$this->exAppApiScopeMapper = $exAppApiScopeMapper;
		$this->exAppScopeMapper = $exAppScopeMapper;
	}

	public function getExApp(string $exAppId): ?Entity {
		try {
			return $this->exAppMapper->findByAppId($exAppId);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return null;
		}
	}

	/**
	 * Register exApp
	 *
	 * @param string $appId
	 * @param array $appData [version, name, config]
	 *
	 * @return ExApp|null
	 */
	public function registerExApp(string $appId, array $appData): ?ExApp {
		try {
			$exApp = $this->exAppMapper->findByAppId($appId);
			$exApp->setVersion($appData['version']);
			$exApp->setName($appData['name']);
			$exApp->setConfig($appData['config']);
			$secret = $this->random->generate(128); // Temporal random secret
			$exApp->setSecret($secret);
			$exApp->setStatus(json_encode(['active' => true]));
			$exApp->setLastResponseTime(time());
			try {
				return $this->exAppMapper->update($exApp);
			} catch (\Exception $e) {
				$this->logger->error('Error while updating ex app: ' . $e->getMessage());
				return null;
			}
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			$exApp = new ExApp([
				'appid' => $appId,
				'version' => $appData['version'],
				'name' => $appData['name'],
				'config' => $appData['config'],
				'secret' =>  $this->random->generate(128),
				'status' => json_encode(['active' => true]),
				'created_time' => time(),
				'last_response_time' => time(),
			]);
			try {
				return $this->exAppMapper->insert($exApp);
			} catch (Exception $e) {
				$this->logger->error('Error while registering ex app: ' . $e->getMessage());
				return null;
			}
		}
	}

	/**
	 * Unregister ex app
	 *
	 * @param string $appId
	 *
	 * @return ExApp|null
	 */
	public function unregisterExApp(string $appId): ?ExApp {
		try {
			$exApp = $this->exAppMapper->findByAppId($appId);
			if ($this->exAppMapper->deleteExApp($exApp) !== 1) {
				$this->logger->error('Error while unregistering ex app: ' . $appId);
				return null;
			}
			return $exApp;
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception $e) {
			$this->logger->error('Error while unregistering ex app: ' . $e->getMessage());
			return null;
		}
	}

	public function getExAppScopeGroups(ExApp $exApp): array {
		try {
			return $this->exAppScopeMapper->findByAppid($exApp->getAppid());
		} catch (Exception) {
			return [];
		}
	}

	public function setExAppScopeGroup(ExApp $exApp, int $scopeGroup): ?ExAppScope {
		$appId = $exApp->getAppid();
		try {
			return $this->exAppScopeMapper->findByAppidScope($appId, $scopeGroup);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception $e) {
			$exAppScope = new ExAppScope([
				'appid' => $appId,
				'scope_group' => $scopeGroup,
			]);
			try {
				return $this->exAppScopeMapper->insert($exAppScope);
			} catch (\Exception $e) {
				$this->logger->error('Error while setting ex app scope group: ' . $e->getMessage());
				return null;
			}
		}
	}

	public function removeExAppScopeGroup(ExApp $exApp, int $scopeGroup): ?ExAppScope {
		try {
			$exAppScope = $this->exAppScopeMapper->findByAppidScope($exApp->getAppid(), $scopeGroup);
			return $this->exAppScopeMapper->delete($exAppScope);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return null;
		}
	}

	/**
	 * Enable ex app
	 *
	 * @param ExApp $exApp
	 *
	 * @return bool
	 */
	public function enableExApp(ExApp $exApp): bool {
		try {
			if ($this->exAppMapper->updateExAppEnabled($exApp->getAppid(), true) === 1) {
				return true;
			}
		} catch (Exception) {
			return false;
		}
		return false;
	}

	/**
	 * Disable ex app
	 *
	 * @param ExApp $exApp
	 *
	 * @return bool
	 */
	public function disableExApp(ExApp $exApp): bool {
		try {
			if ($this->exAppMapper->updateExAppEnabled($exApp->getAppid(), false) === 1) {
				return true;
			}
		} catch (Exception) {
			return false;
		}
		return false;
	}

	/**
	 * Send status check request to ex app (after verify app registration)
	 *
	 * @param string $appId
	 *
	 * @return array|null
	 */
	public function getAppStatus(string $appId): ?array {
		try {
			// TODO: Send request to ex app, update status and last response time, return status
			$exApp = $this->exAppMapper->findByAppId($appId);
			return json_decode($exApp->getStatus(), true);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return null;
		}
	}

	public function requestToExApp(string $userId, ExApp $exApp, string $route, string $method = 'POST', array $params = []): array|IResponse {
		try {
 			$exAppConfig = json_decode($exApp->getConfig(), true);
			$url = $exAppConfig['protocol'] . '://' . $exAppConfig['host'] . ':' . $exAppConfig['port'] . $route;
			// Check in ex_apps_users
			if (!$this->exAppUserExists($exApp->getAppid(), $userId)) {
				try {
					$this->exAppUserMapper->insert(new ExAppUser([
						'appid' => $exApp->getAppid(),
						'userid' => $userId,
					]));
				} catch (\Exception $e) {
					$this->logger->error('Error while inserting ex app user: ' . $e->getMessage());
					return ['error' => 'Error while inserting ex app user: ' . $e->getMessage()];
				}
			}
			$options = [
				'headers' => [
					'AE-VERSION' => $this->appManager->getAppVersion(Application::APP_ID, false),
					'EX-APP-ID' => $exApp->getAppid(),
					'EX-APP-VERSION' => $exApp->getVersion(),
					'NC-USER-ID' => $userId,
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

			$options['headers']['AE-SIGN-TIME'] = time();
			$signature = $this->generateRequestSignature($method, $options, $exApp->getSecret(), $params);
			$options['headers']['AE-SIGNATURE'] = $signature;

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

	public function generateRequestSignature(string $method, array $options, string $secret, array $params = []): ?string {
		$headers = [];
		if (isset($options['headers']['AE-VERSION'])) {
			$headers['AE-VERSION'] = $options['headers']['AE-VERSION'];
		}
		if (isset($options['headers']['EX-APP-ID'])) {
			$headers['EX-APP-ID'] = $options['headers']['EX-APP-ID'];
		}
		if (isset($options['headers']['EX-APP-VERSION'])) {
			$headers['EX-APP-VERSION'] = $options['headers']['EX-APP-VERSION'];
		}
		if (isset($options['headers']['NC-USER-ID']) && $options['headers']['NC-USER-ID'] !== '') {
			$headers['NC-USER-ID'] = $options['headers']['NC-USER-ID'];
		}
		if (isset($options['headers']['AE-DATA-HASH'])) {
			// TODO: Add data hash calculation
			$headers['AE-DATA-HASH'] = $options['headers']['AE-DATA-HASH'];
		}
		if (isset($options['headers']['AE-SIGN-TIME'])) {
			$headers['AE-SIGN-TIME'] = $options['headers']['AE-SIGN-TIME'];
		}

		if ($method === 'GET') {
			$queryParams = $params;
		} else {
			$queryParams = array_merge($params, $options['json']);
		}
//		$this->sortNestedArrayAssoc($queryParams);
		array_walk_recursive(
			$queryParams,
			function(&$v) {
				if (is_numeric($v)) {
					$v = strval($v);
				}
			}
		);
		$body = $method . json_encode($queryParams, JSON_UNESCAPED_SLASHES) . json_encode($headers, JSON_UNESCAPED_SLASHES);
		return hash_hmac('sha256', $body, $secret);
	}

	public function validateExAppRequestToNC(IRequest $request, bool $isDav = false): bool {
		try {
			$exApp = $this->exAppMapper->findByAppId($request->getHeader('EX-APP-ID'));
			$enabled = $exApp->getEnabled();
			if (!$enabled) {
				return false;
			}
			$secret = $exApp->getSecret();
			// TODO: Add check of debug mode for logging each request if needed
		} catch (DoesNotExistException) {
			return false;
		}
		$method = $request->getMethod();
		$headers = [
			'AE-VERSION' => $request->getHeader('AE-VERSION'),
			'EX-APP-ID' => $request->getHeader('EX-APP-ID'),
			'EX-APP-VERSION' => $request->getHeader('EX-APP-VERSION'),
		];
		$userId = $request->getHeader('NC-USER-ID');
		if ($userId !== '') {
			$headers['NC-USER-ID'] = $userId;
		}
		$requestSignature = $request->getHeader('AE-SIGNATURE');
		$queryParams = $this->cleanupParams($request->getParams());
		// $this->sortNestedArrayAssoc($queryParams);
		array_walk_recursive(
			$queryParams,
			function(&$v) {
				if (is_numeric($v)) {
					$v = strval($v);
				}
			}
		);

		$dataHash = $request->getHeader('AE-DATA-HASH');
		$headers['AE-DATA-HASH'] = $dataHash;
		$signTime = $request->getHeader('AE-SIGN-TIME');
		if (!$this->verifySignTime($signTime)) {
			return false;
		}
		$headers['AE-SIGN-TIME'] = $signTime;

		if ($isDav) {
			$method .= $request->getRequestUri();
		}
		if (!empty($queryParams)) {
			$body = $method . json_encode($queryParams, JSON_UNESCAPED_SLASHES) . json_encode($headers, JSON_UNESCAPED_SLASHES);
		} else {
			$body = $method . json_encode($headers, JSON_UNESCAPED_SLASHES);
		}
		$signature = hash_hmac('sha256', $body, $secret);
		$signatureValid = $signature === $requestSignature;

		if (!$this->exAppUserExists($exApp->getAppid(), $userId)) {
			return false;
		}

		if ($signatureValid) {
			if (!$this->verifyDataHash($dataHash)) {
				return false;
			}
			try {
				$path = $request->getPathInfo();
			} catch (\Exception $e) {
				$this->logger->error('Error getting path info: ' . $e->getMessage());
				return false;
			}
			$apiScope = $this->getApiRouteScope($path);

			if ($apiScope === null) {
				return false;
			}
			// If it's initialization scope group
			if ($apiScope->getScopeGroup() === self::INIT_API_SCOPE) {
				return true;
			}
			// If it's another scope group - proceed with default checks
			if (!$this->passesScopeCheck($exApp, $apiScope->getScopeGroup())) {
				return false;
			}
			// If scope check passed, and it's system scope group - proceed with request
			if ($apiScope->getScopeGroup() === self::SYSTEM_API_SCOPE) {
				return true;
			}

			if ($userId !== '') {
				$activeUser = $this->userManager->get($userId);
				if ($activeUser === null) {
					$this->logger->error('Requested user does not exists: ' . $userId);
					return false;
				}
				$this->userSession->setUser($activeUser);
				$this->updateExAppLastResponseTime($exApp);
				return true;
			}
			return false;
		}
		$this->logger->error('Invalid signature for ex app: ' . $exApp->getAppid() . ' and user: ' . $userId);
		return false;
	}

	private function updateExAppLastResponseTime($exApp): void {
		$exApp->setLastResponseTime(time());
		try {
			$this->exAppMapper->updateLastResponseTime($exApp);
		} catch (\Exception $e) {
			$this->logger->error('Error while updating ex app last response time for ex app: ' . $exApp->getAppid() . '. Error: ' . $e->getMessage());
		}
	}

	public function getNCUsersList(): ?array {
		return array_map(function (IUser $user) {
			return $user->getUID();
		}, $this->userManager->searchDisplayName(''));
	}

	private function exAppUserExists(string $appId, string $userId): bool {
		try {
			if ($this->exAppUserMapper->findByAppidUserid($appId, $userId) instanceof ExAppUser) {
				return true;
			}
			return false;
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return false;
		}
	}

	/**
	 * Service function to clean up params from injected params
	 */
	private function cleanupParams(array $params): array {
		if (isset($params['_route'])) {
			unset($params['_route']);
		}
		return $params;
	}

	public function passesScopeCheck(ExApp $exApp, int $apiScope): bool {
		try {
			$exAppScope = $this->exAppScopeMapper->findByAppidScope($exApp->getAppid(), $apiScope);
			return $exAppScope instanceof ExAppScope;
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return false;
		}
	}

	public function getApiRouteScope(string $apiRoute): ?ExAppApiScope {
		try {
			return $this->exAppApiScopeMapper->findByApiRoute($apiRoute);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return null;
		}
	}

	public function registerInitScopes(): bool {
		$apiV1Prefix = '/apps/' . Application::APP_ID . '/api/v1';

		$fileActionsMenuApiScope = new ExAppApiScope([
			'api_route' =>  $apiV1Prefix . '/files/actions/menu',
			'scope_group' => self::INIT_API_SCOPE
		]);
		$logApiScope = new ExAppApiScope([
			'api_route' =>  $apiV1Prefix . '/log',
			'scope_group' => self::INIT_API_SCOPE
		]);
		$usersApiScope = new ExAppApiScope([
			'api_route' =>  $apiV1Prefix . '/users',
			'scope_group' => self::SYSTEM_API_SCOPE
		]);
		$appConfigApiScope = new ExAppApiScope([
			'api_route' =>  $apiV1Prefix . '/ex-app/config',
			'scope_group' => self::SYSTEM_API_SCOPE
		]);
		$appConfigKeysApiScope = new ExAppApiScope([
			'api_route' =>  $apiV1Prefix . '/ex-app/config/keys',
			'scope_group' => self::SYSTEM_API_SCOPE
		]);
		$appConfigAllApiScope = new ExAppApiScope([
			'api_route' =>  $apiV1Prefix . '/ex-app/config/all',
			'scope_group' => self::SYSTEM_API_SCOPE
		]);

		$initApiScopes = [
			$fileActionsMenuApiScope,
			$logApiScope,
			$usersApiScope,
			$appConfigApiScope,
			$appConfigKeysApiScope,
			$appConfigAllApiScope
		];

		try {
			foreach ($initApiScopes as $apiScope) {
				$this->exAppApiScopeMapper->insertOrUpdate($apiScope);
			}
			return true;
		} catch (Exception $e) {
			$this->logger->error('Failed to fill init api scopes: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Verify if sign time is within MAX_SIGN_TIME_DIFF (5 min)
	 *
	 * @param string $signTime
	 * @return bool
	 */
	private function verifySignTime(string $signTime): bool {
		$signTime = intval($signTime);
		$currentTime = time();
		$diff = $currentTime - $signTime;
		if ($diff > self::MAX_SIGN_TIME_DIFF) {
			$this->logger->error('AE-SIGN-TIME diff is too big: ' . $diff);
			return false;
		}
		if ($diff < 0) {
			$this->logger->error('AE-SIGN-TIME diff is negative: ' . $diff);
			return false;
		}
		return true;
	}

	private function verifyDataHash(string $dataHash): bool {
		$hashContext = hash_init('xxh64');
		$stream = fopen('php://input', 'r');
		hash_update_stream($hashContext, $stream, -1);
		fclose($stream);
		$phpInputHash = hash_final($hashContext);
		return $dataHash === $phpInputHash;
	}
}
