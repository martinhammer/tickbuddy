<?php

declare(strict_types=1);

namespace Tickbuddy;

use OCA\Tickbuddy\Dashboard\WeekWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

final class WeekWidgetTest extends TestCase {
	private IL10N $l;
	private IURLGenerator $urlGenerator;
	private WeekWidget $widget;

	protected function setUp(): void {
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnArgument(0);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->widget = new WeekWidget($this->l, $this->urlGenerator);
	}

	public function testIdIsStable(): void {
		// Stored in users' dashboard layouts: changing it drops the widget for everyone.
		$this->assertSame('tickbuddy-week', $this->widget->getId());
	}

	public function testTitleAndIconClass(): void {
		$this->assertSame('Tickbuddy', $this->widget->getTitle());
		$this->assertSame('icon-tickbuddy-widget', $this->widget->getIconClass());
	}

	public function testIconUrlUsesAppIcon(): void {
		$this->urlGenerator->expects($this->once())
			->method('imagePath')
			->with('tickbuddy', 'app-dark.svg')
			->willReturn('/apps/tickbuddy/img/app-dark.svg');
		$this->urlGenerator->expects($this->once())
			->method('getAbsoluteURL')
			->with('/apps/tickbuddy/img/app-dark.svg')
			->willReturn('https://cloud.example/apps/tickbuddy/img/app-dark.svg');

		$this->assertSame('https://cloud.example/apps/tickbuddy/img/app-dark.svg', $this->widget->getIconUrl());
	}

	public function testUrlOpensApp(): void {
		$this->urlGenerator->expects($this->once())
			->method('linkToRouteAbsolute')
			->with('tickbuddy.page.index')
			->willReturn('https://cloud.example/apps/tickbuddy/');

		$this->assertSame('https://cloud.example/apps/tickbuddy/', $this->widget->getUrl());
	}
}
