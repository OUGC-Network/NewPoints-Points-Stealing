<?php
/***************************************************************************
 *
 *   NewPoints Points Stealing plugin (/inc/plugins/newpoints/languages/english/newpoints_stealing.php)
 *    Author: Diogo Parrinha
 *   Copyright: (c) 2021 Diogo Parrinha
 *
 *   License: licence.txt
 *
 *   Adds a points stealing system to NewPoints.
 *
 ***************************************************************************/

use function Newpoints\Core\language_load;
use function Newpoints\Core\log_add;
use function Newpoints\Core\points_add_simple;
use function Newpoints\Core\points_subtract;
use function Newpoints\Core\templates_get;

use const Newpoints\Core\LOGGING_TYPE_CHARGE;
use const Newpoints\Core\LOGGING_TYPE_INCOME;

// Disallow direct access to this file for security reasons
if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.');
}

global $plugins;

if (!defined('IN_ADMINCP')) {
    $plugins->add_hook('newpoints_start', 'newpoints_stealing_page');
    $plugins->add_hook('newpoints_default_menu', 'newpoints_stealing_menu');
    $plugins->add_hook('newpoints_stats_start', 'newpoints_stealing_stats');
}

function newpoints_stealing_info()
{
    return [
        'name' => 'Points Stealing',
        'description' => 'Adds a points stealing system to NewPoints.',
        'author' => 'Diogo Parrinha',
        'version' => '3.1.0',
        'versioncode' => '3100',
        'compatibility' => '31*',
        'codename' => 'newpoints_points_stealing',
    ];
}

function newpoints_stealing_install()
{
    global $db;

    language_load('stealing');

    // add settings
    $disporder = 0;
    newpoints_add_setting(
        'newpoints_stealing_cost',
        'stealing',
        'Cost',
        'How many points do users have to spend to steal from another user?',
        'text',
        '100',
        ++$disporder
    );
    newpoints_add_setting(
        'newpoints_stealing_chance',
        'stealing',
        'Chance',
        'What is the chance, out of a 100, that a stealing try is successful?',
        'text',
        '10',
        ++$disporder
    );
    newpoints_add_setting(
        'newpoints_stealing_blocker',
        'stealing',
        'Blocker Item',
        'Enter the item ID of the Shop item that allows users to block other users from blocking them.',
        'text',
        '',
        ++$disporder
    );
    newpoints_add_setting(
        'newpoints_stealing_sendpm',
        'stealing',
        'Send PM Alerts',
        'Select whether or not PM alerts are sent. The content of the PMs can be changed in the language files.',
        'yesno',
        '1',
        ++$disporder
    );
    newpoints_add_setting(
        'newpoints_stealing_laststealers',
        'stealing',
        'Last Stealers',
        'Enter how many last stealers are displayed in the statistics.',
        'text',
        '10',
        ++$disporder
    );
    newpoints_add_setting(
        'newpoints_stealing_flood',
        'stealing',
        'Flood Check',
        'Enter how many seconds must pass before a user can steal again.',
        'text',
        '15',
        ++$disporder
    );
    newpoints_add_setting(
        'newpoints_stealing_maxpoints',
        'stealing',
        'Maximum Points',
        'Enter how many points users can try to steal from other users. Leave empty to disable the maximum.',
        'text',
        '500',
        ++$disporder
    );

    rebuild_settings();
}

function newpoints_stealing_is_installed()
{
    global $db;

    $q = $db->simple_select('newpoints_settings', '*', 'name=\'newpoints_stealing_cost\'');
    $s = $db->fetch_array($q);
    if (!empty($s)) {
        return true;
    }
    return false;
}

function newpoints_stealing_uninstall()
{
    global $db;

    // delete settings
    newpoints_remove_settings(
        "'newpoints_stealing_cost','newpoints_stealing_chance','newpoints_stealing_blocker','newpoints_stealing_sendpm','newpoints_stealing_laststealers','newpoints_stealing_maxpoints'"
    );
    rebuild_settings();

    newpoints_remove_log(['stealing_stole', 'stealing_blocked', 'stealing_failed', 'stealing_stolen_user']);
}

function newpoints_stealing_activate()
{
    global $db, $mybb;

    global $db;

    $query = $db->simple_select('newpoints_settings', 'sid', "plugin='newpoints_stealing'");

    while ($setting = $db->fetch_array($query)) {
        $db->update_query('newpoints_settings', ['plugin' => 'stealing'], "sid='{$setting['sid']}'");
    }

    newpoints_add_template(
        'newpoints_stealing',
        '
<html>
	<head>
		<title>{$lang->newpoints} - {$lang->newpoints_stealing}</title>
		{$headerinclude}
	</head>
	<body>
		{$header}
		<table width="100%" border="0" align="center">
			<tr>
				<td valign="top" width="180">
					<table border="0" cellspacing="{$theme[\'borderwidth\']}" cellpadding="{$theme[\'tablespace\']}" class="tborder">
						<tr>
							<td class="thead"><strong>{$lang->newpoints_menu}</strong></td>
						</tr>
						{$options}
					</table>
				</td>
				<td valign="top">
					{$inline_errors}
					<table border="0" cellspacing="{$theme[\'borderwidth\']}" cellpadding="{$theme[\'tablespace\']}" class="tborder">
						<tr>
							<td class="thead"><strong>{$lang->newpoints_stealing}</strong></td>
						</tr>
						<tr>
							<td class="trow1">{$lang->newpoints_stealing_info}</td>
						</tr>
						<tr>
							<td class="trow1" align="center">
								<form action="newpoints.php?action=stealing" method="post">
									<input type="hidden" name="my_post_key" value="{$mybb->post_code}" />
									<strong>{$lang->newpoints_stealing_points}:</strong><br />
									<input type="text" class="textbox" name="points" /><br />
									<br />
									<strong>{$lang->newpoints_stealing_victim}:</strong><br />
									<input type="text" class="textbox" name="username" id="username" /><br />
									<br />
									<input type="submit" name="submit" class="button" value="{$lang->newpoints_stealing_steal}" />
								</form>
							</td>
						</tr>
						{$stealing}
					</table>
				</td>
			</tr>
		</table>
		<link rel="stylesheet" href="{$mybb->asset_url}/jscripts/select2/select2.css">
		<script type="text/javascript" src="{$mybb->asset_url}/jscripts/select2/select2.min.js?ver=1804"></script>
		<script type="text/javascript">
		<!--
		if(use_xmlhttprequest == "1")
		{
			MyBB.select2();
			$("#username").select2({
				placeholder: "{$lang->search_user}",
				minimumInputLength: 2,
				multiple: false,
				ajax: { // instead of writing the function to execute the request we use Select2\'s convenient helper
					url: "xmlhttp.php?action=get_users",
					dataType: \'json\',
					data: function (term, page) {
						return {
							query: term, // search term
						};
					},
					results: function (data, page) { // parse the results into the format expected by Select2.
						// since we are using custom formatting functions we do not need to alter remote JSON data
						return {results: data};
					}
				},
				initSelection: function(element, callback) {
					var value = $(element).val();
					if (value !== "") {
						callback({
							id: value,
							text: value
						});
					}
				},
			});
		}
		// -->
		</script>
		{$footer}
	</body>
</html>'
    );

    newpoints_add_template(
        'newpoints_stealing_stats',
        '
<table border="0" cellspacing="{$theme[\'borderwidth\']}" cellpadding="{$theme[\'tablespace\']}" class="tborder">
<tr>
<td class="thead" colspan="4"><strong>{$lang->newpoints_stealing_laststealers}</strong></td>
</tr>
<tr>
<td class="tcat" width="30%"><strong>{$lang->newpoints_stealing_stealer}</strong></td>
<td class="tcat" width="30%"><strong>{$lang->newpoints_stealing_victim}</strong></td>
<td class="tcat" width="20%" align="center"><strong>{$lang->newpoints_stealing_amount}</strong></td>
<td class="tcat" width="20%" align="center"><strong>{$lang->newpoints_stealing_date}</strong></td>
</tr>
{$rows}
</table><br />'
    );

    newpoints_add_template(
        'newpoints_stealing_stats_row',
        '
<tr>
<td class="{$bgcolor}">{$row[\'user\']}</td>
<td class="{$bgcolor}">{$row[\'victim\']}</td>
<td class="{$bgcolor}" align="center">{$row[\'amount\']}</td>
<td class="{$bgcolor}" align="center">{$row[\'date\']}</td>
</tr>'
    );

    newpoints_add_template(
        'newpoints_stealing_stats_nodata',
        '
<tr>
<td class="trow1" width="100%" colspan="4">{$lang->newpoints_stealing_no_data}</td>
</tr>'
    );

    // edit templates
    newpoints_find_replace_templatesets(
        'newpoints_statistics',
        '#' . preg_quote('width="60%">') . '#',
        'width="60%">{$newpoints_stealing_stats}'
    );
}

function newpoints_stealing_deactivate()
{
    global $db, $mybb;

    newpoints_remove_templates(
        "'newpoints_stealing','newpoints_stealing_stats','newpoints_stealing_stats_row','newpoints_stealing_stats_nodata'"
    );

    // edit templates
    newpoints_find_replace_templatesets(
        'newpoints_statistics',
        '#' . preg_quote('{$newpoints_stealing_stats}') . '#',
        ''
    );
}

// show stealing in the list
function newpoints_stealing_menu(&$menu)
{
    global $mybb, $lang;
    newpoints_lang_load('newpoints_stealing');

    $menu[] = [
        'action' => 'stealing',
        'lang_string' => 'newpoints_stealing'
    ];
}

function newpoints_stealing_page()
{
    global $mybb, $db, $lang, $cache, $theme, $header, $templates, $plugins, $headerinclude, $footer, $options, $inline_errors;

    if ($mybb->input['action'] != 'stealing') {
        return;
    }

    $stealing = '';

    if (!$mybb->user['uid']) {
        error_no_permission();
    }

    newpoints_lang_load('newpoints_stealing');

    if ($mybb->request_method == 'post') {
        verify_post_check($mybb->input['my_post_key']);

        // Check points
        /*if($mybb->get_input('points', \MyBB::INPUT_FLOAT) > (float)$mybb->user['newpoints'])
        {
            error($lang->newpoints_stealing_not_enough_points);
        }*/

        // Check flood
        $q = $db->simple_select(
            'newpoints_log',
            '*',
            '(action=\'stealing_stole\' OR action=\'stealing_failed\' OR action=\'stealing_blocked\' OR \'stealing_stolen_user\') AND uid=' . (int)$mybb->user['uid'],
            ['order_by' => 'date', 'order_dir' => 'DESC', 'limit' => 1]
        );
        $log = $db->fetch_array($q);
        if (!empty($log) && $log['date'] > (TIME_NOW - (int)$mybb->settings['newpoints_stealing_flood'])) {
            error(
                $lang->sprintf(
                    $lang->newpoints_stealing_flood,
                    ($log['date'] - (TIME_NOW - (int)$mybb->settings['newpoints_stealing_flood']))
                )
            );
        }

        // Validate points maximum
        if ((float)$mybb->settings['newpoints_stealing_maxpoints'] != 0) {
            if ((float)$mybb->get_input(
                    'points',
                    MyBB::INPUT_FLOAT
                ) > (float)$mybb->settings['newpoints_stealing_maxpoints']) {
                error($lang->newpoints_stealing_over_maxpoints);
            }
        }

        if ((float)$mybb->get_input('points', MyBB::INPUT_FLOAT) <= 0) {
            error($lang->newpoints_stealing_invalid_points);
        }

        // Validate user
        $fields = ['uid', 'username', 'newpoints'];
        if (function_exists('newpoints_shop_get_item')) {
            $fields[] = 'newpoints_items';
        }
        $user = get_user_by_username($mybb->get_input('username'), ['fields' => $fields]);
        if (empty($user)) {
            error($lang->newpoints_stealing_invalid_user);
        }

        if ($user['uid'] == $mybb->user['uid']) {
            error($lang->newpoints_stealing_self);
        }

        // Do we have enough points?
        if ((float)$mybb->settings['newpoints_stealing_cost'] > (float)$mybb->user['newpoints']) {
            error($lang->newpoints_stealing_own_points);
        }

        // Does the victim have enough points?
        if ((float)$mybb->get_input('points', MyBB::INPUT_FLOAT) > (float)$user['newpoints']) {
            error($lang->newpoints_stealing_victim_points);
        }

        // Check if user has blocker item
        if (function_exists('newpoints_shop_get_item') && (int)$mybb->settings['newpoints_stealing_blocker'] > 0) {
            $useritems = @unserialize($user['newpoints_items']);
            if (!empty($useritems)) {
                // make sure we own the item
                $key = array_search((int)$mybb->settings['newpoints_stealing_blocker'], $useritems);
                if ($key !== false) {
                    // Remove item from user
                    unset($useritems[$key]);
                    sort($useritems);
                    $db->update_query(
                        'users',
                        ['newpoints_items' => $db->escape_string(serialize($useritems))],
                        'uid=\'' . (int)$user['uid'] . '\''
                    );

                    // Send PM to victim
                    send_pm([
                        'subject' => $lang->newpoints_stealing_pm_blocked_subject,
                        'message' => $lang->sprintf(
                            $lang->newpoints_stealing_pm_blocked_message,
                            $mybb->user['username'],
                            newpoints_format_points($mybb->get_input('points', MyBB::INPUT_FLOAT))
                        ),
                        'touid' => (int)$user['uid'],
                        'receivepms' => 1
                    ], 0, true);

                    // Log
                    log_add(
                        'stealing_blocked',
                        '',
                        $mybb->user['username'] ?? '',
                        (int)$mybb->user['uid'],
                        (float)$mybb->get_input('points', MyBB::INPUT_FLOAT),
                        (int)$user['uid']
                    );

                    error($lang->newpoints_stealing_blocked);
                }
            }
        }

        // Get money from user
        points_subtract(
            (int)$mybb->user['uid'],
            (float)$mybb->settings['newpoints_stealing_cost']
        );

        // Successful? Get points from victim
        $r = mt_rand(1, 100);
        if ((float)$r > (float)$mybb->settings['newpoints_stealing_chance']) {
            send_pm([
                'subject' => $lang->newpoints_stealing_pm_failed_subject,
                'message' => $lang->sprintf(
                    $lang->newpoints_stealing_pm_failed_message,
                    $mybb->user['username'],
                    newpoints_format_points($mybb->get_input('points', MyBB::INPUT_FLOAT))
                ),
                'touid' => (int)$user['uid'],
                'receivepms' => 1
            ], 0, true);

            // Log
            log_add(
                'stealing_failed',
                '',
                $mybb->user['username'] ?? '',
                (int)$mybb->user['uid'],
                (float)$mybb->get_input('points', MyBB::INPUT_FLOAT),
                (int)$user['uid']
            );

            error($lang->newpoints_stealing_failed);
        }

        // Success
        points_subtract(
            (int)$user['uid'],
            $mybb->get_input('points', MyBB::INPUT_FLOAT)
        );

        points_add_simple(
            (int)$mybb->user['uid'],
            $mybb->get_input('points', MyBB::INPUT_FLOAT)
        );

        // Send PM
        send_pm([
            'subject' => $lang->newpoints_stealing_pm_stolen_subject,
            'message' => $lang->sprintf(
                $lang->newpoints_stealing_pm_stolen_message,
                $mybb->user['username'],
                newpoints_format_points($mybb->get_input('points', MyBB::INPUT_FLOAT))
            ),
            'touid' => (int)$user['uid'],
            'receivepms' => 1
        ], 0, true);

        // Log
        log_add(
            'stealing_stolen_user',
            '',
            $user['username'] ?? '',
            (int)$user['uid'],
            (float)$mybb->get_input('points', MyBB::INPUT_FLOAT),
            (int)$mybb->user['uid'],
            0,
            0,
            LOGGING_TYPE_CHARGE
        );

        log_add(
            'stealing_stole',
            '',
            $mybb->user['username'] ?? '',
            (int)$mybb->user['uid'],
            (float)$mybb->get_input('points', MyBB::INPUT_FLOAT),
            (int)$user['uid'],
            0,
            0,
            LOGGING_TYPE_INCOME
        );

        //redirect($mybb->settings['bburl']."/newpoints.php?action=stealing", $lang->newpoints_stealing_redirect);
        error(
            $lang->sprintf(
                $lang->newpoints_stealing_success,
                newpoints_format_points($mybb->get_input('points', MyBB::INPUT_FLOAT)),
                htmlspecialchars_uni($user['username'])
            ),
            $lang->newpoints_stealing_success_title
        );
    }

    $lang->newpoints_stealing_info = $lang->sprintf(
        $lang->newpoints_stealing_info,
        newpoints_format_points((float)$mybb->settings['newpoints_stealing_cost']),
        number_format($mybb->settings['newpoints_stealing_chance'], 2),
        newpoints_format_points((float)$mybb->settings['newpoints_stealing_maxpoints'])
    );

    // output page
    output_page(eval(templates_get('stealing')));
}

function newpoints_stealing_stats()
{
    global $mybb, $db, $templates, $cache, $theme, $newpoints_stealing_stats, $rows, $lang;

    // load language
    newpoints_lang_load('newpoints_stealing');
    $rows = '';

    // build stats table
    $query = $db->simple_select(
        'newpoints_log',
        '*',
        'action=\'stealing_stole\'',
        [
            'order_by' => 'date',
            'order_dir' => 'DESC',
            'limit' => intval($mybb->settings['newpoints_stealing_laststealers'])
        ]
    );
    while ($row = $db->fetch_array($query)) {
        $bgcolor = alt_trow();
        $data = explode('-', $row['data']);

        // Stealer
        $link = build_profile_link(htmlspecialchars_uni($row['username']), intval($row['uid']));
        $row['user'] = $link;

        // Victim
        $q = $db->simple_select('users', 'username', 'uid=' . (int)$data[0]);
        $victim = $db->fetch_field($q, 'username');
        $row['victim'] = build_profile_link(htmlspecialchars_uni($victim), intval($data[0]));

        // Amount
        $row['amount'] = isset($data[1]) ? newpoints_format_points((float)$data[1]) : 0;

        // Date
        $row['date'] = my_date($mybb->settings['dateformat'], intval($row['date']), '', false);

        eval("\$rows .= \"" . $templates->get('newpoints_stealing_stats_row') . "\";");
    }

    if (!$rows) {
        eval("\$rows = \"" . $templates->get('newpoints_stealing_stats_nodata') . "\";");
    }

    eval("\$newpoints_stealing_stats = \"" . $templates->get('newpoints_stealing_stats') . "\";");
}

$plugins->add_hook('newpoints_logs_log_row', 'newpoints_stealing_logs_log_row');
function newpoints_stealing_logs_log_row()
{
    global $lang;
    global $log_data, $log_action, $log_primary, $log_secondary;

    newpoints_lang_load('newpoints_stealing');

    switch ($log_data['action']) {
        case 'stealing_failed':
        case 'stealing_stole':
        case 'stealing_stolen_user':
        case 'stealing_blocked':
            if (!empty($log_data['log_primary_id'])) {
                $donation_user_data = get_user($log_data['log_primary_id']);

                $log_primary = build_profile_link(
                    htmlspecialchars_uni($donation_user_data['username'] ?? ''),
                    $donation_user_data['uid'] ?? 0
                );
            }
            break;
    }

    if ($log_data['action'] === 'stealing_failed') {
        $log_action = $lang->newpoints_stealing_logging_failed;
    }

    if ($log_data['action'] === 'stealing_stole') {
        $log_action = $lang->newpoints_stealing_logging_stole;
    }

    if ($log_data['action'] === 'stealing_stolen_user') {
        $log_action = $lang->newpoints_stealing_logging_stolen_user;
    }

    if ($log_data['action'] === 'stealing_blocked') {
        $log_action = $lang->newpoints_stealing_logging_blocked;
    }
}

$plugins->add_hook('newpoints_logs_end', 'newpoints_stealing_logs_end');
function newpoints_stealing_logs_end()
{
    global $lang;
    global $action_types;

    newpoints_lang_load('newpoints_stealing');

    foreach ($action_types as $action_key => &$action_value) {
        if ($action_key === 'stealing_failed') {
            $action_value = $lang->newpoints_stealing_logging_failed;
        }

        if ($action_key === 'stealing_stole') {
            $action_value = $lang->newpoints_stealing_logging_stole;
        }

        if ($action_key === 'stealing_stolen_user') {
            $action_value = $lang->newpoints_stealing_logging_stolen_user;
        }

        if ($action_key === 'stealing_blocked') {
            $action_value = $lang->newpoints_stealing_logging_blocked;
        }
    }
}