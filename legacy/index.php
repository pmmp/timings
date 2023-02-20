<?php
/*
 * Aikar's Minecraft Timings Parser
 *
 * Written by Aikar <aikar@aikar.co>
 * http://aikar.co
 * http://starlis.com
 *
 * @license MIT
 */

global $legacyData;


$spigotConfigPattern = "/&amp;amp;lt;spigotConfig&amp;amp;gt;(.*)&amp;amp;lt;\\/spigotConfig&amp;amp;gt;/ms";
if (preg_match($spigotConfigPattern, $legacyData, $configMatch)) {
	$spigotConfig = $configMatch[1];
	$legacyData = preg_replace($spigotConfigPattern, "", $legacyData);
}
if (preg_match('/Sample time (.+?) \(/', $legacyData, $sampm)) {
	$sample = $sampm[1];
}
$subkey = 'Minecraft - Breakdown (counted by other timings, not included in total)  ';
$report = array($subkey => array('Total' => 0), 'Minecraft' => array('Total' => 0));
$current = null;
$version = '';
if (preg_match('/# PocketMine-MP (.*)/i', $legacyData, $m)) {
	$version = $m[1];
}
// legacy
$exclude = array('entityAIJump', 'entityAILoot', 'entityAIMove',
	'entityTickRest', 'entityAI', 'entityBaseTick');
foreach (explode("\n", $legacyData) as $line) {
	if (empty($line)) continue;
	if ($line[0] != " " && $line[0] != "#") {
		$plugin = trim($line);
		if ($plugin == 'Custom Timings' || $plugin == "Minecraft - ** indicates it&#39;s already counted by another timing") {
			$plugin = 'Minecraft';
		}
		$report[$plugin] = array();
		$current =& $report[$plugin];
	} else if ($line[0] == " ") {
		if (preg_match("/(.+?) Time: (\\d+) Count: (\\d+) Avg: /", $line, $m)) {
			array_shift($m);

			$active =& $current;
			$m[0] = trim($m[0]);
			if ($m[0] == 'Player Tick' || $m[0] == 'Connection Handler') {
				$m[0] = '** Connection Handler';
			}
			if (isset($_GET['dev'])) {
				//print_r($m);
			}
			if (preg_match("/Plugin: (.*) Event:(.*)/", $m[0], $eventmatch)) {
				$xplugin = $eventmatch[1];
				$m[0] = trim($eventmatch[2]);
				$active =& $report[trim($xplugin)];
			}
			if (preg_match("/Task: (.*) Runnable: (.*)/", $m[0], $taskmatch)) {
				$xplugin = $taskmatch[1];
				$m[0] = 'Task: ' . str_replace(':', ' ', preg_replace('/.*? Id\:\((.*)\)/', '\1', $taskmatch[2]));

				$active =& $report[trim($xplugin)];
			}

			$data = array(@$m[1], $m[2]);
			if (!in_array($m[0], $exclude) && substr($m[0], 0, 2) != "**") {
				if (!isset($current[@$m[0]])) {
					$active[$m[0]] = $data;
				} else {
					$active[$m[0]][0] += $m[1];
					$active[$m[0]][1] += $m[2];
				}
				$tasks = '** Tasks';
				if (substr($m[0], 0, 5) == "Task:") {
					if (!isset($report[$subkey][$tasks])) {
						$report[$subkey][$tasks] = $data;
					} else {
						$report[$subkey][$tasks][0] += $m[1];
						$report[$subkey][$tasks][1] += $m[2];
					}
				}
				if (!empty($m[1])) {
					@$active['Total'] += $m[1];
				}
			} else {
				if (!isset($report[$subkey][$m[0]])) {
					$report[$subkey][$m[0]] = $data;
				} else {
					$report[$subkey][$m[0]][0] += $m[1];
					$report[$subkey][$m[0]][1] += $m[2];
				}
			}
		}
	}
}
$report[$subkey]['Total'] = intval(@$report['Minecraft']['Total']) - 1;


$total = 0;
$numTicks = 0;
$entityTicks = 0;
$playerTicks = 0;
$totalTimings = 0;

$report = array_sort($report, 'Total', SORT_DESC);
foreach ($report as &$rep) {
	arsort($rep);
	array_walk($rep, function (&$ent, $k) use (&$totalTimings, &$total, &$entityTicks, &$numTicks, &$playerTicks) {
		if ($k == 'Total') {
			return;
		}
		$totalTimings += $ent[1];

		if($k === 'Full Server Tick') {
			$total = $ent[0];
		}
		if ($numTicks === 0 && (stristr($k, ' - entityBaseTick') || stristr($k, ' - entityTick') || $k == '** Full Server Tick') || $k == '** Server Tick Update Cycle') {
			$numTicks = max($ent[1], $numTicks);
		}
		if ($k == '** entityBaseTick' || $k == 'entityBaseTick' || $k == '** tickEntity') {
			$entityTicks = $ent[1];
		}
		if ($k == "** tickEntity - EntityPlayer") {
			$playerTicks = $ent[1];
		}
	});
}
if ($total !== 0) {
	$report["Minecraft"]["Total"] = $total;
}
$recommendations = array();

$numTicks = max(1, $numTicks);
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Aikar's Timings Viewer</title>
	<link rel="stylesheet" href="legacy/timings.css"/>
	<script src="//ajax.googleapis.com/ajax/libs/jquery/2.1.1/jquery.min.js"></script>
	<link rel="stylesheet" href="//ajax.googleapis.com/ajax/libs/jqueryui/1.11.1/themes/smoothness/jquery-ui.css"/>
	<script src="//ajax.googleapis.com/ajax/libs/jqueryui/1.11.1/jquery-ui.min.js"></script>
	<script src="legacy/timings.js"></script>
	<meta name="robots" content="noindex">
</head>
<body>
<?php echo '<!-- ' . $totalTimings . ' -->'; ?>
<div style="text-align: center;margin: auto">
	<div style="text-align:center;width: 310px;margin:auto;float: left">
		<br/>
		&copy; Aikar of <a href='http://ref.emc.gs/?gas=timingsphp' rel="nofollow">Empire Minecraft</a><br/>
		<a href="http://github.com/pmmp/timings" title="Source Code">[source]</a>
			Has timings helped you solve issues with performance? Consider
			<a
			href="https://www.paypal.com/cgi-bin/webscr?cmd=_xclick&business=payments%40starlis%2ecom&lc=US&item_name=Aikar%20Timings&no_note=0&currency_code=USD"><b>[donating]</b></a>
		<br/>

	</div>
</div>
<hr style="clear:left"/>
<?php

$head = ob_get_contents();
ob_end_clean();
/****************************
 * // BEGIN BODY PROCESSING //
 ****************************/


ob_start();
if (!$legacyData) {

	?>
	<div style="padding:50px;margin:auto;text-align: center">
		To use the Timings parser, please type <b>/timings paste</b> in game, console or RCON.
		It will then give you a link to view it on this page.<br/><br/>
		Or paste your timings output below

		<form id="paste" method='post' action="?upload=true">
			<br/>
			<textarea id="uploadbox" name='data' cols="100" rows="8"></textarea></br>
			<form type="hidden" name="browser" value="true">
			<input type='submit' value='Paste'/>
		</form>
	</div>

	<?php

} else {
?>
<div id="reports">
	<?php
	foreach ($report as $plugin => $timings) {
		$ptotal = $timings['Total'];
		$pctStyle = '';
		$pct = 0;
		$totals = 0;
		$pctStr = '';
		if ($sample) {
			$pct = $ptotal / ($sample ? $sample : $total);
			if ($plugin == 'Minecraft') {
				$pctStyle = pct($pct, 1, 70, 40, 20);
			} else {
				$pctStyle = pct($pct, 1, 6, 3, 1);
			}
			$pctStr = number_format($pct * 100, 2) . '%';
			$totals = number_format($ptotal / 1000 / 1000 / 1000, 3) . ' s';
		}
		unset($timings['Total']);
		ob_start();
		echo '<div>';
		echo <<<TITLE
<hr/>
<div class="title">
<table>
	<tr>
		<td>$plugin</td>
TITLE;
		if ($plugin != $subkey){
			echo <<<TITLE
		<td>Total: $totals</td>
		<td class="$pctStyle">Pct: $pctStr</td>
TITLE;
		}
		echo <<<TITLE
	</tr>
</table>
</div>
<hr/>
TITLE;
		echo "<table>";
		echo <<<HEADER
<tr>
	<th class="metrics-column">Pct Total</th>
	<th class="metrics-column">Pct Tick</th>
	<th class="metrics-column">Total</th>
	<th class="metrics-column">Avg</th>
	<th class="metrics-column">PerTick</th>
	<th class="metrics-column">Count</th>
	<th class="event-name-column">Event</th>
</tr>
HEADER;
		$i = 0;
		$hiddenelem = false;
		$shown = 0;
		foreach ($timings as $event => $time) {
			if ($time[1]) {
				$avg = round($time[0] / $time[1], 3);
			} else {
				$avg = 0;
			}
			$timesPerTick = round($time[1] / $numTicks, 1);
			if ($timesPerTick >= 1) {
				$avg = $avg * $timesPerTick;
			}

			$countStr = number_format($time[1] / 1000, 1) . 'k';

			$pctTick = ($avg / 1000 / 1000 / 50) * 100;
			$pctTickStyle = pct($pctTick, 1 /*$count * 1000 / $numTicks*/, 40, 15, 3);
			$pctTickStr = number_format($pctTick, 2) . '%';
			$avg = number_format($avg / 1000 / 1000, 2);

			$stime = number_format($time[0] / 1000 / 1000 / 1000, 2);
			$pctTotal = ($time[0] / ($sample ? $sample : $total)) * 100;
			$pctTotalStyle = pct($pctTotal, 1, 50, 20, 10);
			$pctTotalStr = number_format($pctTotal, 2) . '%';
			$origevent = $event;
			if (preg_match("/\.([a-zA-Z0-9\$_]+::.+)/s", $event, $em)) {
				$event = $em[1];
			}
			$event = trim($event);

			$sevent = "<b title='$origevent'>$event</b>";

			if (in_array($event, $exclude) || substr($event, 0, 2) == "**") {
				$sevent = "<b>" . trim(substr($event, 2)) . "</b>";
			}

			if ($event == "Full Server Tick") {
				$sevent = showInfo('fst', 'Full Server Tick');
				$serverLoadStr = "<span class=\"$pctTickStyle\">$pctTickStr</span>";
				$serverLoad = $pctTick;
			}

			if ($event == "** Connection Handler") {
				$sevent = showInfo('connhandler', 'Connection Handler');
			}

			if ($event == "** activatedTickEntity") {
				$sevent = showInfo('ate', 'Activated Entities');
			}
			if ($event == "Scheduler") {
				$sevent = showInfo('sched', 'Plugin Scheduler');
			}
			$i++;
			if (($plugin == $subkey && $i >= 11) || $pctTotal < 0.0003 || ($plugin != "Minecraft" && $i >= 6 && $plugin != $subkey)) {
				$disabled = " hidden";
				$hiddenelem = true;
			} else {
				$disabled = "";
				$shown++;
			}

			$timesPerTick = number_format($timesPerTick, $timesPerTick > 10 ? 0 : 1);
			echo <<<ROW
<tr class='event $disabled'>
	<td class="metrics-column $pctTotalStyle">$pctTotalStr</td>
	<td class="metrics-column $pctTickStyle">$pctTickStr</td>
	<td class="metrics-column">$stime s</td>
	<td class="metrics-column $pctTickStyle">$avg ms</td>
	<td class="metrics-column">$timesPerTick</td>
	<td class="metrics-column">$countStr</td>
	<td class="event-name-column">$sevent</td>
</tr>
ROW;
		}

		echo "</table>";
		if ($hiddenelem) {
			echo "<button class='show_rest'>Show rest...</button><br />";
		}
		echo '</div>';
		$buffer = ob_get_contents();
		ob_end_clean();
		if ($shown == 0) {
			echo "<div class='hidden'>$buffer</div>";
		} else {
			echo $buffer;
		}

	}
	}
	if ($legacyData) {
		?>
		<button onclick='$(".hidden").toggle()'>Toggle all hidden</button>
	<?php } ?>
</div>

<div style="display: none">
	<div id="info-connhandler" title="About Connection Handler">
		<b>Connection Handler</b> (previously labeled <b>Player Tick</b>) is a wide wrapper of many things
		involving processing a players incoming data to the server. This value being high does not represent a bug
		itself in "Connection Handler", but usually will include timings data from plugins too.
		<br/><br/>
		If you are seeing high values here, it could mean you have more players online than your
		server can support. It is important to remember that Minecraft gets slower every version
		update, and while you may of been able to support this many players in the past, you might
		not be able to anymore.<br/><br/>
		If you are using the player-shuffle setting (has a value other than 0) then that can cause extra lag here, and
		you should ensure that setting is 0.
		<br/><br/>
		Look for other timings such as PlayerMoveEvent, PlayerInteractEvent, PlayerBlockBreakEvent and
		PlayerBlockPlaceEvent.<br/><br/>Those having high timings will also be counted in this event, but they will be
		the problem.

		<br/><br/>There is very little other than player-shuffle (and a future setting) to reduce Connection Handler
		alone. You must simply lower your player count and ensure no plugins are being slow in the events listed above.
	</div>
	<div id="info-fst" title="About Full Server Tick">
		Full Server Tick is the best representation of your servers performance, in the Pct Tick Column. If this
		value hits 100%, then your server is unable to keep up and will begin losing TPS.

		There is no magical solution to improving Full Server Tick, it is merely provided to see a better summary of
		your overall server performance and you can improve it by improving other timings on your server such as
		entities and plugins.
	</div>

	<div id="info-ate" title="About Activated Entities">
		Spigot introduces a major feature called Entity Activation Range that lets you specify ranges away from a player
		that an entity will enter "inactive" state, meaning it will slow down its activity. Any inactive entity
		will reduce its performance cost by up to 95%! This can be a major savings in terms of performance on
		servers that have lots of entities.

		<br/><br/>
		With Entity Activation Range, it is no longer necessary to use ClearLagg to wipe out every entity on a schedule,
		as you can instead set the Misc setting for your world to be lower, such as 4. This will make items on the
		ground not cause you any lag!

		<br/><br/>
		Additionally, setting the animals setting lower to such as 12, will greatly reduce impact from animal farms.
		And finally, you can safely lower monsters to about 24 without any real noticable impact.
		<br/><br/>
		Lowering these settings will lower the "Active Entities" summary at the top of this report, and will give a much
		better TPS.

	</div>
	<div id="info-sched" title="About Scheduler">
		Scheduler accounts for all time spent processing Repeating and Single Synchronous tasks created by plugins. 100%
		of the timing spent here is due to a plugin, and you need to look at your plugins to identify what is making
		this timing total to this.
		<br/><br/>
		Async Tasks do not count on this entry. See all Task: Entries for your plugins to find a culprit.
	</div>
	<script type="text/javascript">
		function showInfo(btn) {
			$("#info-" + $(btn).attr('info')).dialog({width: "80%", modal: true});
		}
	</script>

</body>
</html>


<?php

function showInfo($id, $title) {
	return "<b>$title</b><button class='learnmore' info='$id' onclick='showInfo(this)' title='$title'>Learn More</button></b>";
}

$buffer = ob_get_contents();
ob_end_clean();
echo $head;

if ($legacyData) {
	echo "<span class='head'>";

	$sampleTimeS = 0;
	if ($sample) {
		$sampleTimeS = round($sample / 1000 / 1000 / 1000, 3);
	}
	$totalTimeS = round($total / 1000 / 1000 / 1000, 3);

	?>
		<table>
		<tr>
			<td><b>PocketMine-MP Version</b></td>
			<td><?php echo $version ?></td>
		</tr>
		<tr>
			<td><b>Sample time</b></td>
			<td><?php echo $sampleTimeS ?> s (Ticks: <?php echo $numTicks ?>)</td>
		</tr>
		<tr>
			<td><b>Total CPU time spent</b></td>
			<td><?php echo $totalTimeS ?> s</td>
		</tr>
	<?php
	$activatedPercent = 1;
	if ($entityTicks && $numTicks) {
		?>
		<tr>
			<td><b>Average Entities</b></td>
			<td><?php echo number_format($entityTicks / $numTicks, 2) ?></td>
		</tr>
		<?php
	}
	if ($playerTicks && $numTicks) {
		?>
		<tr>
			<td><b>Average Players</b></td>
			<td><?php echo number_format($playerTicks / $numTicks, 2) ?></td>
		</tr>
		<?php
	}
	if ($numTicks && $sample) {
		$desiredTicks = $sample / 1000 / 1000 / 1000 * 20;
		?>
		<tr>
			<td><b>Average TPS</b></td>
			<td><?php echo number_format($numTicks / $desiredTicks * 20, 2) ?></td>
		</tr>
		<?php
	}
	?>
		<tr>
			<td><b>Server Load</b></td>
			<td><?php echo $serverLoadStr ?></td>
		</tr>
	</table>
	<?php
	echo '</span><hr />';
        if (preg_match("#[\\d,\\.]+#", $serverLoad, $m)) {
                $serverLoad = str_replace(',', '', $m[0]);
                $avgTPS = $numTicks / $desiredTicks * 20;
                if ($serverLoad < 95 && $avgTPS < 19) {
                        $recommendations[] = "<b>Notice: Your AVG TPS is less than 19 but server load is less than 95." .
                                "<br />This means that something (not the server's main thread) is hogging the CPU." . 
				"<br />This might be because of overloaded AsyncWorkers (plugins scheduling too many AsyncTasks or AsyncTasks running for too long)," .
				"<br />too much activity on the network, or something else might be running on the machine and hogging the CPU." .
				"<br />You should check the machine's overall CPU usage to see if anything else might be using a lot of CPU.</b>";
                } else if ($serverLoad >= 99) {
                        $recommendations[] = "<b>Your server is lagging because it is overloaded (99%+ Server Load). Try reducing View Distance if it is above 4.</b>";
                }

        }

	if (!empty($recommendations)) {
		echo "<span style='color: red;display:block;margin: 5px 0'><br />";
		echo implode("<br />\n", $recommendations);
		echo "</span><br /><hr />";
	}
}

echo $buffer;

function pct($pct, $mod = 1, $high = 0, $med = 0, $low = 0) {
	if ($pct * $mod > $high && $high != 0) {
		return 'high-highlight';
	} elseif ($pct * $mod > $med && $med != 0) {
		return 'mid-highlight';
	} else if ($pct * $mod > $low && $low != 0) {
		return 'low-highlight';
	}

	return '';
}

function pad($string, $len, $right = false) {
	return str_pad($string, $len, ' ', $right ? STR_PAD_RIGHT : STR_PAD_LEFT);
}

function array_sort($array, $on, $order = SORT_ASC) {
	$new_array = array();
	$sortable_array = array();

	if (count($array) > 0) {
		foreach ($array as $k => $v) {
			if (is_array($v)) {
				foreach ($v as $k2 => $v2) {
					if ($k2 == $on) {
						$sortable_array[$k] = $v2;
					}
				}
			} else {
				$sortable_array[$k] = $v;
			}
		}

		switch ($order) {
			case SORT_ASC:
				asort($sortable_array);
				break;
			case SORT_DESC:
				arsort($sortable_array);
				break;
		}

		foreach ($sortable_array as $k => $v) {
			$new_array[$k] = $array[$k];
		}
	}

	return $new_array;
}

?>
