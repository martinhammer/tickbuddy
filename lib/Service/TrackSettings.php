<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Service;

/**
 * The type settings sent with a track create or update, as the controller
 * received them, null for anything not sent. {@see TrackService} checks them
 * against the track's type.
 *
 * Number: `unit`, `decimalPlaces`, `allowNegative`. Choice: `options` (create
 * only, a list of labels in place order) and `orderedScale`.
 */
final class TrackSettings {
	/**
	 * @param ?array<array-key, mixed> $options
	 */
	public function __construct(
		public readonly ?string $unit = null,
		public readonly string|int|float|bool|null $decimalPlaces = null,
		public readonly string|int|float|bool|null $allowNegative = null,
		public readonly ?array $options = null,
		public readonly string|int|float|bool|null $orderedScale = null,
	) {
	}

	/**
	 * Names of the Number settings that were sent.
	 *
	 * @return list<string>
	 */
	public function numberFields(): array {
		return array_keys(array_filter([
			'unit' => $this->unit !== null,
			'decimalPlaces' => $this->decimalPlaces !== null,
			'allowNegative' => $this->allowNegative !== null,
		]));
	}

	/**
	 * Names of the Choice settings that were sent.
	 *
	 * @return list<string>
	 */
	public function choiceFields(): array {
		return array_keys(array_filter([
			'options' => $this->options !== null,
			'orderedScale' => $this->orderedScale !== null,
		]));
	}

	/**
	 * The settings for an `Idempotency-Key` fingerprint: numbers and flags as
	 * read when they can be (so `"1"` and `1` match, as `delta` and the flags
	 * do elsewhere), else as sent; labels trimmed.
	 *
	 * @return list<mixed>
	 */
	public function fingerprint(): array {
		return [
			$this->unit,
			$this->decimalPlaces === null ? null : (TickService::parseWholeNumber($this->decimalPlaces, false) ?? $this->decimalPlaces),
			$this->allowNegative === null ? null : (TickService::parseYesNo($this->allowNegative) ?? $this->allowNegative),
			$this->options === null ? null : array_map(fn ($label) => is_string($label) ? trim($label) : $label, array_values($this->options)),
			$this->orderedScale === null ? null : (TickService::parseYesNo($this->orderedScale) ?? $this->orderedScale),
		];
	}
}
