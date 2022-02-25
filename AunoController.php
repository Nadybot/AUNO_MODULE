<?php declare(strict_types=1);

namespace Nadybot\User\Modules\AUNO_MODULE;

use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	Http,
	HttpResponse,
	ModuleInstance,
	SettingManager,
	Text,
};
use Nadybot\Core\ParamClass\PItem;
use Nadybot\Modules\ITEMS_MODULE\{
	ItemsController,
	ItemSearchResult,
};

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */

#[
	NCA\Instance(),
	NCA\DefineCommand(
		command:     'auno',
		accessLevel: 'guest',
		description: 'Search for comments on auno',
	)
]
class AunoController extends ModuleInstance {
	#[NCA\Inject]
	public Http $http;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public ItemsController $itemsController;

	/**
	 * Get the Auno object for an item by a search string, or show a choice dialogue
	 *
	 * @param string $search The string to search for
	 * @param CmdContext $context Where to send the reply yo
	 * @return AunoItem|null Either the item, or null if error or multiple choices presented
	 */
	public function getItemFromSearch(string $search, CmdContext $context): ?AunoItem {
		// If this is a search string, search the item database for low and high ql
		$findings = $this->itemsController->findItemsFromLocal($search, null);
		// Remove duplicates
		$lookup = [];
		$findings = array_filter(
			$findings,
			function(ItemSearchResult $item) use (&$lookup): bool {
				if (isset($lookup["{$item->lowid}-{$item->highid}"])) {
					return false;
				}
				return $lookup["{$item->lowid}-{$item->highid}"] = true;
			}
		);
		// Nothing found? Errooooor
		if (empty($findings)) {
			$msg = "No items found matching <highlight>$search<end>.";
			$context->reply($msg);
			return null;
		} elseif (count($findings) > 1) {
			// If we found more than 1 item, check if there is an exact match first
			$exactFindings = array_values(array_filter($findings, function(ItemSearchResult $item) {
				return $item->numExactMatches === 100;
			}));
			// If we didn't find an exact match, ask which one to use
			if (count($exactFindings) !== 1) {
				$blob = "Search: <highlight>$search<end>\n";
				$num = count($findings);
				foreach ($findings as $item) {
					$itemLink = $item->getLink($item->highql);
					$blob .= "[" . $this->text->makeChatcmd("See Comments", "/tell <myname> auno ${itemLink}") . "] ".
						"$itemLink\n";
				}
				if ($num === $this->settingManager->getInt('maxitems')) {
					$blob .= "\n\n<highlight>*Results have been limited to the first ".
						($this->settingManager->getInt("maxitems")??40).
						" results.<end>";
				}
				$blob .= "\n\n";
				$link = $this->text->makeBlob("Item Search Results ($num)", $blob, "Choose item for which to display comments");
				$context->reply($link);
				return null;
			}
			$findings = $exactFindings;
		}
		$item = new AunoItem([
			"lowId"  => (int)$findings[0]->lowid,
			"highId" => (int)$findings[0]->highid,
			"ql"     => (int)$findings[0]->highql,
			"name"   => $findings[0]->name,
		]);
		return $item;
	}

	/**
	 * Find an Auno Item by search term or pasted text
	 *
	 * @param string $search The text/object to search for
	 * @param CmdContext $context Where to send the replies to
	 * @return AunoItem|null The search object or null
	 */
	public function getItem(string $search, CmdContext $context): ?AunoItem {
		try {
			$item = new PItem($search);
			return new AunoItem([
				"lowId"  => $item->lowID,
				"highId" => $item->highID,
				"ql"     => $item->ql,
				"name"   => $item->name,
			]);
		} catch (Exception) {
			return $this->getItemFromSearch($search, $context);
		}
	}

	/**
	 * Search auno for comments on an item
	 */
	#[NCA\HandlesCommand("auno")]
	public function aunoCommand(CmdContext $context, string $search): void {
		$item = $this->getItem($search, $context);
		if ($item === null) {
			return;
		}
		$allComments = [];
		$numCalls = 0;
		$callback = function(AunoComment ...$comments) use ($context, &$allComments, &$numCalls, $item): void {
			$allComments = $this->mergeComments(...$allComments, ...$comments);
			if (--$numCalls > 0) {
				return;
			}
			// Display them
			$itemLink = $this->makeItem($item);
			if (empty($allComments)) {
				$msg = "No comments found on auno.org for " . $itemLink;
				$context->reply($msg);
				return;
			}
			$blobs = [];
			$commentNum = 0;
			foreach ($allComments as $comment) {
				$blobs []= sprintf(
					"%02d - <highlight>%s<end> - <orange>%s<end>\n%s",
					++$commentNum,
					$comment->time,
					$comment->user,
					$comment->comment,
				);
			}
			$blob = join("\n\n<pagebreak>", $blobs);
			$pages = $this->text->makeBlob(count($blobs) . " comments", $blob, count($blobs) . " Auno comments for " . $itemLink);
			$msg = $this->text->blobWrap("", $pages, " found on Auno for ${itemLink}");
			$context->reply($msg);
		};
		// Download auno comments for low ID and high ID (if it's different to lowID) and merge them into 1
		$this->getAunoComments($item->lowId, $callback);
		$numCalls++;
		if ($item->lowId !== $item->highId) {
			$this->getAunoComments($item->highId, $callback);
			$numCalls++;
		}
	}

	/**
	 * Merge all given comments together
	 *
	 * @return AunoComment[]
	 */
	public function mergeComments(AunoComment ...$comments): array {
		usort($comments, function(AunoComment $a, AunoComment $b) {
			return strcmp($a->time, $b->time);
		});
		return $comments;
	}

	/**
	 * Load comments from AUNO for a specific item ID
	 *
	 * @param int $itemId The ID of the item
	 * @param callable $callback The function to call when data is there
	 * @psalm-param callable(AunoComment...):void $callback The function to call when data is there
	 */
	public function getAunoComments(int $itemId, callable $callback): void {
		$parser = function (HttpResponse $response) use ($callback): void {
			/** @var AunoComment[] */
			$comments = [];
			if (isset($response->error) || !isset($response->body)) {
				$callback(...$comments);
				return;
			}
			if (
				!preg_match(
					"|<legend>Comments</legend>\s*<table class='list' style='width: 100%'>(.+?)</table>|s",
					$response->body,
					$matches
				)
				|| !isset($matches[1])
			) {
				$callback(...$comments);
				return;
			}
			$numMatches = preg_match_all(
				"|".
					"<span style='text-decoration: underline; font-size: 110%'>\s*".
						"(?<user>.+?) @ (?<time>\d{4}-\d{2}-\d{2} \d{2}:\d{2})\s*".
					"</span>\s*".
					"<br />\s*".
					"<div style='margin-bottom: 20px'>\s*".
						"(?<comment>.*?)\s*".
					"</div>".
				"|s",
				$matches[1],
				$pages
			);
			if (!$numMatches) {
				$callback(...$comments);
				return;
			}
			for ($i = 0; $i < $numMatches; $i++) {
				$comment = new AunoComment();
				$comment->user = $pages['user'][$i];
				$comment->time = $pages['time'][$i];
				$comment->comment = $pages['comment'][$i];
				$comment->cleanComment();
				$comments []= $comment;
			}
			$callback(...$comments);
		};
		$this->http
				->get('https://auno.org/ao/db.php')
				->withQueryParams(['id' => $itemId])
				->withTimeout(10)
				->withCallback($parser);
	}

	/**
	 * Make a link to an item
	 *
	 * @param AunoItem $item The item to link to
	 * @return string The <a href...> link
	 */
	public function makeItem(AunoItem $item): string {
		return $this->text->makeItem($item->lowId, $item->highId, $item->ql, $item->name);
	}
}
