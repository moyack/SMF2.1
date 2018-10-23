<?php

/**
 * This file contains the files necessary to display news as an XML feed.
 *
 * Simple Machines Forum (SMF)
 *
 * @package SMF
 * @author Simple Machines http://www.simplemachines.org
 * @copyright 2018 Simple Machines and individual contributors
 * @license http://www.simplemachines.org/about/smf/license.php BSD
 *
 * @version 2.1 Beta 4
 */

if (!defined('SMF'))
	die('No direct access...');

/**
 * Outputs xml data representing recent information or a profile.
 *
 * Can be passed subactions which decide what is output:
 *  'recent' for recent posts,
 *  'news' for news topics,
 *  'members' for recently registered members,
 *  'profile' for a member's profile.
 *  'posts' for a member's posts.
 *  'pms' for a member's personal messages.
 *
 * When displaying a member's profile or posts, the u parameter identifies which member. Defaults
 * to the current user's id.
 * To display a member's personal messages, the u parameter must match the id of the current user.
 *
 * Outputs can be in RSS 0.92, RSS 2, Atom, RDF, or our own custom XML format. Default is RSS 2.
 *
 * Accessed via ?action=.xml.
 *
 * Does not use any templates, sub templates, or template layers...
 * ...except when requesting all the user's own posts or PMs. Then we show a template indicating
 * our progress compiling the info. This template will auto-refresh until the all the info is
 * compiled, at which point we emit the full XML feed as a downloadable file.
 *
 * @uses Stats language file, and in special cases the Admin template and language file.
 */
function ShowXmlFeed()
{
	global $board, $board_info, $context, $scripturl, $boardurl, $txt, $modSettings, $user_info;
	global $query_this_board, $smcFunc, $forum_version, $settings, $cachedir;

	// List all the different types of data they can pull.
	$subActions = array(
		'recent' => array('getXmlRecent', 'recent-post'),
		'news' => array('getXmlNews', 'article'),
		'members' => array('getXmlMembers', 'member'),
		'profile' => array('getXmlProfile', null),
		'posts' => array('getXmlPosts', 'member-post'),
		'pms' => array('getXmlPMs', 'personal-message'),
	);

	// Easy adding of sub actions
	call_integration_hook('integrate_xmlfeeds', array(&$subActions));

	if (empty($_GET['sa']) || !isset($subActions[$_GET['sa']]))
		$_GET['sa'] = 'recent';

	// Users can always export their own profile data
	if (in_array($_GET['sa'], array('profile', 'posts', 'pms')) && !$user_info['is_guest'] && (empty($_GET['u']) || (int) $_GET['u'] == $user_info['id']))
	{
		$modSettings['xmlnews_enable'] = true;

		// Batch mode builds a whole file and then sends it all when done.
		if ($_GET['limit'] == 'all' && empty($_REQUEST['c']) && empty($_REQUEST['boards']) && empty($board))
		{
			$context['batch_mode'] = true;
			$_GET['limit'] = 50;
			unset($_GET['offset']);

			// We track our progress for greater efficiency
			$progress_file = $cachedir . '/xml-batch-' . $_GET['sa'] . '-' . $user_info['id'];
			if (file_exists($progress_file))
			{
				list($context[$_GET['sa'] . '_start'], $context['batch_prev'], $context['batch_total']) = explode(';', file_get_contents($progress_file));

				if ($context['batch_prev'] == $context['batch_total'])
					$context['batch_done'] = true;
			}
			else
				$context[$_GET['sa'] . '_start'] = 0;
		}
	}

	// If it's not enabled, die.
	if (empty($modSettings['xmlnews_enable']))
		obExit(false);

	loadLanguage('Stats');

	// Default to latest 5.  No more than 255, please.
	$_GET['limit'] = empty($_GET['limit']) || (int) $_GET['limit'] < 1 ? 5 : min((int) $_GET['limit'], 255);
	$_GET['offset'] = empty($_GET['offset']) || (int) $_GET['offset'] < 1 ? 0 : (int) $_GET['offset'];

	// Some general metadata for this feed. We'll change some of these values below.
	$feed_meta = array(
		'title' => '',
		'desc' => $txt['xml_rss_desc'],
		'author' => $context['forum_name'],
		'source' => $scripturl,
		'rights' => '© ' . date('Y') . ' ' . $context['forum_name'],
		'icon' => !empty($settings['og_image']) ? $settings['og_image'] : $boardurl . '/favicon.ico',
		'language' => !empty($txt['lang_locale']) ? str_replace("_", "-", substr($txt['lang_locale'], 0, strcspn($txt['lang_locale'], "."))) : 'en',
	);

	// Handle the cases where a board, boards, or category is asked for.
	$query_this_board = 1;
	$context['optimize_msg'] = array(
		'highest' => 'm.id_msg <= b.id_last_msg',
	);
	if (!empty($_REQUEST['c']) && empty($board))
	{
		$_REQUEST['c'] = explode(',', $_REQUEST['c']);
		foreach ($_REQUEST['c'] as $i => $c)
			$_REQUEST['c'][$i] = (int) $c;

		if (count($_REQUEST['c']) == 1)
		{
			$request = $smcFunc['db_query']('', '
				SELECT name
				FROM {db_prefix}categories
				WHERE id_cat = {int:current_category}',
				array(
					'current_category' => (int) $_REQUEST['c'][0],
				)
			);
			list ($feed_meta['title']) = $smcFunc['db_fetch_row']($request);
			$smcFunc['db_free_result']($request);

			$feed_meta['title'] = ' - ' . strip_tags($feed_meta['title']);
		}

		$request = $smcFunc['db_query']('', '
			SELECT b.id_board, b.num_posts
			FROM {db_prefix}boards AS b
			WHERE b.id_cat IN ({array_int:current_category_list})
				AND {query_see_board}',
			array(
				'current_category_list' => $_REQUEST['c'],
			)
		);
		$total_cat_posts = 0;
		$boards = array();
		while ($row = $smcFunc['db_fetch_assoc']($request))
		{
			$boards[] = $row['id_board'];
			$total_cat_posts += $row['num_posts'];
		}
		$smcFunc['db_free_result']($request);

		if (!empty($boards))
			$query_this_board = 'b.id_board IN (' . implode(', ', $boards) . ')';

		// Try to limit the number of messages we look through.
		if ($total_cat_posts > 100 && $total_cat_posts > $modSettings['totalMessages'] / 15)
			$context['optimize_msg']['lowest'] = 'm.id_msg >= ' . max(0, $modSettings['maxMsgID'] - 400 - $_GET['limit'] * 5);
	}
	elseif (!empty($_REQUEST['boards']))
	{
		$_REQUEST['boards'] = explode(',', $_REQUEST['boards']);
		foreach ($_REQUEST['boards'] as $i => $b)
			$_REQUEST['boards'][$i] = (int) $b;

		$request = $smcFunc['db_query']('', '
			SELECT b.id_board, b.num_posts, b.name
			FROM {db_prefix}boards AS b
			WHERE b.id_board IN ({array_int:board_list})
				AND {query_see_board}
			LIMIT {int:limit}',
			array(
				'board_list' => $_REQUEST['boards'],
				'limit' => count($_REQUEST['boards']),
			)
		);

		// Either the board specified doesn't exist or you have no access.
		$num_boards = $smcFunc['db_num_rows']($request);
		if ($num_boards == 0)
			fatal_lang_error('no_board');

		$total_posts = 0;
		$boards = array();
		while ($row = $smcFunc['db_fetch_assoc']($request))
		{
			if ($num_boards == 1)
				$feed_meta['title'] = ' - ' . strip_tags($row['name']);

			$boards[] = $row['id_board'];
			$total_posts += $row['num_posts'];
		}
		$smcFunc['db_free_result']($request);

		if (!empty($boards))
			$query_this_board = 'b.id_board IN (' . implode(', ', $boards) . ')';

		// The more boards, the more we're going to look through...
		if ($total_posts > 100 && $total_posts > $modSettings['totalMessages'] / 12)
			$context['optimize_msg']['lowest'] = 'm.id_msg >= ' . max(0, $modSettings['maxMsgID'] - 500 - $_GET['limit'] * 5);
	}
	elseif (!empty($board))
	{
		$request = $smcFunc['db_query']('', '
			SELECT num_posts
			FROM {db_prefix}boards
			WHERE id_board = {int:current_board}
			LIMIT 1',
			array(
				'current_board' => $board,
			)
		);
		list ($total_posts) = $smcFunc['db_fetch_row']($request);
		$smcFunc['db_free_result']($request);

		$feed_meta['title'] = ' - ' . strip_tags($board_info['name']);
		$feed_meta['source'] .= '?board=' . $board . '.0' ;

		$query_this_board = 'b.id_board = ' . $board;

		// Try to look through just a few messages, if at all possible.
		if ($total_posts > 80 && $total_posts > $modSettings['totalMessages'] / 10)
			$context['optimize_msg']['lowest'] = 'm.id_msg >= ' . max(0, $modSettings['maxMsgID'] - 600 - $_GET['limit'] * 5);
	}
	else
	{
		$query_this_board = '{query_see_board}' . (!empty($modSettings['recycle_enable']) && $modSettings['recycle_board'] > 0 ? '
			AND b.id_board != ' . $modSettings['recycle_board'] : '');
		$context['optimize_msg']['lowest'] = 'm.id_msg >= ' . max(0, $modSettings['maxMsgID'] - 100 - $_GET['limit'] * 5);
	}

	// Show in rss or proprietary format?
	$xml_format = isset($_GET['type']) && in_array($_GET['type'], array('smf', 'rss', 'rss2', 'atom', 'rdf')) ? $_GET['type'] : 'rss2';

	// We only want some information, not all of it.
	$cachekey = array($xml_format, $_GET['action'], $_GET['limit'], $_GET['sa'], $_GET['offset']);
	foreach (array('board', 'boards', 'c') as $var)
		if (isset($_REQUEST[$var]))
			$cachekey[] = $var . '=' . $_REQUEST[$var];
	$cachekey = md5($smcFunc['json_encode']($cachekey) . (!empty($query_this_board) ? $query_this_board : ''));
	$cache_t = microtime();

	// Get the associative array representing the xml.
	if (!empty($modSettings['cache_enable']) && (!$user_info['is_guest'] || $modSettings['cache_enable'] >= 3))
		$xml_data = cache_get_data('xmlfeed-' . $xml_format . ':' . ($user_info['is_guest'] ? '' : $user_info['id'] . '-') . $cachekey, 240);
	if (empty($xml_data))
	{
		$call = call_helper($subActions[$_GET['sa']][0], true);

		if (!empty($call))
			$xml_data = call_user_func($call, $xml_format);

		if (!empty($modSettings['cache_enable']) && (($user_info['is_guest'] && $modSettings['cache_enable'] >= 3)
		|| (!$user_info['is_guest'] && (array_sum(explode(' ', microtime())) - array_sum(explode(' ', $cache_t)) > 0.2))))
			cache_put_data('xmlfeed-' . $xml_format . ':' . ($user_info['is_guest'] ? '' : $user_info['id'] . '-') . $cachekey, $xml_data, 240);
	}

	$feed_meta['title'] = $smcFunc['htmlspecialchars'](strip_tags($context['forum_name'])) . (isset($feed_meta['title']) ? $feed_meta['title'] : '');

	// Allow mods to add extra namespaces and tags to the feed/channel
	$namespaces = array(
		'rss' => array(),
		'rss2' => array('atom' => 'http://www.w3.org/2005/Atom'),
		'atom' => array('' => 'http://www.w3.org/2005/Atom'),
		'rdf' => array(
			'' => 'http://purl.org/rss/1.0/',
			'rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
			'dc' => 'http://purl.org/dc/elements/1.1/',
		),
		'smf' => array(
			'' => 'http://www.simplemachines.org/xml/' . $_GET['sa'],
			'smf' => 'http://www.simplemachines.org/',
		),
	);
	if ($_GET['sa'] == 'pms')
	{
		$namespaces['rss']['smf'] = 'http://www.simplemachines.org/';
		$namespaces['rss2']['smf'] = 'http://www.simplemachines.org/';
		$namespaces['atom']['smf'] = 'http://www.simplemachines.org/';
	}

	$extraFeedTags = array(
		'rss' => array(),
		'rss2' => array(),
		'atom' => array(),
		'rdf' => array(),
		'smf' => array(),
	);

	// Allow mods to specify any keys that need special handling
	$forceCdataKeys = array();
	$nsKeys = array();

	// Remember this, just in case...
	$orig_feed_meta = $feed_meta;

	// If mods want to do somthing with this feed, let them do that now.
	// Provide the feed's data, metadata, namespaces, extra feed-level tags, keys that need special handling, the feed format, and the requested subaction
	call_integration_hook('integrate_xml_data', array(&$xml_data, &$feed_meta, &$namespaces, &$extraFeedTags, &$forceCdataKeys, &$nsKeys, $xml_format, $_GET['sa']));

	// These can't be empty
	foreach (array('title', 'desc', 'source') as $mkey)
		$feed_meta[$mkey] = !empty($feed_meta[$mkey]) ? $feed_meta[$mkey] : $orig_feed_meta[$mkey];

	// Sanitize basic feed metadata values
	foreach ($feed_meta as $mkey => $mvalue)
		$feed_meta[$mkey] = cdata_parse(strip_tags(fix_possible_url($feed_meta[$mkey])));

	$ns_string = '';
	if (!empty($namespaces[$xml_format]))
	{
		foreach ($namespaces[$xml_format] as $nsprefix => $nsurl)
			$ns_string .= ' xmlns' . ($nsprefix !== '' ? ':' : '') . $nsprefix . '="' . $nsurl . '"';
	}

	$extraFeedTags_string = '';
	if (!empty($extraFeedTags[$xml_format]))
	{
		$indent = "\t" . ($xml_format !== 'atom' ? "\t" : '');
		foreach ($extraFeedTags[$xml_format] as $extraTag)
			$extraFeedTags_string .= "\n" . $indent . $extraTag;
	}

	// Descriptive filenames = good
	$xml_filename[] = preg_replace('/\s+/', '_', $feed_meta['title']);
	$xml_filename[] = $_GET['sa'];
	if (in_array($_GET['sa'], array('profile', 'posts', 'pms')))
		$xml_filename[] = 'u=' . (isset($_GET['u']) ? (int) $_GET['u'] : $user_info['id']);
	if (!empty($boards))
		$xml_filename[] = 'boards=' . implode(',', $boards);
	elseif (!empty($board))
		$xml_filename[] = 'board=' . $board;
	$xml_filename[] = $xml_format;
	$xml_filename = strtr(un_htmlspecialchars(implode('-', $xml_filename)), '"', '') ;

	// First, output the xml header.
	$context['feed']['header'] = '<?xml version="1.0" encoding="' . $context['character_set'] . '"?' . '>';

	// Are we outputting an rss feed or one with more information?
	if ($xml_format == 'rss' || $xml_format == 'rss2')
	{
		if ($xml_format == 'rss2')
			foreach ($_REQUEST as $var => $val)
				if (in_array($var, array('action', 'sa', 'type', 'board', 'boards', 'c', 'u', 'limit')))
					$url_parts[] = $var . '=' . (is_array($val) ? implode(',', $val) : $val);

		// Start with an RSS 2.0 header.
		$context['feed']['header'] .= '
<rss version=' . ($xml_format == 'rss2' ? '"2.0"' : '"0.92"') . ' xml:lang="' . strtr($txt['lang_locale'], '_', '-') . '"' . $ns_string . '>
	<channel>
		<title>' . $feed_meta['title'] . '</title>
		<link>' . $feed_meta['source'] . '</link>
		<description>' . $feed_meta['desc'] . '</description>';

		if (!empty($feed_meta['icon']))
			$context['feed']['header'] .= '
		<image>
			<url>' . $feed_meta['icon'] . '</url>
			<title>' . $feed_meta['title'] . '</title>
			<link>' . $feed_meta['source'] . '</link>
		</image>';

		if (!empty($feed_meta['rights']))
			$context['feed']['header'] .= '
		<copyright>' . $feed_meta['rights'] . '</copyright>';

		if (!empty($feed_meta['language']))
			$context['feed']['header'] .= '
		<language>' . $feed_meta['language'] . '</language>';

		// RSS2 calls for this.
		if ($xml_format == 'rss2')
			$context['feed']['header'] .= '
		<atom:link rel="self" type="application/rss+xml" href="' . $scripturl . (!empty($url_parts) ? '?' . implode(';', $url_parts) : '') . '" />';

		$context['feed']['header'] .= $extraFeedTags_string;

		// Output all of the associative array, start indenting with 2 tabs, and name everything "item".
		dumpTags($xml_data, 2, null, $xml_format, $forceCdataKeys, $nsKeys);

		// Output the footer of the xml.
		$context['feed']['footer'] = '
	</channel>
</rss>';
	}
	elseif ($xml_format == 'atom')
	{
		foreach ($_REQUEST as $var => $val)
			if (in_array($var, array('action', 'sa', 'type', 'board', 'boards', 'c', 'u', 'limit', 'offset')))
				$url_parts[] = $var . '=' . (is_array($val) ? implode(',', $val) : $val);

		$context['feed']['header'] .= '
<feed' . $ns_string . (!empty($feed_meta['language']) ? ' xml:lang="' . $feed_meta['language'] . '"' : '') . '>
	<title>' . $feed_meta['title'] . '</title>
	<link rel="alternate" type="text/html" href="' . $feed_meta['source'] . '" />
	<link rel="self" type="application/atom+xml" href="' . $scripturl . (!empty($url_parts) ? '?' . implode(';', $url_parts) : '') . '" />
	<updated>' . gmstrftime('%Y-%m-%dT%H:%M:%SZ') . '</updated>
	<id>' . $feed_meta['source'] . '</id>
	<subtitle>' . $feed_meta['desc'] . '</subtitle>
	<generator uri="https://www.simplemachines.org" version="' . trim(strtr($forum_version, array('SMF' => ''))) . '">SMF</generator>';

		if (!empty($feed_meta['icon']))
			$context['feed']['header'] .= '
	<icon>' . $feed_meta['icon'] . '</icon>';

		if (!empty($feed_meta['author']))
			$context['feed']['header'] .= '
	<author>
		<name>' . $feed_meta['author'] . '</name>
	</author>';

		if (!empty($feed_meta['rights']))
			$context['feed']['header'] .= '
	<rights>' . $feed_meta['rights'] . '</rights>';

		$context['feed']['header'] .= $extraFeedTags_string;

		dumpTags($xml_data, 1, null, $xml_format, $forceCdataKeys, $nsKeys);

		$context['feed']['footer'] = '
</feed>';
	}
	elseif ($xml_format == 'rdf')
	{
		$context['feed']['header'] .= '
<rdf:RDF' . $ns_string . '>
	<channel rdf:about="' . $scripturl . '">
		<title>' . $feed_meta['title'] . '</title>
		<link>' . $feed_meta['source'] . '</link>
		<description>' . $feed_meta['desc'] . '</description>';

		$context['feed']['header'] .= $extraFeedTags_string;

		$context['feed']['header'] .= '
		<items>
			<rdf:Seq>';

		foreach ($xml_data as $item)
		{
			$link = array_filter($item['content'], function ($e) { return ($e['tag'] == 'link'); });
			$link = array_pop($link);

			$context['feed']['header'] .= '
				<rdf:li rdf:resource="' . $link['content'] . '" />';
		}

		$context['feed']['header'] .= '
			</rdf:Seq>
		</items>
	</channel>';

		dumpTags($xml_data, 1, null, $xml_format, $forceCdataKeys, $nsKeys);

		$context['feed']['footer'] = '
</rdf:RDF>';
	}
	// Otherwise, we're using our proprietary formats - they give more data, though.
	else
	{
		$context['feed']['header'] .= '
<smf:xml-feed xml:lang="' . strtr($txt['lang_locale'], '_', '-') . '"' . $ns_string . '>';

		// Hard to imagine anyone wanting to add these for the proprietary format, but just in case...
		$context['feed']['header'] .= $extraFeedTags_string;

		// Dump out that associative array.  Indent properly.... and use the right names for the base elements.
		dumpTags($xml_data, 1, $subActions[$_GET['sa']][1], $xml_format, $forceCdataKeys, $nsKeys);

		$context['feed']['footer'] = '
</smf:xml-feed>';
	}

	// Batch mode involves a lot of reading and writing to a temporary file
	if (!empty($context['batch_mode']))
	{
		$xml_filepath = $cachedir . '/' . $xml_filename . '.xml';

		// Append our current items to the output file
		if (file_exists($xml_filepath))
		{
			$handle = fopen($xml_filepath, 'r+');

			// Trim off the existing feed footer
			ftruncate($handle, filesize($xml_filepath) - strlen($context['feed']['footer']));

			// Add the new data
			fseek($handle, 0, SEEK_END);
			fwrite($handle, $context['feed']['items']);
			fwrite($handle, $context['feed']['footer']);

			fclose($handle);
		}
		else
			file_put_contents($xml_filepath, implode('', $context['feed']));

		if (!empty($context['batch_done']))
		{
			if (file_exists($xml_filepath))
				$feed = file_get_contents($xml_filepath);
			else
				$feed = implode('', $context['feed']);

			$_REQUEST['download'] = true;
			unlink($progress_file);
			unlink($xml_filepath);
		}
		else
		{
			// This shouldn't interfere with normal feed reader operation, because the only way this
			// can happen is when the user is logged into their account, which isn't possible when
			// connecting via any normal feed reader.
			loadTemplate('Admin');
			loadLanguage('Admin');
			$context['sub_template'] = 'not_done';
			$context['continue_post_data'] = '';
			$context['continue_countdown'] = 3;
			$context['continue_percent'] = number_format(($context['batch_prev'] / $context['batch_total']) * 100, 1);
			$context['continue_get_data'] = '?action=' . $_REQUEST['action'] . ';sa=' . $_GET['sa'] . ';type=' . $xml_format . (!empty($_GET['u']) ? ';u=' . $_GET['u'] : '') . ';limit=all';

			if ($context['batch_prev'] == $context['batch_total'])
			{
				$context['continue_countdown'] = 1;
				$context['continue_post_data'] = '
					<script>
						var x = document.getElementsByName("cont");
						var i;
						for (i = 0; i < x.length; i++) {
							x[i].disabled = true;
						}
					</script>';
			}
		}
	}
	// Keepin' it simple...
	else
		$feed = implode('', $context['feed']);

	if (!empty($feed))
	{
		// This is an xml file....
		ob_end_clean();
		if (!empty($modSettings['enableCompressedOutput']))
			@ob_start('ob_gzhandler');
		else
			ob_start();

		if ($xml_format == 'smf' || isset($_REQUEST['debug']))
			header('content-type: text/xml; charset=' . (empty($context['character_set']) ? 'UTF-8' : $context['character_set']));
		elseif ($xml_format == 'rss' || $xml_format == 'rss2')
			header('content-type: application/rss+xml; charset=' . (empty($context['character_set']) ? 'UTF-8' : $context['character_set']));
		elseif ($xml_format == 'atom')
			header('content-type: application/atom+xml; charset=' . (empty($context['character_set']) ? 'UTF-8' : $context['character_set']));
		elseif ($xml_format == 'rdf')
			header('content-type: ' . (isBrowser('ie') ? 'text/xml' : 'application/rdf+xml') . '; charset=' . (empty($context['character_set']) ? 'UTF-8' : $context['character_set']));

		header('content-disposition: ' . (isset($_REQUEST['download']) ? 'attachment' : 'inline') . '; filename="' . $xml_filename . '.xml"');

		echo $feed;

		obExit(false);
	}
}

/**
 * Called from dumpTags to convert data to xml
 * Finds urls for local site and sanitizes them
 *
 * @param string $val A string containing a possible URL
 * @return string $val The string with any possible URLs sanitized
 */
function fix_possible_url($val)
{
	global $modSettings, $context, $scripturl;

	if (substr($val, 0, strlen($scripturl)) != $scripturl)
		return $val;

	call_integration_hook('integrate_fix_url', array(&$val));

	if (empty($modSettings['queryless_urls']) || ($context['server']['is_cgi'] && ini_get('cgi.fix_pathinfo') == 0 && @get_cfg_var('cgi.fix_pathinfo') == 0) || (!$context['server']['is_apache'] && !$context['server']['is_lighttpd']))
		return $val;

	$val = preg_replace_callback('~\b' . preg_quote($scripturl, '~') . '\?((?:board|topic)=[^#"]+)(#[^"]*)?$~', function($m) use ($scripturl)
		{
			return $scripturl . '/' . strtr("$m[1]", '&;=', '//,') . '.html' . (isset($m[2]) ? $m[2] : "");
		}, $val);
	return $val;
}

/**
 * Ensures supplied data is properly encapsulated in cdata xml tags
 * Called from getXmlProfile in News.php
 *
 * @param string $data XML data
 * @param string $ns A namespace prefix for the XML data elements (used by mods, maybe)
 * @param boolean $force If true, enclose the XML data in cdata tags no matter what (used by mods, maybe)
 * @return string The XML data enclosed in cdata tags when necessary
 */
function cdata_parse($data, $ns = '', $force = false)
{
	global $smcFunc;

	// Do we even need to do this?
	if (strpbrk($data, '<>&') == false && $force !== true)
		return $data;

	$cdata = '<![CDATA[';

	// @todo If we drop the obsolete $ns parameter, this whole loop could be replaced with a simple `str_replace(']]>', ']]]]><[CDATA[>', $data)`

	for ($pos = 0, $n = $smcFunc['strlen']($data); $pos < $n; null)
	{
		$positions = array(
			$smcFunc['strpos']($data, '&', $pos),
			$smcFunc['strpos']($data, ']]>', $pos),
		);
		if ($ns != '')
			$positions[] = $smcFunc['strpos']($data, '<', $pos);
		foreach ($positions as $k => $dummy)
		{
			if ($dummy === false)
				unset($positions[$k]);
		}

		$old = $pos;
		$pos = empty($positions) ? $n : min($positions);

		if ($pos - $old > 0)
			$cdata .= $smcFunc['substr']($data, $old, $pos - $old);
		if ($pos >= $n)
			break;

		if ($smcFunc['substr']($data, $pos, 1) == '<')
		{
			$pos2 = $smcFunc['strpos']($data, '>', $pos);
			if ($pos2 === false)
				$pos2 = $n;
			if ($smcFunc['substr']($data, $pos + 1, 1) == '/')
				$cdata .= ']]></' . $ns . ':' . $smcFunc['substr']($data, $pos + 2, $pos2 - $pos - 1) . '<![CDATA[';
			else
				$cdata .= ']]><' . $ns . ':' . $smcFunc['substr']($data, $pos + 1, $pos2 - $pos) . '<![CDATA[';
			$pos = $pos2 + 1;
		}
		elseif ($smcFunc['substr']($data, $pos, 3) == ']]>')
		{
			$cdata .= ']]]]><![CDATA[>';
			$pos = $pos + 3;
		}
		elseif ($smcFunc['substr']($data, $pos, 1) == '&')
		{
			$pos2 = $smcFunc['strpos']($data, ';', $pos);
			if ($pos2 === false)
				$pos2 = $n;
			$ent = $smcFunc['substr']($data, $pos + 1, $pos2 - $pos - 1);

			if ($smcFunc['substr']($data, $pos + 1, 1) == '#')
				$cdata .= ']]>' . $smcFunc['substr']($data, $pos, $pos2 - $pos + 1) . '<![CDATA[';
			elseif (in_array($ent, array('amp', 'lt', 'gt', 'quot')))
				$cdata .= ']]>' . $smcFunc['substr']($data, $pos, $pos2 - $pos + 1) . '<![CDATA[';

			$pos = $pos2 + 1;
		}
	}

	$cdata .= ']]>';

	return strtr($cdata, array('<![CDATA[]]>' => ''));
}

/**
 * Formats data retrieved in other functions into xml format.
 * Additionally formats data based on the specific format passed.
 * This function is recursively called to handle sub arrays of data.
 *
 * @param array $data The array to output as xml data
 * @param int $i The amount of indentation to use.
 * @param null|string $tag
 * @param string $xml_format The format to use ('atom', 'rss', 'rss2' or empty for plain XML)
 * @param array $forceCdataKeys A list of keys on which to force cdata wrapping (used by mods, maybe)
 * @param array $nsKeys Key-value pairs of namespace prefixes to pass to cdata_parse() (used by mods, maybe)
 */
function dumpTags($data, $i, $tag = null, $xml_format = '', $forceCdataKeys = array(), $nsKeys = array())
{
	global $context;

	if (empty($context['feed']['items']))
		$context['feed']['items'] = '';

	// For every array in the data...
	foreach ($data as $element)
	{
		// If a tag was passed, use it instead of the key.
		$key = isset($tag) ? $tag : (isset($element['tag']) ? $element['tag'] : null);
		$val = isset($element['content']) ? $element['content'] : null;
		$attrs = isset($element['attributes']) ? $element['attributes'] : null;

		// Skip it, it's been set to null.
		if ($key === null || ($val === null && $attrs === null))
			continue;

		$forceCdata = in_array($key, $forceCdataKeys);
		$ns = !empty($nsKeys[$key]) ? $nsKeys[$key] : '';

		// First let's indent!
		$context['feed']['items'] .= "\n" . str_repeat("\t", $i);

		// Beginning tag.
		$context['feed']['items'] .= '<' . $key;

		if (!empty($attrs))
		{
			foreach ($attrs as $attr_key => $attr_value)
				$context['feed']['items'] .= ' ' . $attr_key . '="' . fix_possible_url($attr_value) . '"';
		}

		// If it's empty, simply output an empty element.
		if (empty($val) && $val !== '0' && $val !== 0)
		{
			$context['feed']['items'] .= ' />';
		}
		else
		{
			$context['feed']['items'] .= '>';

			// The element's value.
			if (is_array($val))
			{
				// An array.  Dump it, and then indent the tag.
				dumpTags($val, $i + 1, null, $xml_format, $forceCdataKeys, $nsKeys);
				$context['feed']['items'] .= "\n" . str_repeat("\t", $i);
			}
			// A string with returns in it.... show this as a multiline element.
			elseif (strpos($val, "\n") !== false)
				$context['feed']['items'] .= "\n" . (!empty($element['cdata']) || $forceCdata ? cdata_parse(fix_possible_url($val), $ns, $forceCdata) : fix_possible_url($val)) . "\n" . str_repeat("\t", $i);
			// A simple string.
			else
				$context['feed']['items'] .= !empty($element['cdata']) || $forceCdata ? cdata_parse(fix_possible_url($val), $ns, $forceCdata) : fix_possible_url($val);

			// Ending tag.
			$context['feed']['items'] .= '</' . $key . '>';
		}
	}
}

/**
 * Retrieve the list of members from database.
 * The array will be generated to match the format.
 * @todo get the list of members from Subs-Members.
 *
 * @param string $xml_format The format to use. Can be 'atom', 'rdf', 'rss', 'rss2' or 'smf'
 * @return array An array of arrays of feed items. Each array has keys corresponding to the appropriate tags for the specified format.
 */
function getXmlMembers($xml_format)
{
	global $scripturl, $smcFunc, $txt, $context;

	if (!allowedTo('view_mlist'))
		return array();

	loadLanguage('Profile');

	// Find the most recent members.
	$request = $smcFunc['db_query']('', '
		SELECT id_member, member_name, real_name, date_registered, last_login
		FROM {db_prefix}members
		ORDER BY id_member DESC
		LIMIT {int:limit} OFFSET {int:offset}',
		array(
			'limit' => $_GET['limit'],
			'offset' => $_GET['offset'],
		)
	);
	$data = array();
	while ($row = $smcFunc['db_fetch_assoc']($request))
	{
		// If any control characters slipped in somehow, kill the evil things
		$row = preg_replace($context['utf8'] ? '/\pCc*/u' : '/[\x00-\x1F\x7F]*/', '', $row);

		// Create a GUID for each member using the tag URI scheme
		$guid = 'tag:' . parse_url($scripturl, PHP_URL_HOST) . ',' . gmdate('Y-m-d', $row['date_registered']) . ':member=' . $row['id_member'];

		// Make the data look rss-ish.
		if ($xml_format == 'rss' || $xml_format == 'rss2')
			$data[] = array(
				'tag' => 'item',
				'content' => array(
					array(
						'tag' => 'title',
						'content' => $row['real_name'],
						'cdata' => true,
					),
					array(
						'tag' => 'link',
						'content' => $scripturl . '?action=profile;u=' . $row['id_member'],
					),
					array(
						'tag' => 'comments',
						'content' => $scripturl . '?action=pm;sa=send;u=' . $row['id_member'],
					),
					array(
						'tag' => 'pubDate',
						'content' => gmdate('D, d M Y H:i:s \G\M\T', $row['date_registered']),
					),
					array(
						'tag' => 'guid',
						'content' => $guid,
						'attributes' => array(
							'isPermaLink' => 'false',
						),
					),
				),
			);
		elseif ($xml_format == 'rdf')
			$data[] = array(
				'tag' => 'item',
				'attributes' => array('rdf:about' => $scripturl . '?action=profile;u=' . $row['id_member']),
				'content' => array(
					array(
						'tag' => 'dc:format',
						'content' => 'text/html',
					),
					array(
						'tag' => 'title',
						'content' => $row['real_name'],
						'cdata' => true,
					),
					array(
						'tag' => 'link',
						'content' => $scripturl . '?action=profile;u=' . $row['id_member'],
					),
				),
			);
		elseif ($xml_format == 'atom')
			$data[] = array(
				'tag' => 'entry',
				'content' => array(
					array(
						'tag' => 'title',
						'content' => $row['real_name'],
						'cdata' => true,
					),
					array(
						'tag' => 'link',
						'attributes' => array(
							'rel' => 'alternate',
							'type' => 'text/html',
							'href' => $scripturl . '?action=profile;u=' . $row['id_member'],
						),
					),
					array(
						'tag' => 'published',
						'content' => gmstrftime('%Y-%m-%dT%H:%M:%SZ', $row['date_registered']),
					),
					array(
						'tag' => 'updated',
						'content' => gmstrftime('%Y-%m-%dT%H:%M:%SZ', $row['last_login']),
					),
					array(
						'tag' => 'id',
						'content' => $guid,
					),
				),
			);
		// More logical format for the data, but harder to apply.
		else
			$data[] = array(
				'tag' => 'member',
				'attributes' => array('title' => $txt['who_member']),
				'content' => array(
					array(
						'tag' => 'name',
						'attributes' => array('title' => $txt['name']),
						'content' => $row['real_name'],
						'cdata' => true,
					),
					array(
						'tag' => 'time',
						'attributes' => array('title' => $txt['date_registered']),
						'content' => $smcFunc['htmlspecialchars'](strip_tags(timeformat($row['date_registered']))),
					),
					array(
						'tag' => 'id',
						'content' => $row['id_member'],
					),
					array(
						'tag' => 'link',
						'attributes' => array('title' => $txt['url']),
						'content' => $scripturl . '?action=profile;u=' . $row['id_member'],
					),
				),
			);
	}
	$smcFunc['db_free_result']($request);

	return $data;
}

/**
 * Get the latest topics information from a specific board,
 * to display later.
 * The returned array will be generated to match the xml_format.
 * @todo does not belong here
 *
 * @param string $xml_format The XML format. Can be 'atom', 'rdf', 'rss', 'rss2' or 'smf'.
 * @return array An array of arrays of topic data for the feed. Each array has keys corresponding to the tags for the specified format.
 */
function getXmlNews($xml_format)
{
	global $scripturl, $modSettings, $board, $user_info;
	global $query_this_board, $smcFunc, $context, $txt;

	/* Find the latest posts that:
		- are the first post in their topic.
		- are on an any board OR in a specified board.
		- can be seen by this user.
		- are actually the latest posts. */

	$done = false;
	$loops = 0;
	while (!$done)
	{
		$optimize_msg = implode(' AND ', $context['optimize_msg']);
		$request = $smcFunc['db_query']('', '
			SELECT
				m.smileys_enabled, m.poster_time, m.id_msg, m.subject, m.body, m.modified_time,
				m.icon, t.id_topic, t.id_board, t.num_replies,
				b.name AS bname,
				COALESCE(mem.id_member, 0) AS id_member,
				COALESCE(mem.email_address, m.poster_email) AS poster_email,
				COALESCE(mem.real_name, m.poster_name) AS poster_name
			FROM {db_prefix}topics AS t
				INNER JOIN {db_prefix}messages AS m ON (m.id_msg = t.id_first_msg)
				INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)
				LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = m.id_member)
			WHERE ' . $query_this_board . (empty($optimize_msg) ? '' : '
				AND {raw:optimize_msg}') . (empty($board) ? '' : '
				AND t.id_board = {int:current_board}') . ($modSettings['postmod_active'] ? '
				AND t.approved = {int:is_approved}' : '') . '
			ORDER BY t.id_first_msg DESC
			LIMIT {int:limit} OFFSET {int:offset}',
			array(
				'current_board' => $board,
				'is_approved' => 1,
				'limit' => $_GET['limit'],
				'offset' => $_GET['offset'],
				'optimize_msg' => $optimize_msg,
			)
		);
		// If we don't have $_GET['limit'] results, try again with an unoptimized version covering all rows.
		if ($loops < 2 && $smcFunc['db_num_rows']($request) < $_GET['limit'])
		{
			$smcFunc['db_free_result']($request);
			if (empty($_REQUEST['boards']) && empty($board))
				unset($context['optimize_msg']['lowest']);
			else
				$context['optimize_msg']['lowest'] = 'm.id_msg >= t.id_first_msg';
			$context['optimize_msg']['highest'] = 'm.id_msg <= t.id_last_msg';
			$loops++;
		}
		else
			$done = true;
	}
	$data = array();
	while ($row = $smcFunc['db_fetch_assoc']($request))
	{
		// If any control characters slipped in somehow, kill the evil things
		$row = preg_replace($context['utf8'] ? '/\pCc*/u' : '/[\x00-\x1F\x7F]*/', '', $row);

		// Limit the length of the message, if the option is set.
		if (!empty($modSettings['xmlnews_maxlen']) && $smcFunc['strlen'](str_replace('<br>', "\n", $row['body'])) > $modSettings['xmlnews_maxlen'])
			$row['body'] = strtr($smcFunc['substr'](str_replace('<br>', "\n", $row['body']), 0, $modSettings['xmlnews_maxlen'] - 3), array("\n" => '<br>')) . '...';

		$row['body'] = parse_bbc($row['body'], $row['smileys_enabled'], $row['id_msg']);

		censorText($row['body']);
		censorText($row['subject']);

		// Do we want to include any attachments?
		if (!empty($modSettings['attachmentEnable']) && !empty($modSettings['xmlnews_attachments']) && allowedTo('view_attachments', $row['id_board']))
		{
			$attach_request = $smcFunc['db_query']('', '
				SELECT
					a.id_attach, a.filename, COALESCE(a.size, 0) AS filesize, a.mime_type, a.downloads, a.approved, m.id_topic AS topic
				FROM {db_prefix}attachments AS a
					LEFT JOIN {db_prefix}messages AS m ON (m.id_msg = a.id_msg)
				WHERE a.attachment_type = {int:attachment_type}
					AND a.id_msg = {int:message_id}',
				array(
					'message_id' => $row['id_msg'],
					'attachment_type' => 0,
					'is_approved' => 1,
				)
			);
			$loaded_attachments = array();
			while ($attach = $smcFunc['db_fetch_assoc']($attach_request))
			{
				// Include approved attachments only
				if ($attach['approved'])
					$loaded_attachments['attachment_' . $attach['id_attach']] = $attach;
			}
			$smcFunc['db_free_result']($attach_request);

			// Sort the attachments by size to make things easier below
			if (!empty($loaded_attachments))
			{
				uasort($loaded_attachments, function($a, $b) {
					if ($a['filesize'] == $b['filesize'])
					        return 0;
					return ($a['filesize'] < $b['filesize']) ? -1 : 1;
				});
			}
			else
				$loaded_attachments = null;
		}
		else
			$loaded_attachments = null;

		// Create a GUID for this topic using the tag URI scheme
		$guid = 'tag:' . parse_url($scripturl, PHP_URL_HOST) . ',' . gmdate('Y-m-d', $row['poster_time']) . ':topic=' . $row['id_topic'];

		// Being news, this actually makes sense in rss format.
		if ($xml_format == 'rss' || $xml_format == 'rss2')
		{
			// Only one attachment allowed in RSS.
			if ($loaded_attachments !== null)
			{
				$attachment = array_pop($loaded_attachments);
				$enclosure = array(
					'url' => fix_possible_url($scripturl . '?action=dlattach;topic=' . $attachment['topic'] . '.0;attach=' . $attachment['id_attach']),
					'length' => $attachment['filesize'],
					'type' => $attachment['mime_type'],
				);
			}
			else
				$enclosure = null;

			$data[] = array(
				'tag' => 'item',
				'content' => array(
					array(
						'tag' => 'title',
						'content' => $row['subject'],
						'cdata' => true,
					),
					array(
						'tag' => 'link',
						'content' => $scripturl . '?topic=' . $row['id_topic'] . '.0',
					),
					array(
						'tag' => 'description',
						'content' => $row['body'],
						'cdata' => true,
					),
					array(
						'tag' => 'author',
						'content' => (allowedTo('moderate_forum') || $row['id_member'] == $user_info['id']) ? $row['poster_email'] . ' (' . $row['poster_name'] . ')' : null,
					),
					array(
						'tag' => 'comments',
						'content' => $scripturl . '?action=post;topic=' . $row['id_topic'] . '.0',
					),
					array(
						'tag' => 'category',
						'content' => $row['bname'],
					),
					array(
						'tag' => 'pubDate',
						'content' => gmdate('D, d M Y H:i:s \G\M\T', $row['poster_time']),
					),
					array(
						'tag' => 'guid',
						'content' => $guid,
						'attributes' => array(
							'isPermaLink' => 'false',
						),
					),
					array(
						'tag' => 'enclosure',
						'attributes' => $enclosure,
					),
				),
			);
		}
		elseif ($xml_format == 'rdf')
		{
			$data[] = array(
				'tag' => 'item',
				'attributes' => array('rdf:about' => $scripturl . '?topic=' . $row['id_topic'] . '.0'),
				'content' => array(
					array(
						'tag' => 'dc:format',
						'content' => 'text/html',
					),
					array(
						'tag' => 'title',
						'content' => $row['subject'],
						'cdata' => true,
					),
					array(
						'tag' => 'link',
						'content' => $scripturl . '?topic=' . $row['id_topic'] . '.0',
					),
					array(
						'tag' => 'description',
						'content' => $row['body'],
						'cdata' => true,
					),
				),
			);
		}
		elseif ($xml_format == 'atom')
		{
			// Only one attachment allowed
			if (!empty($loaded_attachments))
			{
				$attachment = array_pop($loaded_attachments);
				$enclosure = array(
					'rel' => 'enclosure',
					'href' => fix_possible_url($scripturl . '?action=dlattach;topic=' . $attachment['topic'] . '.0;attach=' . $attachment['id_attach']),
					'length' => $attachment['filesize'],
					'type' => $attachment['mime_type'],
				);
			}
			else
				$enclosure = null;

			$data[] = array(
				'tag' => 'entry',
				'content' => array(
					array(
						'tag' => 'title',
						'content' => $row['subject'],
						'cdata' => true,
					),
					array(
						'tag' => 'link',
						'attributes' => array(
							'rel' => 'alternate',
							'type' => 'text/html',
							'href' => $scripturl . '?topic=' . $row['id_topic'] . '.0',
						),
					),
					array(
						'tag' => 'summary',
						'attributes' => array('type' => 'html'),
						'content' => $row['body'],
						'cdata' => true,
					),
					array(
						'tag' => 'category',
						'attributes' => array('term' => $row['bname']),
					),
					array(
						'tag' => 'author',
						'content' => array(
							array(
								'tag' => 'name',
								'content' => $row['poster_name'],
								'cdata' => true,
							),
							array(
								'tag' => 'email',
								'content' => (allowedTo('moderate_forum') || $row['id_member'] == $user_info['id']) ? $row['poster_email'] : null,
							),
							array(
								'tag' => 'uri',
								'content' => !empty($row['id_member']) ? $scripturl . '?action=profile;u=' . $row['id_member'] : null,
							),
						)
					),
					array(
						'tag' => 'published',
						'content' => gmstrftime('%Y-%m-%dT%H:%M:%SZ', $row['poster_time']),
					),
					array(
						'tag' => 'updated',
						'content' => gmstrftime('%Y-%m-%dT%H:%M:%SZ', empty($row['modified_time']) ? $row['poster_time'] : $row['modified_time']),
					),
					array(
						'tag' => 'id',
						'content' => $guid,
					),
					array(
						'tag' => 'link',
						'attributes' => $enclosure,
					),
				),
			);
		}
		// The biggest difference here is more information.
		else
		{
			loadLanguage('Post');

			$attachments = array();
			if (!empty($loaded_attachments))
			{
				foreach ($loaded_attachments as $attachment)
				{
					$attachments[] = array(
						'tag' => 'attachment',
						'attributes' => array('title' => $txt['attachment']),
						'content' => array(
							array(
								'tag' => 'id',
								'content' => $attachment['id_attach'],
							),
							array(
								'tag' => 'name',
								'attributes' => array('title' => $txt['name']),
								'content' => preg_replace('~&amp;#(\\d{1,7}|x[0-9a-fA-F]{1,6});~', '&#\\1;', $smcFunc['htmlspecialchars']($attachment['filename'])),
							),
							array(
								'tag' => 'downloads',
								'attributes' => array('title' => $txt['downloads']),
								'content' => $attachment['downloads'],
							),
							array(
								'tag' => 'size',
								'attributes' => array('title' => $txt['filesize']),
								'content' => ($attachment['filesize'] < 1024000) ? round($attachment['filesize'] / 1024, 2) . ' ' . $txt['kilobyte'] : round($attachment['filesize'] / 1024 / 1024, 2) . ' ' . $txt['megabyte'],
							),
							array(
								'tag' => 'byte_size',
								'attributes' => array('title' => $txt['filesize']),
								'content' => $attachment['filesize'],
							),
							array(
								'tag' => 'link',
								'attributes' => array('title' => $txt['url']),
								'content' => $scripturl . '?action=dlattach;topic=' . $attachment['topic'] . '.0;attach=' . $attachment['id_attach'],
							),
						)
					);
				}
			}
			else
				$attachments = null;

			$data[] = array(
				'tag' => 'article',
				'attributes' => array('title' => $txt['news']),
				'content' => array(
					array(
						'tag' => 'time',
						'attributes' => array('title' => $txt['date']),
						'content' => $smcFunc['htmlspecialchars'](strip_tags(timeformat($row['poster_time']))),
					),
					array(
						'tag' => 'id',
						'content' => $row['id_topic'],
					),
					array(
						'tag' => 'subject',
						'attributes' => array('title' => $txt['subject']),
						'content' => $row['subject'],
						'cdata' => true,
					),
					array(
						'tag' => 'body',
						'attributes' => array('title' => $txt['message']),
						'content' => $row['body'],
						'cdata' => true,
					),
					array(
						'tag' => 'poster',
						'attributes' => array('title' => $txt['author']),
						'content' => array(
							array(
								'tag' => 'name',
								'attributes' => array('title' => $txt['name']),
								'content' => $row['poster_name'],
								'cdata' => true,
							),
							array(
								'tag' => 'id',
								'content' => $row['id_member'],
							),
							array(
								'tag' => 'link',
								'attributes' => array('title' => $txt['url']),
								'content' => !empty($row['id_member']) ? $scripturl . '?action=profile;u=' . $row['id_member'] : '',
							),
						)
					),
					array(
						'tag' => 'topic',
						'attributes' => array('title' => $txt['topic']),
						'content' => $row['id_topic'],
					),
					array(
						'tag' => 'board',
						'attributes' => array('title' => $txt['board']),
						'content' => array(
							array(
								'tag' => 'name',
								'attributes' => array('title' => $txt['name']),
								'content' => $row['bname'],
							),
							array(
								'tag' => 'id',
								'content' => $row['id_board'],
							),
							array(
								'tag' => 'link',
								'attributes' => array('title' => $txt['url']),
								'content' => $scripturl . '?board=' . $row['id_board'] . '.0',
							),
						),
					),
					array(
						'tag' => 'link',
						'attributes' => array('title' => $txt['url']),
						'content' => $scripturl . '?topic=' . $row['id_topic'] . '.0',
					),
					array(
						'tag' => 'attachments',
						'attributes' => array('title' => $txt['attachments']),
						'content' => $attachments,
					),
				),
			);
		}
	}
	$smcFunc['db_free_result']($request);

	return $data;
}

/**
 * Get the recent topics to display.
 * The returned array will be generated to match the xml_format.
 * @todo does not belong here.
 *
 * @param string $xml_format The XML format. Can be 'atom', 'rdf', 'rss', 'rss2' or 'smf'
 * @return array An array of arrays containing data for the feed. Each array has keys corresponding to the appropriate tags for the specified format.
 */
function getXmlRecent($xml_format)
{
	global $scripturl, $modSettings, $board, $txt;
	global $query_this_board, $smcFunc, $context, $user_info, $sourcedir;

	require_once($sourcedir . '/Subs-Attachments.php');

	$done = false;
	$loops = 0;
	while (!$done)
	{
		$optimize_msg = implode(' AND ', $context['optimize_msg']);
		$request = $smcFunc['db_query']('', '
			SELECT m.id_msg
			FROM {db_prefix}messages AS m
				INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)
				INNER JOIN {db_prefix}topics AS t ON (t.id_topic = m.id_topic)
			WHERE ' . $query_this_board . (empty($optimize_msg) ? '' : '
				AND {raw:optimize_msg}') . (empty($board) ? '' : '
				AND m.id_board = {int:current_board}') . ($modSettings['postmod_active'] ? '
				AND m.approved = {int:is_approved}' : '') . '
			ORDER BY m.id_msg DESC
			LIMIT {int:limit} OFFSET {int:offset}',
			array(
				'limit' => $_GET['limit'],
				'offset' => $_GET['offset'],
				'current_board' => $board,
				'is_approved' => 1,
				'optimize_msg' => $optimize_msg,
			)
		);
		// If we don't have $_GET['limit'] results, try again with an unoptimized version covering all rows.
		if ($loops < 2 && $smcFunc['db_num_rows']($request) < $_GET['limit'])
		{
			$smcFunc['db_free_result']($request);
			if (empty($_REQUEST['boards']) && empty($board))
				unset($context['optimize_msg']['lowest']);
			else
				$context['optimize_msg']['lowest'] = $loops ? 'm.id_msg >= t.id_first_msg' : 'm.id_msg >= (t.id_last_msg - t.id_first_msg) / 2';
			$loops++;
		}
		else
			$done = true;
	}
	$messages = array();
	while ($row = $smcFunc['db_fetch_assoc']($request))
		$messages[] = $row['id_msg'];
	$smcFunc['db_free_result']($request);

	if (empty($messages))
		return array();

	// Find the most recent posts this user can see.
	$request = $smcFunc['db_query']('', '
		SELECT
			m.smileys_enabled, m.poster_time, m.id_msg, m.subject, m.body, m.id_topic, t.id_board,
			b.name AS bname, t.num_replies, m.id_member, m.icon, mf.id_member AS id_first_member,
			COALESCE(mem.real_name, m.poster_name) AS poster_name, mf.subject AS first_subject,
			COALESCE(memf.real_name, mf.poster_name) AS first_poster_name,
			COALESCE(mem.email_address, m.poster_email) AS poster_email, m.modified_time
		FROM {db_prefix}messages AS m
			INNER JOIN {db_prefix}topics AS t ON (t.id_topic = m.id_topic)
			INNER JOIN {db_prefix}messages AS mf ON (mf.id_msg = t.id_first_msg)
			INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = m.id_member)
			LEFT JOIN {db_prefix}members AS memf ON (memf.id_member = mf.id_member)
		WHERE m.id_msg IN ({array_int:message_list})
			' . (empty($board) ? '' : 'AND t.id_board = {int:current_board}') . '
		ORDER BY m.id_msg DESC
		LIMIT {int:limit}',
		array(
			'limit' => $_GET['limit'],
			'current_board' => $board,
			'message_list' => $messages,
		)
	);
	$data = array();
	while ($row = $smcFunc['db_fetch_assoc']($request))
	{
		// If any control characters slipped in somehow, kill the evil things
		$row = preg_replace($context['utf8'] ? '/\pCc*/u' : '/[\x00-\x1F\x7F]*/', '', $row);

		// Limit the length of the message, if the option is set.
		if (!empty($modSettings['xmlnews_maxlen']) && $smcFunc['strlen'](str_replace('<br>', "\n", $row['body'])) > $modSettings['xmlnews_maxlen'])
			$row['body'] = strtr($smcFunc['substr'](str_replace('<br>', "\n", $row['body']), 0, $modSettings['xmlnews_maxlen'] - 3), array("\n" => '<br>')) . '...';

		$row['body'] = parse_bbc($row['body'], $row['smileys_enabled'], $row['id_msg']);

		censorText($row['body']);
		censorText($row['subject']);

		// Do we want to include any attachments?
		if (!empty($modSettings['attachmentEnable']) && !empty($modSettings['xmlnews_attachments']) && allowedTo('view_attachments', $row['id_board']))
		{
			$attach_request = $smcFunc['db_query']('', '
				SELECT
					a.id_attach, a.filename, COALESCE(a.size, 0) AS filesize, a.mime_type, a.downloads, a.approved, m.id_topic AS topic
				FROM {db_prefix}attachments AS a
					LEFT JOIN {db_prefix}messages AS m ON (m.id_msg = a.id_msg)
				WHERE a.attachment_type = {int:attachment_type}
					AND a.id_msg = {int:message_id}',
				array(
					'message_id' => $row['id_msg'],
					'attachment_type' => 0,
					'is_approved' => 1,
				)
			);
			$loaded_attachments = array();
			while ($attach = $smcFunc['db_fetch_assoc']($attach_request))
			{
				// Include approved attachments only
				if ($attach['approved'])
					$loaded_attachments['attachment_' . $attach['id_attach']] = $attach;
			}
			$smcFunc['db_free_result']($attach_request);

			// Sort the attachments by size to make things easier below
			if (!empty($loaded_attachments))
			{
				uasort($loaded_attachments, function($a, $b) {
					if ($a['filesize'] == $b['filesize'])
					        return 0;
					return ($a['filesize'] < $b['filesize']) ? -1 : 1;
				});
			}
			else
				$loaded_attachments = null;
		}
		else
			$loaded_attachments = null;

		// Create a GUID for this post using the tag URI scheme
		$guid = 'tag:' . parse_url($scripturl, PHP_URL_HOST) . ',' . gmdate('Y-m-d', $row['poster_time']) . ':msg=' . $row['id_msg'];

		// Doesn't work as well as news, but it kinda does..
		if ($xml_format == 'rss' || $xml_format == 'rss2')
		{
			// Only one attachment allowed in RSS.
			if ($loaded_attachments !== null)
			{
				$attachment = array_pop($loaded_attachments);
				$enclosure = array(
					'url' => fix_possible_url($scripturl . '?action=dlattach;topic=' . $attachment['topic'] . '.0;attach=' . $attachment['id_attach']),
					'length' => $attachment['filesize'],
					'type' => $attachment['mime_type'],
				);
			}
			else
				$enclosure = null;

			$data[] = array(
				'tag' => 'item',
				'content' => array(
					array(
						'tag' => 'title',
						'content' => $row['subject'],
						'cdata' => true,
					),
					array(
						'tag' => 'link',
						'content' => $scripturl . '?topic=' . $row['id_topic'] . '.msg' . $row['id_msg'] . '#msg' . $row['id_msg'],
					),
					array(
						'tag' => 'description',
						'content' => $row['body'],
						'cdata' => true,
					),
					array(
						'tag' => 'author',
						'content' => (allowedTo('moderate_forum') || (!empty($row['id_member']) && $row['id_member'] == $user_info['id'])) ? $row['poster_email'] : null,
					),
					array(
						'tag' => 'category',
						'content' => $row['bname'],
					),
					array(
						'tag' => 'comments',
						'content' => $scripturl . '?action=post;topic=' . $row['id_topic'] . '.0',
					),
					array(
						'tag' => 'pubDate',
						'content' => gmdate('D, d M Y H:i:s \G\M\T', $row['poster_time']),
					),
					array(
						'tag' => 'guid',
						'content' => $guid,
						'attributes' => array(
							'isPermaLink' => 'false',
						),
					),
					array(
						'tag' => 'enclosure',
						'attributes' => $enclosure,
					),
				),
			);
		}
		elseif ($xml_format == 'rdf')
		{
			$data[] = array(
				'tag' => 'item',
				'attributes' => array('rdf:about' => $scripturl . '?topic=' . $row['id_topic'] . '.msg' . $row['id_msg'] . '#msg' . $row['id_msg']),
				'content' => array(
					array(
						'tag' => 'dc:format',
						'content' => 'text/html',
					),
					array(
						'tag' => 'title',
						'content' => $row['subject'],
						'cdata' => true,
					),
					array(
						'tag' => 'link',
						'content' => $scripturl . '?topic=' . $row['id_topic'] . '.msg' . $row['id_msg'] . '#msg' . $row['id_msg'],
					),
					array(
						'tag' => 'description',
						'content' => $row['body'],
						'cdata' => true,
					),
				),
			);
		}
		elseif ($xml_format == 'atom')
		{
			// Only one attachment allowed
			if (!empty($loaded_attachments))
			{
				$attachment = array_pop($loaded_attachments);
				$enclosure = array(
					'rel' => 'enclosure',
					'href' => fix_possible_url($scripturl . '?action=dlattach;topic=' . $attachment['topic'] . '.0;attach=' . $attachment['id_attach']),
					'length' => $attachment['filesize'],
					'type' => $attachment['mime_type'],
				);
			}
			else
				$enclosure = null;

			$data[] = array(
				'tag' => 'entry',
				'content' => array(
					array(
						'tag' => 'title',
						'content' => $row['subject'],
						'cdata' => true,
					),
					array(
						'tag' => 'link',
						'attributes' => array(
							'rel' => 'alternate',
							'type' => 'text/html',
							'href' => $scripturl . '?topic=' . $row['id_topic'] . '.msg' . $row['id_msg'] . '#msg' . $row['id_msg'],
						),
					),
					array(
						'tag' => 'summary',
						'attributes' => array('type' => 'html'),
						'content' => $row['body'],
						'cdata' => true,
					),
					array(
						'tag' => 'category',
						'attributes' => array('term' => $row['bname']),
					),
					array(
						'tag' => 'author',
						'content' => array(
							array(
								'tag' => 'name',
								'content' => $row['poster_name'],
								'cdata' => true,
							),
							array(
								'tag' => 'email',
								'content' => (allowedTo('moderate_forum') || (!empty($row['id_member']) && $row['id_member'] == $user_info['id'])) ? $row['poster_email'] : null,
							),
							array(
								'tag' => 'uri',
								'content' => !empty($row['id_member']) ? $scripturl . '?action=profile;u=' . $row['id_member'] : null,
							),
						),
					),
					array(
						'tag' => 'published',
						'content' => gmstrftime('%Y-%m-%dT%H:%M:%SZ', $row['poster_time']),
					),
					array(
						'tag' => 'updated',
						'content' => gmstrftime('%Y-%m-%dT%H:%M:%SZ', empty($row['modified_time']) ? $row['poster_time'] : $row['modified_time']),
					),
					array(
						'tag' => 'id',
						'content' => $guid,
					),
					array(
						'tag' => 'link',
						'attributes' => $enclosure,
					),
				),
			);
		}
		// A lot of information here.  Should be enough to please the rss-ers.
		else
		{
			loadLanguage('Post');

			$attachments = array();
			if (!empty($loaded_attachments))
			{
				foreach ($loaded_attachments as $attachment)
				{
					$attachments[] = array(
						'tag' => 'attachment',
						'attributes' => array('title' => $txt['attachment']),
						'content' => array(
							array(
								'tag' => 'id',
								'content' => $attachment['id_attach'],
							),
							array(
								'tag' => 'name',
								'attributes' => array('title' => $txt['name']),
								'content' => preg_replace('~&amp;#(\\d{1,7}|x[0-9a-fA-F]{1,6});~', '&#\\1;', $smcFunc['htmlspecialchars']($attachment['filename'])),
							),
							array(
								'tag' => 'downloads',
								'attributes' => array('title' => $txt['downloads']),
								'content' => $attachment['downloads'],
							),
							array(
								'tag' => 'size',
								'attributes' => array('title' => $txt['filesize']),
								'content' => ($attachment['filesize'] < 1024000) ? round($attachment['filesize'] / 1024, 2) . ' ' . $txt['kilobyte'] : round($attachment['filesize'] / 1024 / 1024, 2) . ' ' . $txt['megabyte'],
							),
							array(
								'tag' => 'byte_size',
								'attributes' => array('title' => $txt['filesize']),
								'content' => $attachment['filesize'],
							),
							array(
								'tag' => 'link',
								'attributes' => array('title' => $txt['url']),
								'content' => $scripturl . '?action=dlattach;topic=' . $attachment['topic'] . '.0;attach=' . $attachment['id_attach'],
							),
						)
					);
				}
			}
			else
				$attachments = null;

			$data[] = array(
				'tag' => 'recent-post',
				'attributes' => array('title' => $txt['post']),
				'content' => array(
					array(
						'tag' => 'time',
						'attributes' => array('title' => $txt['date']),
						'content' => $smcFunc['htmlspecialchars'](strip_tags(timeformat($row['poster_time']))),
					),
					array(
						'tag' => 'id',
						'content' => $row['id_msg'],
					),
					array(
						'tag' => 'subject',
						'attributes' => array('title' => $txt['subject']),
						'content' => $row['subject'],
						'cdata' => true,
					),
					array(
						'tag' => 'body',
						'attributes' => array('title' => $txt['message']),
						'content' => $row['body'],
						'cdata' => true,
					),
					array(
						'tag' => 'starter',
						'attributes' => array('title' => $txt['topic_started']),
						'content' => array(
							array(
								'tag' => 'name',
								'attributes' => array('title' => $txt['name']),
								'content' => $row['first_poster_name'],
								'cdata' => true,
							),
							array(
								'tag' => 'id',
								'content' => $row['id_first_member'],
							),
							array(
								'tag' => 'link',
								'attributes' => array('title' => $txt['url']),
								'content' => !empty($row['id_first_member']) ? $scripturl . '?action=profile;u=' . $row['id_first_member'] : '',
							),
						),
					),
					array(
						'tag' => 'poster',
						'attributes' => array('title' => $txt['author']),
						'content' => array(
							array(
								'tag' => 'name',
								'attributes' => array('title' => $txt['name']),
								'content' => $row['poster_name'],
								'cdata' => true,
							),
							array(
								'tag' => 'id',
								'content' => $row['id_member'],
							),
							array(
								'tag' => 'link',
								'attributes' => array('title' => $txt['url']),
								'content' => !empty($row['id_member']) ? $scripturl . '?action=profile;u=' . $row['id_member'] : '',
							),
						),
					),
					array(
						'tag' => 'topic',
						'attributes' => array('title' => $txt['topic']),
						'content' => array(
							array(
								'tag' => 'subject',
								'attributes' => array('title' => $txt['subject']),
								'content' => $row['first_subject'],
								'cdata' => true,
							),
							array(
								'tag' => 'id',
								'content' => $row['id_topic'],
							),
							array(
								'tag' => 'link',
								'attributes' => array('title' => $txt['url']),
								'content' => $scripturl . '?topic=' . $row['id_topic'] . '.new#new',
							),
						),
					),
					array(
						'tag' => 'board',
						'attributes' => array('title' => $txt['board']),
						'content' => array(
							array(
								'tag' => 'name',
								'attributes' => array('title' => $txt['name']),
								'content' => $row['bname'],
							),
							array(
								'tag' => 'id',
								'content' => $row['id_board'],
							),
							array(
								'tag' => 'link',
								'attributes' => array('title' => $txt['url']),
								'content' => $scripturl . '?board=' . $row['id_board'] . '.0',
							),
						),
					),
					array(
						'tag' => 'link',
						'attributes' => array('title' => $txt['url']),
						'content' => $scripturl . '?topic=' . $row['id_topic'] . '.msg' . $row['id_msg'] . '#msg' . $row['id_msg'],
					),
					array(
						'tag' => 'attachments',
						'attributes' => array('title' => $txt['attachments']),
						'content' => $attachments,
					),
				),
			);
		}
	}
	$smcFunc['db_free_result']($request);

	return $data;
}

/**
 * Get the profile information for member into an array,
 * which will be generated to match the xml_format.
 * @todo refactor.
 *
 * @param string $xml_format The XML format. Can be 'atom', 'rdf', 'rss', 'rss2' or 'smf'
 * @return array An array profile data
 */
function getXmlProfile($xml_format)
{
	global $scripturl, $memberContext, $user_profile, $user_info, $txt, $context;

	// Make sure the id is a number and not "I like trying to hack the database".
	$_GET['u'] = isset($_GET['u']) ? (int) $_GET['u'] : $user_info['id'];

	// You must input a valid user....
	if (empty($_GET['u']) || !loadMemberData((int) $_GET['u']))
		return array();

	// Load the member's contextual information! (Including custom fields for our proprietary XML type)
	if (!loadMemberContext($_GET['u'], ($xml_format == 'smf')) || !allowedTo('profile_view'))
		return array();

	// Okay, I admit it, I'm lazy.  Stupid $_GET['u'] is long and hard to type.
	$profile = &$memberContext[$_GET['u']];

	// Create a GUID for this member using the tag URI scheme
	$guid = 'tag:' . parse_url($scripturl, PHP_URL_HOST) . ',' . gmdate('Y-m-d', $user_profile[$profile['id']]['date_registered']) . ':member=' . $profile['id'];

	if ($xml_format == 'rss' || $xml_format == 'rss2')
	{
		$data[] = array(
			'tag' => 'item',
			'content' => array(
				array(
					'tag' => 'title',
					'content' => $profile['name'],
					'cdata' => true,
				),
				array(
					'tag' => 'link',
					'content' => $scripturl . '?action=profile;u=' . $profile['id'],
				),
				array(
					'tag' => 'description',
					'content' => isset($profile['group']) ? $profile['group'] : $profile['post_group'],
					'cdata' => true,
				),
				array(
					'tag' => 'comments',
					'content' => $scripturl . '?action=pm;sa=send;u=' . $profile['id'],
				),
				array(
					'tag' => 'pubDate',
					'content' => gmdate('D, d M Y H:i:s \G\M\T', $user_profile[$profile['id']]['date_registered']),
				),
				array(
					'tag' => 'guid',
					'content' => $guid,
					'attributes' => array(
						'isPermaLink' => 'false',
					),
				),
			)
		);
	}
	elseif ($xml_format == 'rdf')
	{
		$data[] = array(
			'tag' => 'item',
			'attributes' => array('rdf:about' => $scripturl . '?action=profile;u=' . $profile['id']),
			'content' => array(
				array(
					'tag' => 'dc:format',
					'content' => 'text/html',
				),
				array(
					'tag' => 'title',
					'content' => $profile['name'],
					'cdata' => true,
				),
				array(
					'tag' => 'link',
					'content' => $scripturl . '?action=profile;u=' . $profile['id'],
				),
				array(
					'tag' => 'description',
					'content' => isset($profile['group']) ? $profile['group'] : $profile['post_group'],
					'cdata' => true,
				),
			)
		);
	}
	elseif ($xml_format == 'atom')
	{
		$data[] = array(
			'tag' => 'entry',
			'content' => array(
				array(
					'tag' => 'title',
					'content' => $profile['name'],
					'cdata' => true,
				),
				array(
					'tag' => 'link',
					'attributes' => array(
						'rel' => 'alternate',
						'type' => 'text/html',
						'href' => $scripturl . '?action=profile;u=' . $profile['id'],
					),
				),
				array(
					'tag' => 'summary',
					'attributes' => array('type' => 'html'),
					'content' => isset($profile['group']) ? $profile['group'] : $profile['post_group'],
					'cdata' => true,
				),
				array(
					'tag' => 'author',
					'content' => array(
						array(
							'tag' => 'name',
							'content' => $profile['name'],
							'cdata' => true,
						),
						array(
							'tag' => 'email',
							'content' => $profile['show_email'] ? $profile['email'] : null,
						),
						array(
							'tag' => 'uri',
							'content' => !empty($profile['website']['url']) ? $profile['website']['url'] : null,
						),
					),
				),
				array(
					'tag' => 'published',
					'content' => gmstrftime('%Y-%m-%dT%H:%M:%SZ', $user_profile[$profile['id']]['date_registered']),
				),
				array(
					'tag' => 'updated',
					'content' => gmstrftime('%Y-%m-%dT%H:%M:%SZ', $user_profile[$profile['id']]['last_login']),
				),
				array(
					'tag' => 'id',
					'content' => $guid,
				),
			)
		);
	}
	else
	{
		loadLanguage('Profile');

		$data = array(
			array(
				'tag' => 'username',
				'attributes' => array('title' => $txt['username']),
				'content' => $user_info['is_admin'] || $user_info['id'] == $profile['id'] ? $profile['username'] : null,
				'cdata' => true,
			),
			array(
				'tag' => 'name',
				'attributes' => array('title' => $txt['name']),
				'content' => $profile['name'],
				'cdata' => true,
			),
			array(
				'tag' => 'link',
				'attributes' => array('title' => $txt['url']),
				'content' => $scripturl . '?action=profile;u=' . $profile['id'],
			),
			array(
				'tag' => 'posts',
				'attributes' => array('title' => $txt['member_postcount']),
				'content' => $profile['posts'],
			),
			array(
				'tag' => 'post-group',
				'attributes' => array('title' => $txt['membergroups_group_type_post']),
				'content' => $profile['post_group'],
				'cdata' => true,
			),
			array(
				'tag' => 'language',
				'attributes' => array('title' => $txt['preferred_language']),
				'content' => $profile['language'],
				'cdata' => true,
			),
			array(
				'tag' => 'last-login',
				'attributes' => array('title' => $txt['lastLoggedIn']),
				'content' => gmdate('D, d M Y H:i:s \G\M\T', $user_profile[$profile['id']]['last_login']),
			),
			array(
				'tag' => 'registered',
				'attributes' => array('title' => $txt['date_registered']),
				'content' => gmdate('D, d M Y H:i:s \G\M\T', $user_profile[$profile['id']]['date_registered']),
			),
			array(
				'tag' => 'avatar',
				'attributes' => array('title' => $txt['personal_picture']),
				'content' => !empty($profile['avatar']['url']) ? $profile['avatar']['url'] : null,
			),
			array(
				'tag' => 'signature',
				'attributes' => array('title' => $txt['signature']),
				'content' => !empty($profile['signature']) ? $profile['signature'] : null,
				'cdata' => true,
			),
			array(
				'tag' => 'blurb',
				'attributes' => array('title' => $txt['personal_text']),
				'content' => !empty($profile['blurb']) ? $profile['blurb'] : null,
				'cdata' => true,
			),
			array(
				'tag' => 'title',
				'attributes' => array('title' => $txt['title']),
				'content' => !empty($profile['title']) ? $profile['title'] : null,
				'cdata' => true,
			),
			array(
				'tag' => 'position',
				'attributes' => array('title' => $txt['position']),
				'content' => !empty($profile['group']) ? $profile['group'] : null,
				'cdata' => true,
			),
			array(
				'tag' => 'email',
				'attributes' => array('title' => $txt['user_email_address']),
				'content' => !empty($profile['show_email']) || $user_info['is_admin'] || $user_info['id'] == $profile['id'] ? $profile['email'] : null,
			),
			array(
				'tag' => 'website',
				'attributes' => array('title' => $txt['website']),
				'content' => empty($profile['website']['url']) ? null : array(
					array(
						'tag' => 'title',
						'attributes' => array('title' => $txt['website_title']),
						'content' => !empty($profile['website']['title']) ? $profile['website']['title'] : null,
					),
					array(
						'tag' => 'link',
						'attributes' => array('title' => $txt['website_url']),
						'content' => $profile['website']['url'],
					),
				),
			),
			array(
				'tag' => 'online',
				'attributes' => array('title' => $txt['online']),
				'content' => !empty($profile['online']['is_online']) ? '' : null,
			),
			array(
				'tag' => 'ip_addresses',
				'attributes' => array('title' => $txt['ip_address']),
				'content' => allowedTo('moderate_forum') || $user_info['id'] == $profile['id'] ? array(
					array(
						'tag' => 'ip',
						'attributes' => array('title' => $txt['most_recent_ip']),
						'content' => $profile['ip'],
					),
					array(
						'tag' => 'ip2',
						'content' => $profile['ip'] != $profile['ip2'] ? $profile['ip2'] : null,
					),
				) : null,
			),
		);

		if (!empty($profile['birth_date']) && substr($profile['birth_date'], 0, 4) != '0000' && substr($profile['birth_date'], 0, 4) != '1004')
		{
			list ($birth_year, $birth_month, $birth_day) = sscanf($profile['birth_date'], '%d-%d-%d');
			$datearray = getdate(forum_time());
			$age = $datearray['year'] - $birth_year - (($datearray['mon'] > $birth_month || ($datearray['mon'] == $birth_month && $datearray['mday'] >= $birth_day)) ? 0 : 1);

			$data[] = array(
				'tag' => 'age',
				'attributes' => array('title' => $txt['age']),
				'content' => $age,
			);
			$data[] = array(
				'tag' => 'birthdate',
				'attributes' => array('title' => $txt['dob']),
				'content' => $profile['birth_date'],
			);
		}

		if (!empty($profile['custom_fields']))
		{
			foreach ($profile['custom_fields'] as $custom_field)
			{
				$data[] = array(
					'tag' => $custom_field['col_name'],
					'attributes' => array('title' => $custom_field['title']),
					'content' => $custom_field['raw'],
					'cdata' => true,
				);
			}
		}
	}

	// Save some memory.
	unset($profile, $memberContext[$_GET['u']]);

	return $data;
}

/**
 * Get a user's posts.
 * The returned array will be generated to match the xml_format.
 *
 * @param string $xml_format The XML format. Can be 'atom', 'rdf', 'rss', 'rss2' or 'smf'
 * @return array An array of arrays containing data for the feed. Each array has keys corresponding to the appropriate tags for the specified format.
 */
function getXmlPosts($xml_format)
{
	global $scripturl, $modSettings, $board, $txt, $context, $user_info;
	global $query_this_board, $smcFunc, $sourcedir, $cachedir;

	$uid = isset($_GET['u']) ? (int) $_GET['u'] : $user_info['id'];

	if (empty($uid) || (!allowedTo('profile_view') && $uid != $user_info['id']))
		return array();

	$show_all = $user_info['is_admin'] || $uid == $user_info['id'];

	// You are allowed in this special case to see your own posts from anywhere
	if ($show_all)
		$query_this_board = preg_replace('/\{query_see_board\}\s*(AND )?/', '', $query_this_board);

	require_once($sourcedir . '/Subs-Attachments.php');

	// Need to know the total so we can track our progress
	if (!empty($context['batch_mode']) && empty($context['batch_total']))
	{
		$request = $smcFunc['db_query']('', '
			SELECT COUNT(m.id_msg)
			FROM {db_prefix}messages as m
				INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)
			WHERE id_member = {int:uid}
				AND ' . $query_this_board . ($modSettings['postmod_active'] && !$show_all ? '
				AND approved = {int:is_approved}' : ''),
			array(
				'uid' => $uid,
				'is_approved' => 1,
			)
		);
		list($context['batch_total']) = $smcFunc['db_fetch_row']($request);
		$smcFunc['db_free_result']($request);
	}

	$request = $smcFunc['db_query']('', '
		SELECT m.id_msg, m.id_topic, m.id_board, m.poster_name, m.poster_email, m.poster_ip, m.poster_time, m.subject,
			modified_time, m.modified_name, m.modified_reason, m.body, m.likes, m.approved, m.smileys_enabled
		FROM {db_prefix}messages as m
			INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)
		WHERE id_member = {int:uid}
			AND id_msg > {int:start_after}
			AND ' . $query_this_board . ($modSettings['postmod_active'] && !$show_all ? '
			AND approved = {int:is_approved}' : '') . '
		ORDER BY id_msg
		LIMIT {int:limit} OFFSET {int:offset}',
		array(
			'limit' => $_GET['limit'],
			'offset' => !empty($context['posts_start']) ? 0 : $_GET['offset'],
			'start_after' => !empty($context['posts_start']) ? $context['posts_start'] : 0,
			'uid' => $uid,
			'is_approved' => 1,
		)
	);
	$data = array();
	while ($row = $smcFunc['db_fetch_assoc']($request))
	{
		$last = $row['id_msg'];

		// We want a readable version of the IP address
		$row['poster_ip'] = inet_dtop($row['poster_ip']);

		// If any control characters slipped in somehow, kill the evil things
		$row = preg_replace($context['utf8'] ? '/\pCc*/u' : '/[\x00-\x1F\x7F]*/', '', $row);

		// If using our own format, we want the raw BBC
		if ($xml_format != 'smf')
			$row['body'] = parse_bbc($row['body'], $row['smileys_enabled'], $row['id_msg']);

		// Do we want to include any attachments?
		if (!empty($modSettings['attachmentEnable']) && !empty($modSettings['xmlnews_attachments']))
		{
			$attach_request = $smcFunc['db_query']('', '
				SELECT
					a.id_attach, a.filename, COALESCE(a.size, 0) AS filesize, a.mime_type, a.downloads, a.approved, m.id_topic AS topic
				FROM {db_prefix}attachments AS a
					LEFT JOIN {db_prefix}messages AS m ON (m.id_msg = a.id_msg)
				WHERE a.attachment_type = {int:attachment_type}
					AND a.id_msg = {int:message_id}',
				array(
					'message_id' => $row['id_msg'],
					'attachment_type' => 0,
					'is_approved' => 1,
				)
			);
			$loaded_attachments = array();
			while ($attach = $smcFunc['db_fetch_assoc']($attach_request))
			{
				// Include approved attachments only
				if ($attach['approved'])
					$loaded_attachments['attachment_' . $attach['id_attach']] = $attach;
			}
			$smcFunc['db_free_result']($attach_request);

			// Sort the attachments by size to make things easier below
			if (!empty($loaded_attachments))
			{
				uasort($loaded_attachments, function($a, $b) {
					if ($a['filesize'] == $b['filesize'])
					        return 0;
					return ($a['filesize'] < $b['filesize']) ? -1 : 1;
				});
			}
			else
				$loaded_attachments = null;
		}
		else
			$loaded_attachments = null;

		// Create a GUID for this post using the tag URI scheme
		$guid = 'tag:' . parse_url($scripturl, PHP_URL_HOST) . ',' . gmdate('Y-m-d', $row['poster_time']) . ':msg=' . $row['id_msg'];

		if ($xml_format == 'rss' || $xml_format == 'rss2')
		{
			// Only one attachment allowed in RSS.
			if ($loaded_attachments !== null)
			{
				$attachment = array_pop($loaded_attachments);
				$enclosure = array(
					'url' => fix_possible_url($scripturl . '?action=dlattach;topic=' . $attachment['topic'] . '.0;attach=' . $attachment['id_attach']),
					'length' => $attachment['filesize'],
					'type' => $attachment['mime_type'],
				);
			}
			else
				$enclosure = null;

			$data[] = array(
				'tag' => 'item',
				'content' => array(
					array(
						'tag' => 'title',
						'content' => $row['subject'],
						'cdata' => true,
					),
					array(
						'tag' => 'link',
						'content' => $scripturl . '?msg=' . $row['id_msg'],
					),
					array(
						'tag' => 'description',
						'content' => $row['body'],
						'cdata' => true,
					),
					array(
						'tag' => 'author',
						'content' => (allowedTo('moderate_forum') || ($uid == $user_info['id'])) ? $row['poster_email'] : null,
					),
					array(
						'tag' => 'category',
						'content' => $row['bname'],
					),
					array(
						'tag' => 'comments',
						'content' => $scripturl . '?action=post;topic=' . $row['id_topic'] . '.0',
					),
					array(
						'tag' => 'pubDate',
						'content' => gmdate('D, d M Y H:i:s \G\M\T', $row['poster_time']),
					),
					array(
						'tag' => 'guid',
						'content' => $guid,
						'attributes' => array(
							'isPermaLink' => 'false',
						),
					),
					array(
						'tag' => 'enclosure',
						'attributes' => $enclosure,
					),
				),
			);
		}
		elseif ($xml_format == 'rdf')
		{
			$data[] = array(
				'tag' => 'item',
				'attributes' => array('rdf:about' => $scripturl . '?topic=' . $row['id_topic'] . '.msg' . $row['id_msg'] . '#msg' . $row['id_msg']),
				'content' => array(
					array(
						'tag' => 'dc:format',
						'content' => 'text/html',
					),
					array(
						'tag' => 'title',
						'content' => $row['subject'],
						'cdata' => true,
					),
					array(
						'tag' => 'link',
						'content' => $scripturl . '?msg=' . $row['id_msg'],
					),
					array(
						'tag' => 'description',
						'content' => $row['body'],
						'cdata' => true,
					),
				),
			);
		}
		elseif ($xml_format == 'atom')
		{
			// Only one attachment allowed
			if (!empty($loaded_attachments))
			{
				$attachment = array_pop($loaded_attachments);
				$enclosure = array(
					'rel' => 'enclosure',
					'href' => fix_possible_url($scripturl . '?action=dlattach;topic=' . $attachment['topic'] . '.0;attach=' . $attachment['id_attach']),
					'length' => $attachment['filesize'],
					'type' => $attachment['mime_type'],
				);
			}
			else
				$enclosure = null;

			$data[] = array(
				'tag' => 'entry',
				'content' => array(
					array(
						'tag' => 'title',
						'content' => $row['subject'],
						'cdata' => true,
					),
					array(
						'tag' => 'link',
						'attributes' => array(
							'rel' => 'alternate',
							'type' => 'text/html',
							'href' => $scripturl . '?msg=' . $row['id_msg'],
						),
					),
					array(
						'tag' => 'summary',
						'attributes' => array('type' => 'html'),
						'content' => $row['body'],
						'cdata' => true,
					),
					array(
						'tag' => 'author',
						'content' => array(
							array(
								'tag' => 'name',
								'content' => $row['poster_name'],
								'cdata' => true,
							),
							array(
								'tag' => 'email',
								'content' => (allowedTo('moderate_forum') || ($uid == $user_info['id'])) ? $row['poster_email'] : null,
							),
							array(
								'tag' => 'uri',
								'content' => !empty($row['id_member']) ? $scripturl . '?action=profile;u=' . $uid : null,
							),
						),
					),
					array(
						'tag' => 'published',
						'content' => gmstrftime('%Y-%m-%dT%H:%M:%SZ', $row['poster_time']),
					),
					array(
						'tag' => 'updated',
						'content' => gmstrftime('%Y-%m-%dT%H:%M:%SZ', empty($row['modified_time']) ? $row['poster_time'] : $row['modified_time']),
					),
					array(
						'tag' => 'id',
						'content' => $guid,
					),
					array(
						'tag' => 'link',
						'attributes' => $enclosure,
					),
				),
			);
		}
		// A lot of information here.  Should be enough to please the rss-ers.
		else
		{
			loadLanguage('Post');

			$attachments = array();
			if (!empty($loaded_attachments))
			{
				foreach ($loaded_attachments as $attachment)
				{
					$attachments[] = array(
						'tag' => 'attachment',
						'attributes' => array('title' => $txt['attachment']),
						'content' => array(
							array(
								'tag' => 'id',
								'content' => $attachment['id_attach'],
							),
							array(
								'tag' => 'name',
								'attributes' => array('title' => $txt['name']),
								'content' => preg_replace('~&amp;#(\\d{1,7}|x[0-9a-fA-F]{1,6});~', '&#\\1;', $smcFunc['htmlspecialchars']($attachment['filename'])),
							),
							array(
								'tag' => 'downloads',
								'attributes' => array('title' => $txt['downloads']),
								'content' => $attachment['downloads'],
							),
							array(
								'tag' => 'size',
								'attributes' => array('title' => $txt['filesize']),
								'content' => ($attachment['filesize'] < 1024000) ? round($attachment['filesize'] / 1024, 2) . ' ' . $txt['kilobyte'] : round($attachment['filesize'] / 1024 / 1024, 2) . ' ' . $txt['megabyte'],
							),
							array(
								'tag' => 'byte_size',
								'attributes' => array('title' => $txt['filesize']),
								'content' => $attachment['filesize'],
							),
							array(
								'tag' => 'link',
								'attributes' => array('title' => $txt['url']),
								'content' => $scripturl . '?action=dlattach;topic=' . $attachment['topic'] . '.0;attach=' . $attachment['id_attach'],
							),
						)
					);
				}
			}
			else
				$attachments = null;

			$data[] = array(
				'tag' => 'member-post',
				'attributes' => array('title' => $txt['post']),
				'content' => array(
					array(
						'tag' => 'id',
						'content' => $row['id_msg'],
					),
					array(
						'tag' => 'subject',
						'attributes' => array('title' => $txt['subject']),
						'content' => $row['subject'],
						'cdata' => true,
					),
					array(
						'tag' => 'body',
						'attributes' => array('title' => $txt['message']),
						'content' => $row['body'],
						'cdata' => true,
					),
					array(
						'tag' => 'poster',
						'attributes' => array('title' => $txt['author']),
						'content' => array(
							array(
								'tag' => 'name',
								'attributes' => array('title' => $txt['name']),
								'content' => $row['poster_name'],
								'cdata' => true,
							),
							array(
								'tag' => 'id',
								'content' => $uid,
							),
							array(
								'tag' => 'link',
								'attributes' => array('title' => $txt['url']),
								'content' => $scripturl . '?action=profile;u=' . $uid,
							),
							array(
								'tag' => 'email',
								'attributes' => array('title' => $txt['user_email_address']),
								'content' => (allowedTo('moderate_forum') || $uid == $user_info['id']) ? $row['poster_email'] : null,
							),
							array(
								'tag' => 'ip',
								'attributes' => array('title' => $txt['ip']),
								'content' => (allowedTo('moderate_forum') || $uid == $user_info['id']) ? $row['poster_ip'] : null,
							),
						),
					),
					array(
						'tag' => 'topic',
						'attributes' => array('title' => $txt['topic']),
						'content' => array(
							array(
								'tag' => 'id',
								'content' => $row['id_topic'],
							),
							array(
								'tag' => 'link',
								'attributes' => array('title' => $txt['url']),
								'content' => $scripturl . '?topic=' . $row['id_topic'] . '.0',
							),
						),
					),
					array(
						'tag' => 'board',
						'attributes' => array('title' => $txt['board']),
						'content' => array(
							array(
								'tag' => 'id',
								'content' => $row['id_board'],
							),
							array(
								'tag' => 'link',
								'attributes' => array('title' => $txt['url']),
								'content' => $scripturl . '?board=' . $row['id_board'] . '.0',
							),
						),
					),
					array(
						'tag' => 'link',
						'attributes' => array('title' => $txt['url']),
						'content' => $scripturl . '?msg=' . $row['id_msg'],
					),
					array(
						'tag' => 'time',
						'attributes' => array('title' => $txt['date']),
						'content' => $smcFunc['htmlspecialchars'](strip_tags(timeformat($row['poster_time']))),
					),
					array(
						'tag' => 'modified_time',
						'attributes' => array('title' => $txt['modified_time']),
						'content' => !empty($row['modified_time']) ? $smcFunc['htmlspecialchars'](strip_tags(timeformat($row['modified_time']))) : null,
					),
					array(
						'tag' => 'modified_by',
						'attributes' => array('title' => $txt['modified_by']),
						'content' => !empty($row['modified_name']) ? $row['modified_name'] : null,
						'cdata' => true,
					),
					array(
						'tag' => 'modified_reason',
						'attributes' => array('title' => $txt['reason_for_edit']),
						'content' => !empty($row['modified_reason']) ? $row['modified_reason'] : null,
						'cdata' => true,
					),
					array(
						'tag' => 'likes',
						'attributes' => array('title' => $txt['likes']),
						'content' => $row['likes'],
					),
					array(
						'tag' => 'attachments',
						'attributes' => array('title' => $txt['attachments']),
						'content' => $attachments,
					),
				),
			);
		}
	}
	$smcFunc['db_free_result']($request);

	// If we're in batch mode, make a note of our progress.
	if (!empty($context['batch_mode']))
	{
		$context['batch_prev'] = (empty($context['batch_prev']) ? 0 : $context['batch_prev']) + count($data);

		file_put_contents($cachedir . '/xml-batch-posts-' . $uid, implode(';', array($last, $context['batch_prev'], $context['batch_total'])));
	}

	return $data;
}

/**
 * Get a user's personal messages.
 * Only the user can do this, and no one else -- not even the admin!
 *
 * @param string $xml_format The XML format. Can be 'atom', 'rdf', 'rss', 'rss2' or 'smf'
 * @return array An array of arrays containing data for the feed. Each array has keys corresponding to the appropriate tags for the specified format.
 */
function getXmlPMs($xml_format)
{
	global $scripturl, $modSettings, $board, $txt, $context, $user_info;
	global $query_this_board, $smcFunc, $sourcedir, $cachedir;

	// Personal messages are supposed to be private
	if (isset($_GET['u']) && (int) $_GET['u'] != $user_info['id'])
		return array();

	// For batch mode, we need to know how many there are
	if (!empty($context['batch_mode']) && empty($context['batch_total']))
	{
		$request = $smcFunc['db_query']('', '
			SELECT COUNT(pm.id_pm)
			FROM {db_prefix}personal_messages AS pm
				INNER JOIN {db_prefix}pm_recipients AS pmr ON (pm.id_pm = pmr.id_pm)
			WHERE (pm.id_member_from = {int:uid} OR pmr.id_member = {int:uid})',
			array(
				'uid' => $user_info['id'],
			)
		);
		list($context['batch_total']) = $smcFunc['db_fetch_row']($request);
		$smcFunc['db_free_result']($request);
	}

	$request = $smcFunc['db_query']('', '
		SELECT pm.id_pm, pm.msgtime, pm.subject, pm.body, pm.id_member_from, pm.from_name, GROUP_CONCAT(pmr.id_member) AS id_members_to, GROUP_CONCAT(COALESCE(mem.real_name, mem.member_name)) AS to_names
		FROM {db_prefix}personal_messages AS pm
			INNER JOIN {db_prefix}pm_recipients AS pmr ON (pm.id_pm = pmr.id_pm)
			INNER JOIN {db_prefix}members AS mem ON (mem.id_member = pmr.id_member)
		WHERE (pm.id_member_from = {int:uid} OR pmr.id_member = {int:uid})
			AND pm.id_pm > {int:start_after}
		GROUP BY pm.id_pm
		ORDER BY pm.id_pm
		LIMIT {int:limit} OFFSET {int:offset}',
		array(
			'limit' => $_GET['limit'],
			'offset' => !empty($context['pms_start']) ? 0 : $_GET['offset'],
			'start_after' => !empty($context['pms_start']) ? $context['pms_start'] : 0,
			'uid' => $user_info['id'],
		)
	);
	$data = array();
	while ($row = $smcFunc['db_fetch_assoc']($request))
	{
		$last = $row['id_pm'];

		// If any control characters slipped in somehow, kill the evil things
		$row = preg_replace($context['utf8'] ? '/\pCc*/u' : '/[\x00-\x1F\x7F]*/', '', $row);

		// If using our own format, we want the raw BBC
		if ($xml_format != 'smf')
			$row['body'] = parse_bbc($row['body']);

		$recipients = array_combine(explode(',', $row['id_members_to']), explode(',', $row['to_names']));

		// Create a GUID for this post using the tag URI scheme
		$guid = 'tag:' . parse_url($scripturl, PHP_URL_HOST) . ',' . gmdate('Y-m-d', $row['msgtime']) . ':pm=' . $row['id_pm'];

		if ($xml_format == 'rss' || $xml_format == 'rss2')
		{
			$item = array(
				'tag' => 'item',
				'content' => array(
					array(
						'tag' => 'guid',
						'content' => $guid,
						'attributes' => array(
							'isPermaLink' => 'false',
						),
					),
					array(
						'tag' => 'pubDate',
						'content' => gmdate('D, d M Y H:i:s \G\M\T', $row['msgtime']),
					),
					array(
						'tag' => 'title',
						'content' => $row['subject'],
						'cdata' => true,
					),
					array(
						'tag' => 'description',
						'content' => $row['body'],
						'cdata' => true,
					),
					array(
						'tag' => 'smf:sender',
						// This technically violates the RSS spec, but meh...
						'content' => $row['from_name'],
						'cdata' => true,
					),
				),
			);

			foreach ($recipients as $recipient_id => $recipient_name)
				$item['content'][] = array(
					'tag' => 'smf:recipient',
					'content' => $recipient_name,
					'cdata' => true,
				);

			$data[] = $item;
		}
		elseif ($xml_format == 'rdf')
		{
			$data[] = array(
				'tag' => 'item',
				'attributes' => array('rdf:about' => $scripturl . '?action=pm#msg' . $row['id_pm']),
				'content' => array(
					array(
						'tag' => 'dc:format',
						'content' => 'text/html',
					),
					array(
						'tag' => 'title',
						'content' => $row['subject'],
						'cdata' => true,
					),
					array(
						'tag' => 'link',
						'content' => $scripturl . '?action=pm#msg' . $row['id_pm'],
					),
					array(
						'tag' => 'description',
						'content' => $row['body'],
						'cdata' => true,
					),
				),
			);
		}
		elseif ($xml_format == 'atom')
		{
			$item = array(
				'tag' => 'entry',
				'content' => array(
					array(
						'tag' => 'id',
						'content' => $guid,
					),
					array(
						'tag' => 'updated',
						'content' => gmstrftime('%Y-%m-%dT%H:%M:%SZ', $row['msgtime']),
					),
					array(
						'tag' => 'title',
						'content' => $row['subject'],
						'cdata' => true,
					),
					array(
						'tag' => 'content',
						'attributes' => array('type' => 'html'),
						'content' => $row['body'],
						'cdata' => true,
					),
					array(
						'tag' => 'author',
						'content' => array(
							array(
								'tag' => 'name',
								'content' => $row['from_name'],
								'cdata' => true,
							),
						),
					),
				),
			);

			foreach ($recipients as $recipient_id => $recipient_name)
				$item['content'][] = array(
					'tag' => 'contributor',
					'content' => array(
						array(
							'tag' => 'smf:role',
							'content' => 'recipient',
						),
						array(
							'tag' => 'name',
							'content' => $recipient_name,
							'cdata' => true,
						),
					),
				);

			$data[] = $item;
		}
		else
		{
			loadLanguage('PersonalMessage');

			$item = array(
				'tag' => 'personal-message',
				'attributes' => array('title' => $txt['pm']),
				'content' => array(
					array(
						'tag' => 'id',
						'content' => $row['id_pm'],
					),
					array(
						'tag' => 'sent-date',
						'attributes' => array('title' => $txt['date']),
						'content' => $smcFunc['htmlspecialchars'](strip_tags(timeformat($row['msgtime']))),
					),
					array(
						'tag' => 'subject',
						'attributes' => array('title' => $txt['subject']),
						'content' => $row['subject'],
						'cdata' => true,
					),
					array(
						'tag' => 'body',
						'attributes' => array('title' => $txt['message']),
						'content' => $row['body'],
						'cdata' => true,
					),
					array(
						'tag' => 'sender',
						'attributes' => array('title' => $txt['author']),
						'content' => array(
							array(
								'tag' => 'name',
								'attributes' => array('title' => $txt['name']),
								'content' => $row['from_name'],
								'cdata' => true,
							),
							array(
								'tag' => 'id',
								'content' => $row['id_member_from'],
							),
							array(
								'tag' => 'link',
								'attributes' => array('title' => $txt['url']),
								'content' => $scripturl . '?action=profile;u=' . $row['id_member_from'],
							),
						),
					),
				),
			);

			foreach ($recipients as $recipient_id => $recipient_name)
				$item['content'][] = array(
					'tag' => 'recipient',
					'attributes' => array('title' => $txt['recipient']),
					'content' => array(
						array(
							'tag' => 'name',
							'attributes' => array('title' => $txt['name']),
							'content' => $recipient_name,
							'cdata' => true,
						),
						array(
							'tag' => 'id',
							'content' => $recipient_id,
						),
						array(
							'tag' => 'link',
							'attributes' => array('title' => $txt['url']),
							'content' => $scripturl . '?action=profile;u=' . $recipient_id,
						),
					),
				);

			$data[] = $item;
		}
	}
	$smcFunc['db_free_result']($request);

	// If we're in batch mode, make a note of our progress.
	if (!empty($context['batch_mode']))
	{
		$context['batch_prev'] = (empty($context['batch_prev']) ? 0 : $context['batch_prev']) + count($data);

		file_put_contents($cachedir . '/xml-batch-pms-' . $user_info['id'], implode(';', array($last, $context['batch_prev'], $context['batch_total'])));
	}

	return $data;
}

?>