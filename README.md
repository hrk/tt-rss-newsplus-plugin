# TT-RSS News+ plugin

This is a plugin for [Tiny-Tiny-RSS](http://tt-rss.org) web based news feed reader and aggregator.

It adds a new API (getCompactHeadlines) to allow (faster) two-way synchronzation between an instance of TT-RSS and the [News+](http://github.com/noinnion/newsplus/) Android app.

## API Reference

**getCompactHeadlines**

Returns a JSON-encoded list of IDs of headlines matching the input parameters.

Parameters:
 * feed_id (integer/string) - only output articles for this feed (see below)
 * limit (integer) - limits the amount of returned articles (see below)
 * skip (integer) - skip this amount of feeds first
 * view_mode (string = all_articles, unread, adaptive, marked, updated)
 * since_id (integer) - only return articles with id greater than since_id

Notes:
 * *Limit*: contrary to the standard **getHeadlines** API call, there is no hardcoded limit. If not specified, the default is set to 20.
 * *feed_id*: feeds between -10 and 0 have a special meaning.
  * 0: archived
  * -1: starred
  * -2: published
  * -3: fresh
  * -4: all articles
  * -6: recently read
  * IDs < -10: labels
  * textual feed_id: browsing by tags

## Installation

To install this plugin you can either clone the repository or download a zip file, then extract it in your own tt-rss/plugin/ directory.

You should have a new "api_newsplus" directory under plugins.

#### Configuration for a single user
Log-in to your TT-RSS instance and go into the preferences. Scroll to plugins and enable "api_newsplus". It will be listed under the *system* plugins.

#### Automatic configuration for every user
Edit your config.php and add "api_newsplus" to the list of system plugins. It will be automatically enabled for every user.
