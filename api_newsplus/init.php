<?php
/*
 * This plugin adds API-calls used by News+ in order to
 * support two-way synchronization in a way compatible
 * to News+ internal structure.
 */
class Api_newsplus extends Plugin {

	private $host;
	private $dbh;
	
	function about() {
		return array(1.0
			, "News+ plugin"
			, "hrk"
			, true // Not a system plugin.
			, "http://github.com/hrk/tt-rss-news+-plugin/"
			);

	}
	
	function api_version() {
		return 2;
	}

	function init($host) {
		$this->host = $host;
		$this->dbh = $host->get_dbh();
		
		$this->host->add_api_method("getCompactHeadlines", $this);
	}

    /*
     *
     */
	function getCompactHeadlines() {
		$feed_id = db_escape_string($_REQUEST["feed_id"]);
		if ($feed_id != "") {
			$limit = (int)db_escape_string($_REQUEST["limit"]);
			$offset = (int)db_escape_string($_REQUEST["skip"]);
			/* all_articles, unread, adaptive, marked, updated */
			$view_mode = db_escape_string($_REQUEST["view_mode"]);
			$since_id = (int)db_escape_string($_REQUEST["since_id"]);

			/* */
			$headlines = $this->buildHeadlinesArray($feed_id, $limit, $offset, $view_mode, $since_id);
			return array(API::STATUS_OK, $headlines);
		} else {
			return array(API::STATUS_ERR, array("error" => 'INCORRECT_USAGE'));
		} // end-if: $feed_if != ""
	} // end-function












	function buildHeadlinesArray($feed_id, $limit = 20, $offset = 0, $view_mode = "all_articles", $since_id) {

			$qfh_ret = $this->queryFeedHeadlines($feed_id, $limit,
				$view_mode, false, "", "",
				"", $offset, 0, false, $since_id, false);

			$result = $qfh_ret[0];

			$headlines = array();

			while ($line = db_fetch_assoc($result)) {
				/*
				foreach (PluginHost::getInstance()->get_hooks(PluginHost::HOOK_QUERY_HEADLINES) as $p) {
					$line = $p->hook_query_headlines($line, 100, true);
				}
				*/

				$is_updated = ($line["last_read"] == "" &&
					($line["unread"] != "t" && $line["unread"] != "1"));

				$headline_row = array(
						"id" => (int)$line["id"],
/*						"unread" => sql_bool_to_bool($line["unread"]),
						"marked" => sql_bool_to_bool($line["marked"]),
						"published" => sql_bool_to_bool($line["published"]),
						"updated" => (int) strtotime($line["updated"]),
						"is_updated" => $is_updated,
						"feed_id" => $line["feed_id"],*/
					);

/*
				foreach (PluginHost::getInstance()->get_hooks(PluginHost::HOOK_RENDER_ARTICLE_API) as $p) {
					$headline_row = $p->hook_render_article_api(array("headline" => $headline_row));
				}*/

				array_push($headlines, $headline_row);
			}

			return $headlines;
	}





	function queryFeedHeadlines($feed, $limit, $view_mode, $cat_view, $search, $search_mode, $override_order = false, $offset = 0, $owner_uid = 0, $filter = false, $since_id = 0, $include_children = false, $ignore_vfeed_group = false, $override_strategy = false, $override_vfeed = false) {

		if (!$owner_uid) $owner_uid = $_SESSION["uid"];

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
					$unread = getFeedUnread($feed, $cat_view);

					if ($unread > 0)
						$view_query_part = " unread = true AND ";
				}
			}

			if ($view_mode == "marked") {
				$view_query_part = " marked = true AND ";
			}

			if ($view_mode == "has_note") {
				$view_query_part = " (note IS NOT NULL AND note != '') AND ";
			}

			if ($view_mode == "published") {
				$view_query_part = " published = true AND ";
			}

			if ($view_mode == "unread" && $feed != -6) {
				$view_query_part = " unread = true AND ";
			}

			if ($limit > 0) {
				$limit_query_part = "LIMIT " . $limit;
			}

			$allow_archived = false;

			$vfeed_query_part = "";

			// override query strategy and enable feed display when searching globally
			if (!is_numeric($feed)) {
				$query_strategy_part = "true";
				$vfeed_query_part = "(SELECT title FROM ttrss_feeds WHERE
					id = feed_id) as feed_title,";
			} else if ($feed > 0) {
				$query_strategy_part = "feed_id = '$feed'";
			} else if ($feed == 0) { // archive virtual feed
				$query_strategy_part = "feed_id IS NULL";
				$allow_archived = true;
			} else if ($feed == -1) { // starred virtual feed
				$query_strategy_part = "marked = true";
				$vfeed_query_part = "ttrss_feeds.title AS feed_title,";
				$allow_archived = true;

				if (!$override_order) {
					$override_order = "last_marked DESC, date_entered DESC, updated DESC";
				}

			} else if ($feed == -2) { // published virtual feed OR labels category

					$query_strategy_part = "published = true";
					$vfeed_query_part = "ttrss_feeds.title AS feed_title,";
					$allow_archived = true;

					if (!$override_order) {
						$override_order = "last_published DESC, date_entered DESC, updated DESC";
					}

			} else if ($feed == -6) { // recently read
				$query_strategy_part = "unread = false AND last_read IS NOT NULL";
				$vfeed_query_part = "ttrss_feeds.title AS feed_title,";
				$allow_archived = true;

				if (!$override_order) $override_order = "last_read DESC";

			} else if ($feed == -3) { // fresh virtual feed
				$query_strategy_part = "unread = true AND score >= 0";

				$intl = get_pref("FRESH_ARTICLE_MAX_AGE", $owner_uid);

				if (DB_TYPE == "pgsql") {
					$query_strategy_part .= " AND date_entered > NOW() - INTERVAL '$intl hour' ";
				} else {
					$query_strategy_part .= " AND date_entered > DATE_SUB(NOW(), INTERVAL $intl HOUR) ";
				}

				$vfeed_query_part = "ttrss_feeds.title AS feed_title,";
			} else if ($feed == -4) { // all articles virtual feed
				$allow_archived = true;
				$query_strategy_part = "true";
				$vfeed_query_part = "ttrss_feeds.title AS feed_title,";
			} else if ($feed <= LABEL_BASE_INDEX) { // labels
				$label_id = feed_to_label_id($feed);

				$query_strategy_part = "label_id = '$label_id' AND
					ttrss_labels2.id = ttrss_user_labels2.label_id AND
					ttrss_user_labels2.article_id = ref_id";

				$vfeed_query_part = "ttrss_feeds.title AS feed_title,";
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

			if ($override_strategy) {
				$query_strategy_part = $override_strategy;
			}

			if ($override_vfeed) {
				$vfeed_query_part = $override_vfeed;
			}


					if (is_numeric($feed) && $feed > 0) {
						$result = db_query("SELECT title,site_url,last_error,last_updated
							FROM ttrss_feeds WHERE id = '$feed' AND owner_uid = $owner_uid");

						$feed_site_url = db_fetch_result($result, 0, "site_url");
						$last_error = db_fetch_result($result, 0, "last_error");
						$last_updated = db_fetch_result($result, 0, "last_updated");
					}


			$content_query_part = "content, content AS content_preview, ";


			if (is_numeric($feed)) {

				if ($feed >= 0) {
					$feed_kind = "Feeds";
				} else {
					$feed_kind = "Labels";
				}

				if ($limit_query_part) {
					$offset_query_part = "OFFSET $offset";
				}

				// proper override_order applied above
				if ($vfeed_query_part && !$ignore_vfeed_group && get_pref('VFEED_GROUP_BY_FEED', $owner_uid)) {
					if (!$override_order) {
						$order_by = "ttrss_feeds.title, $order_by";
					} else {
						$order_by = "ttrss_feeds.title, $override_order";
					}
				}

				if (!$allow_archived) {
					$from_qpart = "ttrss_entries,ttrss_user_entries,ttrss_feeds$ext_tables_part";
					$feed_check_qpart = "ttrss_user_entries.feed_id = ttrss_feeds.id AND";

				} else {
					$from_qpart = "ttrss_entries$ext_tables_part,ttrss_user_entries
						LEFT JOIN ttrss_feeds ON (feed_id = ttrss_feeds.id)";
				}

				if ($vfeed_query_part)
					$vfeed_query_part .= "favicon_avg_color,";

				$query = "SELECT DISTINCT
						date_entered,
						guid,
						ttrss_entries.id,
						updated,
						always_display_enclosures,
						site_url,
						note,
						int_id,
						uuid,
						unread,feed_id,marked,published,link,last_read,orig_feed_id,
						last_marked, last_published,
						$vfeed_query_part
						author,score
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
								"note," .
								"ttrss_entries.id as id," .
								"updated," .
								"unread," .
								"feed_id," .
								"orig_feed_id," .
								"marked," .
								"link," .
								"uuid," .
								"last_read," .
								"(SELECT hide_images FROM ttrss_feeds WHERE id = feed_id) AS hide_images," .
								"last_marked, last_published, " .
								$since_id_part .
								$vfeed_query_part .
								$content_query_part .
								"score ";

				$feed_kind = "Tags";
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
				//				error_log("TAG SQL: " . $tag_sql);
				// $tag_sql = "tag_name = '$feed'";   DEFAULT way

				//				error_log("[". $select_qpart . "][" . $from_qpart . "][" .$where_qpart . "]");
				$result = db_query($select_qpart . $from_qpart . $where_qpart);
			}

			return array($result);

	}








	function wrap($status, $reply) {
		print json_encode(array("seq" => $this->seq,
			"status" => $status,
			"content" => $reply));
	} // end-function: wrap

} // end-class
?>
