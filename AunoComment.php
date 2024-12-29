<?php declare(strict_types=1);

namespace Nadybot\User\Modules\AUNO_MODULE;

use Nadybot\Core\Safe;

class AunoComment {
	public function __construct(
		public readonly string $user='',
		public readonly string $time='1970-01-01 00:00',
		public readonly string $comment='',
	) {
	}

	/**
	 * Remove HTML tags and cleanup the comment returned by AUNO
	 *
	 * @return $this
	 */
	public function cleanComment(): self {
		$comment = Safe::pregReplace("/\s*\n\s*/", '', $this->comment);
		$comment = Safe::pregReplace('|<br\s*/?>|', "\n", $comment);
		$comment = strip_tags($comment);
		$comment = trim($comment);
		$comment = Safe::pregReplace("|(https?://[^'\"\s]+)|", "<a href='chatcmd:///start $1'>$1</a>", $comment);

		return new self(
			user: $this->user,
			time: $this->time,
			comment: $comment,
		);
	}
}
