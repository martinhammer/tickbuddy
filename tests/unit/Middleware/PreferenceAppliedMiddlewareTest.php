<?php

declare(strict_types=1);

namespace Middleware;

use OCA\Tickbuddy\Middleware\PreferenceAppliedMiddleware;
use OCA\Tickbuddy\Service\StrictHandling;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\OCSController;
use PHPUnit\Framework\TestCase;

final class PreferenceAppliedMiddlewareTest extends TestCase {
	private function middleware(bool $requested): PreferenceAppliedMiddleware {
		$strictHandling = $this->createMock(StrictHandling::class);
		$strictHandling->method('requested')->willReturn($requested);
		return new PreferenceAppliedMiddleware($strictHandling);
	}

	public function testConfirmsStrictHandlingOnTheApi(): void {
		$response = $this->createMock(Response::class);
		$response->expects($this->once())->method('addHeader')->with('Preference-Applied', 'handling=strict');

		$result = $this->middleware(true)->afterController($this->createMock(OCSController::class), 'toggle', $response);
		$this->assertSame($response, $result);
	}

	public function testSaysNothingWhenNotAsked(): void {
		$response = $this->createMock(Response::class);
		$response->expects($this->never())->method('addHeader');

		$this->middleware(false)->afterController($this->createMock(OCSController::class), 'toggle', $response);
	}

	public function testSaysNothingOnThePage(): void {
		$response = $this->createMock(Response::class);
		$response->expects($this->never())->method('addHeader');

		$this->middleware(true)->afterController($this->createMock(Controller::class), 'index', $response);
	}
}
