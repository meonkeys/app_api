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

namespace OCA\AppEcosystemV2;

use OCP\ISession;
use OCP\IRequest;
use Sabre\DAV\Auth\Backend\BackendInterface;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

use OCA\AppEcosystemV2\AppInfo\Application;

class UserBackend implements BackendInterface {
	/** @var IRequest */
	private $request;

	/** @var ISession */
	private $session;

	public function __construct(
		IRequest $request,
		ISession $session,
	) {
		$this->request = $request;
		$this->session = $session;
	}

	public function check(RequestInterface $request, ResponseInterface $response) {
		if ($this->session->get('user_id') === $this->request->getHeader('EX-APP-ID')) {
			return [true, Application::APP_ID];
		}
		return [false, Application::APP_ID];
	}

	public function challenge(RequestInterface $request, ResponseInterface $response) {
	}
}
