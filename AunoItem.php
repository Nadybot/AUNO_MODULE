<?php declare(strict_types=1);

namespace Nadybot\User\Modules\AUNO_MODULE;

class AunoItem {
	public function __construct(
		public readonly int $lowId,
		public readonly int $highId,
		public readonly int $ql,
		public readonly string $name,
	) {
	}
}
