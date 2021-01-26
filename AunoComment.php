<?php declare(strict_types=1);

namespace Nadybot\User\Modules\AUNO_MODULE;

class AunoComment {
	public string $user = '';
	public string $time = '1970-01-01 00:00';
	public string $comment = '';

	/**
	 * Remove HTML tags and cleanup the comment returned by AUNO
	 *
	 * @return $this
	 */
	public function cleanComment(): self {
		$this->comment = preg_replace("/\s*\n\s*/", "", $this->comment);
		$this->comment = preg_replace('|<br\s*/?>|', "\n", $this->comment);
		$this->comment = strip_tags($this->comment);
		$this->comment = trim($this->comment);
		$this->comment = preg_replace("|(https?://[^'\"\s]+)|", "<a href='chatcmd:///start $1'>$1</a>", $this->comment);

		return $this;
	}
}
