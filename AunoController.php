<?php declare(strict_types=1);

namespace Nadybot\User\Modules\AUNO_MODULE;

use Nadybot\Core\{
	CommandReply,
	DB,
	DBRow,
	Http,
	LoggerWrapper,
	SettingManager,
	Text,
};
use Nadybot\Modules\ITEMS_MODULE\ItemsController;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'auno',
 *		accessLevel = 'all',
 *		description = 'Search for comments on auno',
 *		help        = 'auno.txt'
 *	)
 */
class AunoController {
	
	public string $moduleName;

	/** @Inject */
	public Http $http;

	/** @Inject */
	public Text $text;
	
	/** @Inject */
	public DB $db;
	
	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public ItemsController $itemsController;
	
	/** @Logger */
	public LoggerWrapper $logger;

	/**
	 * Get the Auno object for an item built by its hash-values
	 *
	 * @param mixed[] $specs The item's values as [key => value]
	 * @return AunoItem
	 */
	public function getItemFromHash(array $specs): AunoItem {
		$item = new AunoItem();
		$item->highId = (int)$specs['highId'];
		$item->lowId  = (int)$specs['lowId'];
		$item->ql     = (int)$specs['ql'];
		$item->name   = $specs['name'];

		return $item;
	}

	/**
	 * Get the Auno object for an item by a search string, or show a choice dialogue
	 *
	 * @param string $search The string to search for
	 * @param \Nadybot\Core\CommandReply $sendto Where to send the reply yo
	 * @return \Nadybot\User\Modules\AUNO_MODULE\AunoItem|null Either the item, or null if error or multiple choices presented
	 */
	public function getItemFromSearch(string $search, CommandReply $sendto): ?AunoItem {
		// If this is a search string, search the item database for low and high ql
		$findings = $this->itemsController->findItemsFromLocal($search, null);
		// Nothing found? Errooooor
		if (empty($findings)) {
			$msg = "No items found matching <highlight>$search<end>.";
			$sendto->reply($msg);
			return null;
		} elseif (count($findings) > 1) {
			// If we found more than 1 item, check of there is an exact match first
			$exactFindings = array_values(array_filter($findings, function(DBRow $item) {
				return $item->numExactMatches === 100;
			}));
			// If we didn't find an exact match, ask which one to use
			if (count($exactFindings) !== 1) {
				$blob = "Search: <highlight>$search<end>\n";
				$num = count($findings);
				foreach ($findings as $item) {
					$itemLink = $this->text->makeItem($item->lowid, $item->highid, $item->highql, $item->name);
					$blob .= "[" . $this->text->makeChatcmd("See Comments", "/tell <myname> auno ${itemLink}") . "] ".
						 "$itemLink\n";
				}
				if ($num == $this->settingManager->get('maxitems')) {
					$blob .= "\n\n<highlight>*Results have been limited to the first " . $this->settingManager->get("maxitems") . " results.<end>";
				}
				$blob .= "\n\n";
				$link = $this->text->makeBlob("Item Search Results ($num)", $blob, "Choose item for which to display comments");
				$sendto->reply($link);
				return null;
			}
			$findings = $exactFindings;
		}
		$item = new AunoItem();
		$item->lowId  = (int)$findings[0]->lowid;
		$item->highId = (int)$findings[0]->highid;
		$item->ql     = (int)$findings[0]->highql;
		$item->name   = $findings[0]->name;

		return $item;
	}

	/**
	 * Find an Auno Item by search term or pasted text
	 *
	 * @param string $search The text/object to search for
	 * @param \Nadybot\Core\CommandReply $sendto Where to send the replies to
	 * @return \Nadybot\User\Modules\AUNO_MODULE\AunoItem|null The search object or null
	 */
	public function getItem(string $search, CommandReply $sendto): ?Aunoitem {
		$search = html_entity_decode($search, ENT_QUOTES, "UTF-8");
		// Check if we were given a link to a item. If so, extract low and high ql
		if (preg_match("|<a href=['\"]itemref://(?<lowId>\d+)/(?<highId>\d+)/(?<ql>\d+)['\"]>(?<name>.+?)</a>|", $search, $matches)) {
			return $this->getItemFromHash($matches);
		}
		return $this->getItemFromSearch($search, $sendto);
	}

	/**
	 * Search auno for comments for an item
	 *
	 * @param string $message The full text as received by the bot
	 * @param string $channel "tell", "guild" or "priv"
	 * @param string $sender Name of the person sending the command
	 * @param \Nadybot\Core\CommandReply $sendto Object to send the reply to
	 * @param string[] $args The parameters as parsed with the regexp
	 *
	 * @return void
	 *
	 * @HandlesCommand("auno")
	 * @Matches("/^auno (.+)$/i")
	 */
	public function aunoCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$item = $this->getItem($args[1], $sendto);
		if ($item === null) {
			return;
		}
		// Download auno comments for low ID and high ID (if it's different to lowID) and merge them into 1
		$comments  = $this->getAunoComments((int)$item->lowId);
		if ($item->lowId != $item->highId) {
			$commentsHigh = $this->getAunoComments((int)$item->highId);
			$comments = $this->mergeComments($comments, $commentsHigh);
		}
		// Display them
		$itemLink = $this->makeItem($item);
		if (empty($comments)) {
			$msg = "No comments found on auno.org for " . $itemLink;
			$sendto->reply($msg);
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
		$blob = join("\n\n<pagebreak>", $blobs);
		$pages = $this->text->makeBlob(count($blobs) . " comments", $blob, count($blobs) . " Auno comments for " . $itemLink);
		if (is_array($pages)) {
			$msg = array_map(function($page) use ($itemLink) {
				return $page . " found on Auno for ${itemLink}";
			}, $pages);
		} else {
			$msg =  $pages . " found on Auno for ${itemLink}.";
		}
		$sendto->reply($msg);
	}

	/**
	 * Merge all given comments together
	 *
	 * @param \Nadybot\User\Modules\AUNO_MODULE\AunoComment[] $comments,... Every parameter is an array of comments
	 * @return \Nadybot\User\Modules\AUNO_MODULE\AunoComment[] The merged comments
	 */
	public function mergeComments(...$comments): array {
		$merged = array_reduce($comments, 'array_merge', []);
		usort($merged, function(AunoComment $a, AunoComment $b) {
			return strcmp($a->time, $b->time);
		});
		return $merged;
	}

	/**
	 * Load comments from AUNO for a specific item ID
	 *
	 * @param int $itemId The ID of the item
	 * @return AunoComment[] List of comments with user, time and comment
	 */
	public function getAunoComments(int $itemId): array {
		$comments = [];
		$response = $this->http
				->get('https://auno.org/ao/db.php')
				->withQueryParams(['id' => $itemId])
				->withTimeout(10)
				->waitAndReturnResponse();
		if (isset($response->error)) {
			return $comments;
		}
		if (!preg_match("|<legend>Comments</legend>\s*<table class='list' style='width: 100%'>(.+?)</table>|s", $response->body, $matches)) {
			return $comments;
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
			return $comments;
		}
		for ($i = 0; $i < $numMatches; $i++) {
			$comment = new AunoComment();
			$comment->user = $pages['user'][$i];
			$comment->time = $pages['time'][$i];
			$comment->comment = $pages['comment'][$i];
			$comment->cleanComment();
			$comments []= $comment;
		}
		return $comments;
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
