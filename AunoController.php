<?php declare(strict_types=1);

namespace Nadybot\User\Modules\AUNO_MODULE;

use function Amp\async;
use function Amp\Future\{await};
use Amp\Http\Client\{HttpClientBuilder, Request};
use Amp\TimeoutCancellation;
use Exception;
use League\Uri\{Modifier};
use Nadybot\Core\ParamClass\PItem;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	ModuleInstance,
	Safe,
	Text,
};
use Nadybot\Modules\ITEMS_MODULE\{
	ItemSearchResult,
	ItemsController,
};

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */

#[
	NCA\Instance(),
	NCA\DefineCommand(
		command: 'auno',
		accessLevel: 'guest',
		description: 'Search for comments on auno',
	)
]
class AunoController extends ModuleInstance {
	#[NCA\Inject]
	private HttpClientBuilder $http;

	#[NCA\Inject]
	private ItemsController $itemsController;

	/**
	 * Get the Auno object for an item by a search string, or show a choice dialogue
	 *
	 * @param string     $search  The string to search for
	 * @param CmdContext $context Where to send the reply yo
	 *
	 * @return AunoItem|null Either the item, or null if error or multiple choices presented
	 */
	public function getItemFromSearch(string $search, CmdContext $context): ?AunoItem {
		// If this is a search string, search the item database for low and high ql
		$findings = $this->itemsController->findItemsFromLocal($search, null);
		// Remove duplicates
		$lookup = [];
		$findings = array_filter(
			$findings,
			static function (ItemSearchResult $item) use (&$lookup): bool {
				if (isset($lookup["{$item->lowid}-{$item->highid}"])) {
					return false;
				}
				return $lookup["{$item->lowid}-{$item->highid}"] = true;
			}
		);
		// Nothing found? Errooooor
		if (count($findings) === 0) {
			$msg = "No items found matching <highlight>{$search}<end>.";
			$context->reply($msg);
			return null;
		} elseif (count($findings) > 1) {
			// If we found more than 1 item, check if there is an exact match first
			$exactFindings = array_values(array_filter($findings, static function (ItemSearchResult $item): bool {
				return $item->numExactMatches === 100;
			}));
			// If we didn't find an exact match, ask which one to use
			if (count($exactFindings) !== 1) {
				$blob = "Search: <highlight>{$search}<end>\n";
				$num = count($findings);
				foreach ($findings as $item) {
					$itemLink = $item->getLink($item->highql);
					$blob .= '[' . Text::makeChatcmd('See Comments', "/tell <myname> auno {$itemLink}") . '] '.
						"{$itemLink}\n";
				}
				if ($num === $this->itemsController->maxitems) {
					$blob .= "\n\n<highlight>*Results have been limited to the first ".
						"{$this->itemsController->maxitems} results.<end>";
				}
				$blob .= "\n\n";
				$link = Text::makeBlob("Item Search Results ({$num})", $blob, 'Choose item for which to display comments');
				$context->reply($link);
				return null;
			}
			$findings = $exactFindings;
		}
		$item = new AunoItem(
			lowId: $findings[0]->lowid,
			highId: $findings[0]->highid,
			ql: $findings[0]->highql,
			name: $findings[0]->name,
		);
		return $item;
	}

	/**
	 * Find an Auno Item by search term or pasted text
	 *
	 * @param string     $search  The text/object to search for
	 * @param CmdContext $context Where to send the replies to
	 *
	 * @return AunoItem|null The search object or null
	 */
	public function getItem(string $search, CmdContext $context): ?AunoItem {
		try {
			$item = new PItem($search);
			return new AunoItem(
				lowId: $item->lowID,
				highId: $item->highID,
				ql: $item->ql,
				name: $item->name,
			);
		} catch (Exception) {
			return $this->getItemFromSearch($search, $context);
		}
	}

	/** Search auno for comments on an item */
	#[NCA\HandlesCommand('auno')]
	public function aunoCommand(CmdContext $context, string $search): void {
		$item = $this->getItem($search, $context);
		if ($item === null) {
			return;
		}
		$jobs = [];
		$jobs['low']= async($this->getAunoComments(...), $item->lowId);
		// Download auno comments for low ID and high ID (if it's different to lowID) and merge them into 1
		$comments = $this->getAunoComments($item->lowId);
		if ($item->lowId !== $item->highId) {
			$jobs['high'] = async($this->getAunoComments(...), $item->highId);
		}
		$jobComments = await($jobs);
		$comments = $this->mergeComments(...$jobComments['low'], ...($jobComments['high']??[]));

		// Display them
		$itemLink = $this->makeItem($item);
		if (count($comments) === 0) {
			$msg = 'No comments found on auno.org for ' . $itemLink;
			$context->reply($msg);
			return;
		}
		$blobs = [];
		$commentNum = 0;
		foreach ($comments as $comment) {
			$blobs []= sprintf(
				"%02d - <highlight>%s<end> - <orange>%s<end>\n%s",
				++$commentNum,
				$comment->time,
				$comment->user,
				$comment->comment,
			);
		}
		$blob = implode("\n\n<pagebreak>", $blobs);
		$msg = Text::makeBlob(
			count($blobs) . ' comments',
			$blob,
			count($blobs) . ' Auno comments for ' . $itemLink
		);
		$msg .= " found on Auno for {$itemLink}";
		$context->reply($msg);
	}

	/**
	 * Merge all given comments together
	 *
	 * @return list<AunoComment>
	 */
	public function mergeComments(AunoComment ...$comments): array {
		usort($comments, static function (AunoComment $a, AunoComment $b): int {
			return strcmp($a->time, $b->time);
		});
		return $comments;
	}

	/**
	 * Load comments from AUNO for a specific item ID
	 *
	 * @param int $itemId The ID of the item
	 *
	 * @return list<AunoComment> The parsed commments
	 */
	public function getAunoComments(int $itemId): array {
		$uri = Modifier::from('https://auno.org/ao/db.php')
			->appendQueryParameters(['id' => $itemId]);
		$request = new Request($uri->getUriString());
		$client = $this->http->build();
		$request->setTcpConnectTimeout(5);
		$request->setTlsHandshakeTimeout(5);
		$request->setTransferTimeout(5);
		$response = $client->request($request, new TimeoutCancellation(10));

		/** @var list<AunoComment> */
		$comments = [];
		if ($response->getStatus() !== 200) {
			return $comments;
		}
		$body = $response->getBody()->buffer(new TimeoutCancellation(10));
		if ($body === '') {
			return $comments;
		}
		$matches = Safe::pregMatch(
			"|<legend>Comments</legend>\s*<table class='list' style='width: 100%'>(.+?)</table>|s",
			$body,
		);
		if (count($matches) < 2) {
			return $comments;
		}
		$pages = Safe::pregMatchAll(
			'|'.
				"<span style='text-decoration: underline; font-size: 110%'>\s*".
					"(?<user>.+?) @ (?<time>\d{4}-\d{2}-\d{2} \d{2}:\d{2})\s*".
				"</span>\s*".
				"<br />\s*".
				"<div style='margin-bottom: 20px'>\s*".
					"(?<comment>.*?)\s*".
				'</div>'.
			'|s',
			$matches[1],
		);
		if (count($pages) < 1) {
			return $comments;
		}
		for ($i = 0; $i < count($pages[0]); $i++) {
			$comment = new AunoComment(
				user: $pages['user'][$i],
				time: $pages['time'][$i],
				comment: $pages['comment'][$i],
			);
			$comments []= $comment->cleanComment();
		}
		return $comments;
	}

	/**
	 * Make a link to an item
	 *
	 * @param AunoItem $item The item to link to
	 *
	 * @return string The <a href...> link
	 */
	public function makeItem(AunoItem $item): string {
		return Text::makeItem($item->lowId, $item->highId, $item->ql, $item->name);
	}
}
