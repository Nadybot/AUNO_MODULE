<?php declare(strict_types=1);

namespace Nadybot\User\Modules\AUNO_MODULE;

use Spatie\DataTransferObject\DataTransferObject;

class AunoItem extends DataTransferObject {
	public int $lowId = 0;
	public int $highId = 0;
	public int $ql = 0;
	public string $name = "";
}
