<?php declare(strict_types=1);

namespace Nadybot\User\Modules\AUNO_MODULE;

use DateTimeInterface;
use EventSauce\ObjectHydrator\PropertyCasters\{CastListToType, CastToDateTimeImmutable};
use Nadybot\Core\{Registry, Safe};
use Nadybot\Modules\ITEMS_MODULE\ItemsController;

class AOGalaxyComment {
	/**
	 * @param int               $id        Internal ID of the comment, not sorted by date
	 * @param string            $text      The HTML-formatted text of the comment with encoded entities
	 * @param string            $rawText   The raw text of the comment without encoded entities
	 * @param string            $author    The name of the author of the comment
	 * @param int               $score     Internal ranking score of a comment
	 * @param AOGalaxyComment[] $children
	 * @param DateTimeInterface $timestamp The timestamp of the comment
	 *
	 * @psalm-param list<AOGalaxyComment> $children
	 */
	final public function __construct(
		public readonly int $id,
		public readonly string $text,
		public readonly string $rawText,
		public readonly string $author,
		public readonly int $score,
		#[CastListToType(self::class)] public readonly array $children,
		#[CastToDateTimeImmutable('M d, Y')] public readonly DateTimeInterface $timestamp,
	) {
	}

	/**
	 * Replace item-links in comments with actual AO item links
	 *
	 * @return string The cleaned comment text
	 */
	public function cleanComment(): string {
		$text = $this->rawText;
		$text = Safe::pregReplace("|<br\s*/?>|", '', $text);
		$text = Safe::pregReplace('{<a href=([\'"])(http.+?)\1>(.*?)</a>}s', '$2', $text);
		$text = Safe::pregReplaceCallback(
			'{https?://(?:(?:www\.)?auno\.org/ao/db\.php\?id=|(?:www\.)?aogalaxy\.com/_items/item\.php\?aoid=)(?<id>\d+)(?:&ql=(?<ql>\d+))?}s',
			/** @param array{0:string,id:string,ql?:string} $matches */
			static function (array $matches): string {
				$itemsController = Registry::getInstance(ItemsController::class);
				$itemId = (int)$matches['id'];
				$item = $itemsController->findById($itemId);
				if ($item === null) {
					return $matches[0];
				}
				$ql = isset($matches['ql']) ? (int)$matches['ql'] : null;
				return $item->getLink($ql);
			},
			$text
		);
		$text = Safe::pregReplace('|/waypoint\s*(\d+)\s+(\d+)\s+(\d+)|s', "<a href='chatcmd:///waypoint $1 $2 $3'>/waypoint $1 $2 $3</a>", $text);
		$text = Safe::pregReplace("|(https?://[^'\"\s]+)|", "<a href='chatcmd:///start $1'>$1</a>", $text);
		return $text;
	}
}
