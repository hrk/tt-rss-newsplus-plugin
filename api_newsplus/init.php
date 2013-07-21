<?php
/*
 * This plugin adds API-calls used by News+ in order to
 * support two-way synchronization in a way compatible
 * to News+ internal structure.
 */
class Api_newsplus extends Plugin {

	private $host;
	private $dbh;

	/**
	 * Plugin interface: about.
	 */
	function about() {
		return array(1.0
			, "API plugin for News+"
			, "hrk"
			, true // Must be a system plugin to add an API.
			, "http://github.com/hrk/tt-rss-newsplus-plugin/"
			);
	}
	
	/**
	 * Plugin interface.
	 */
	function api_version() {
		return 2;
	}

	/**
	 * Plugin interface.
	 */
	function init($host) {
		$this->host = $host;
		$this->dbh = $host->get_dbh();

		$this->host->add_api_method("getCompactHeadlines", $this);
	}

	/**
	 * Our own API.
	 */
	function getCompactHeadlines() {
		$feed_id = db_escape_string($_REQUEST["feed_id"]);
		if ($feed_id != "") {
			$limit = (int) db_escape_string($_REQUEST["limit"]);
			$offset = (int) db_escape_string($_REQUEST["skip"]);
			/* all_articles, unread, adaptive, marked, updated */
			$view_mode = db_escape_string($_REQUEST["view_mode"]);
			$since_id = (int) db_escape_string($_REQUEST["since_id"]);

			/* */
			$headlines = $this->buildHeadlinesArray($feed_id, $limit, $offset, $view_mode, $since_id);
			return array(API::STATUS_OK, $headlines);
		} else {
			return array(API::STATUS_ERR, array("error" => 'INCORRECT_USAGE'));
		} // end-if: $feed_if != ""
	} // end-function

	/**
	 * Private function which builds the result. It is mapped on api_get_headlines in the official API.
	 */
	function buildHeadlinesArray($feed_id, $limit = 20, $offset = 0, $view_mode = "all_articles", $since_id = 0) {

			$qfh_ret = $this->queryFeedHeadlines($feed_id, $limit, $view_mode, $offset, $since_id);
			$result = $qfh_ret[0];
			$headlines = array();

			while ($line = db_fetch_assoc($result)) {
				/*
				foreach (PluginHost::getInstance()->get_hooks(PluginHost::HOOK_QUERY_HEADLINES) as $p) {
					$line = $p->hook_query_headlines($line, 100, true);
				}
				*/
				$headline_row = array("id" => (int)$line["id"]);

				array_push($headlines, $headline_row);
			}
			return $headlines;
	} // end-function buildHeadlinesArray

	/**
	 * Private function which executes the SQL query. Mapped on the same-named function in
	 * the official TT-RSS API, removing un-needed parts.
	 */
	function queryFeedHeadlines($feed, $limit, $view_mode, $offset = 0, $since_id = 0) {
		$owner_uid = $_SESSION["uid"];

		$ext_tables_part = "";

		$search_query_part = "";

		$filter_query_part = "";

		if ($since_id) {
			$since_id_part = "ttrss_entries.id > $since_id AND ";
		} else {
			$since_id_part = "";
		}

		$view_query_part = "";
		
		if ($view_mode == "adaptive") {
			if ($feed != -1) {
				$unread = getFeedUnread($feed, false);

				if ($unread > 0) {
					$view_query_part = " unread = true AND ";
				}
			}
		} else if ($view_mode == "marked") {
			$view_query_part = " marked = true AND ";
		} else if ($view_mode == "has_note") {
			$view_query_part = " (note IS NOT NULL AND note != '') AND ";
		} else if ($view_mode == "published") {
			$view_query_part = " published = true AND ";
		} else if ($view_mode == "unread" && $feed != -6) {
			$view_query_part = " unread = true AND ";
		}

		if ($limit > 0) {
			$limit_query_part = "LIMIT " . $limit;
		}

		$allow_archived = false;

		// override query strategy and enable feed display when searching globally
		if (!is_numeric($feed)) {
			$query_strategy_part = "true";
		} else if ($feed > 0) {
			$query_strategy_part = "feed_id = '$feed'";
		} else if ($feed == 0) { // archive virtual feed
			$query_strategy_part = "feed_id IS NULL";
			$allow_archived = true;
		} else if ($feed == -1) { // starred virtual feed
			$query_strategy_part = "marked = true";
			$allow_archived = true;

			$override_order = "last_marked DESC, date_entered DESC, updated DESC";
		} else if ($feed == -2) { // published virtual feed OR labels category
			$query_strategy_part = "published = true";
			$allow_archived = true;

			$override_order = "last_published DESC, date_entered DESC, updated DESC";
		} else if ($feed == -6) { // recently read
			$query_strategy_part = "unread = false AND last_read IS NOT NULL";
			$allow_archived = true;

			$override_order = "last_read DESC";
		} else if ($feed == -3) { // fresh virtual feed
			$query_strategy_part = "unread = true AND score >= 0";

			$intl = get_pref("FRESH_ARTICLE_MAX_AGE", $owner_uid);

			if (DB_TYPE == "pgsql") {
				$query_strategy_part .= " AND date_entered > NOW() - INTERVAL '$intl hour' ";
			} else {
				$query_strategy_part .= " AND date_entered > DATE_SUB(NOW(), INTERVAL $intl HOUR) ";
			}

		} else if ($feed == -4) { // all articles virtual feed
			$allow_archived = true;
			$query_strategy_part = "true";
		} else if ($feed <= LABEL_BASE_INDEX) { // labels
			$label_id = feed_to_label_id($feed);

			$query_strategy_part = "label_id = '$label_id' AND
				ttrss_labels2.id = ttrss_user_labels2.label_id AND
				ttrss_user_labels2.article_id = ref_id";

			$ext_tables_part = ",ttrss_labels2,ttrss_user_labels2";
			$allow_archived = true;
		} else {
			$query_strategy_part = "true";
		}

		$order_by = "score DESC, date_entered DESC, updated DESC";

		if ($view_mode == "unread_first") {
			$order_by = "unread DESC, $order_by";
		}

		if ($override_order) {
			$order_by = $override_order;
		}

		$content_query_part = "content, content AS content_preview, ";

		if (is_numeric($feed)) {
			if ($limit_query_part) {
				$offset_query_part = "OFFSET $offset";
			}

			if (!$allow_archived) {
				$from_qpart = "ttrss_entries,ttrss_user_entries,ttrss_feeds$ext_tables_part";
				$feed_check_qpart = "ttrss_user_entries.feed_id = ttrss_feeds.id AND";

			} else {
				$from_qpart = "ttrss_entries$ext_tables_part,ttrss_user_entries
					LEFT JOIN ttrss_feeds ON (feed_id = ttrss_feeds.id)";
			}

			$query = "SELECT DISTINCT
					date_entered,
					guid,
					ttrss_entries.id,
					updated,
					int_id,
					uuid,
					unread,marked,published,link,last_read,
					last_marked, last_published,
					score
				FROM
					$from_qpart
				WHERE
				$feed_check_qpart
				ttrss_user_entries.ref_id = ttrss_entries.id AND
				ttrss_user_entries.owner_uid = '$owner_uid' AND
				$search_query_part
				$filter_query_part
				$view_query_part
				$since_id_part
				$query_strategy_part ORDER BY $order_by
				$limit_query_part $offset_query_part";

			if ($_REQUEST["debug"]) print $query;

			$result = db_query($query);

		} else {
			// browsing by tag
			$select_qpart = "SELECT DISTINCT " .
							"date_entered," .
							"guid," .
							"ttrss_entries.id as id," .
							"updated," .
							"unread," .
							"marked," .
							"uuid," .
							"last_read," .
							"(SELECT hide_images FROM ttrss_feeds WHERE id = feed_id) AS hide_images," .
							"last_marked, last_published, " .
							$since_id_part .
							"score ";

			$all_tags = explode(",", $feed);
			$i = 1;
			$sub_selects = array();
			$sub_ands = array();
			foreach ($all_tags as $term) {
				array_push($sub_selects, "(SELECT post_int_id from ttrss_tags WHERE tag_name = " . db_quote($term) . " AND owner_uid = $owner_uid) as A$i");
				$i++;
			}
			if ($i > 2) {
				$x = 1;
				$y = 2;
				do {
					array_push($sub_ands, "A$x.post_int_id = A$y.post_int_id");
					$x++;
					$y++;
				} while ($y < $i);
			}
			array_push($sub_ands, "A1.post_int_id = ttrss_user_entries.int_id and ttrss_user_entries.owner_uid = $owner_uid");
			array_push($sub_ands, "ttrss_user_entries.ref_id = ttrss_entries.id");
			$from_qpart = " FROM " . implode(", ", $sub_selects) . ", ttrss_user_entries, ttrss_entries";
			$where_qpart = " WHERE " . implode(" AND ", $sub_ands);
			$result = db_query($select_qpart . $from_qpart . $where_qpart);
		}

		return array($result);
	} // end-function queryFeedHeadlines

} // end-class
?>
