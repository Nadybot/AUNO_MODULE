<?php

declare(strict_types=1);

namespace Nadybot\User\Modules\AUNO_MODULE;

use Amp\Http\Client\{HttpClientBuilder, Request};
use Amp\TimeoutCancellation;
use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	Hydrator,
	ModuleInstance,
	ParamClass\PItem,
	Safe,
	Text,
};
use Nadybot\Core\Types\AccessLevel;
use Nadybot\Modules\ITEMS_MODULE\{
	ItemSearchResult,
	ItemsController,
};
use Nadylib\Type;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */

#[
	NCA\Instance(),
	NCA\DefineCommand(
		command: 'auno',
		accessLevel: AccessLevel::Guest,
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
	 * @return null|AunoItem Either the item, or null if error or multiple choices presented
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
					$blob .= '[' . Text::makeChatcmd('See Comments', "/tell <myname> auno {$itemLink}") . '] ' .
						"{$itemLink}\n";
				}
				if ($num === $this->itemsController->maxitems) {
					$blob .= "\n\n<highlight>*Results have been limited to the first " .
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
	 * @return null|AunoItem The search object or null
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
		$comments = $this->getAOGalaxyComments($item->lowId);

		// Display them
		$itemLink = $this->makeItem($item);
		if (count($comments) === 0) {
			$msg = 'No comments found for ' . $itemLink;
			$context->reply($msg);
			return;
		}

		$blobs = [];
		$commentNum = 0;
		foreach ($comments as $comment) {
			$blobs[] = $this->getCommentLine($comment, 0, $commentNum);
		}
		$blob = implode("\n\n<pagebreak>", $blobs);
		$msg = Text::makeBlob(
			"{$commentNum} comments",
			$blob,
			"{$commentNum} Comments for {$itemLink}"
		);
		$msg .= " found for {$itemLink}";
		$context->reply($msg);
	}

	/**
	 * Load comments from AUNO for a specific item ID
	 *
	 * @param int $itemId The ID of the item
	 *
	 * @return list<AOGalaxyComment> The parsed commments
	 */
	public function getAOGalaxyComments(int $itemId): array {
		$uri = 'https://www.aogalaxy.com/_items/get_item_comments.php' .
			"?itemAOID={$itemId}";
		$request = new Request($uri);
		$client = $this->http->build();
		$request->setTcpConnectTimeout(5);
		$request->setTlsHandshakeTimeout(5);
		$request->setTransferTimeout(5);
		$response = $client->request($request, new TimeoutCancellation(10));

		/** @var list<AunoComment> */
		$empty = [];
		if ($response->getStatus() !== 200) {
			return $empty;
		}
		$body = $response->getBody()->buffer(new TimeoutCancellation(10));
		if ($body === '') {
			return $empty;
		}
		try {
			$json = Safe::jsonDecode($body, Type\vec(Type\dict(Type\string(), Type\mixed())));
			$comments = Hydrator::literalHydrateObjects(AOGalaxyComment::class, $json)->toArray();
		} catch (Exception) {
			// If we fail to parse the JSON, just return an empty array
			return $empty;
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

	/**
	 * Format a comment and its children into a displayable string
	 *
	 * @param AOGalaxyComment $comment    The comment to format
	 * @param int             $level      The current indentation level (for child comments)
	 * @param int             $commentNum The current comment number (for numbering comments)
	 *
	 * @return string The formatted comment line with children
	 */
	private function getCommentLine(AOGalaxyComment $comment, int $level, int &$commentNum): string {
		$indent = str_repeat('<tab>', $level);
		$text = sprintf(
			"%s%02d - <highlight>%s<end> - <orange>%s<end>\n%s",
			$indent,
			++$commentNum,
			$comment->timestamp->format('Y-m-d'),
			$comment->author,
			$indent . implode("\n{$indent}", array_map(trim(...), explode("\n", $comment->cleanComment()))),
		);
		foreach ($comment->children as $child) {
			$text .= "\n\n<pagebreak>" . $this->getCommentLine($child, $level + 1, $commentNum);
		}
		return $text;
	}
}
