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

const BREAKDOWN_SUBKEY = 'Minecraft - Breakdown (counted by other timings, not included in total)  ';
global $buildTree;

class TimingResult{
	/**
	 * @var self[]
	 */
	public array $children = [];

	public function __construct(
		public string $name,
		public string $group,
		public int $count,
		public int $timeNs,
		public float $avgNs,
		public int $violations,
		public ?int $parentId,
		public int $timerId
	){}
}

function sortTimings(array $timings) : array{
	uasort($timings, function(TimingResult $a, TimingResult $b) : int{
		return $b->timeNs <=> $a->timeNs;
	});
	foreach($timings as $timing){
		$timing->children = sortTimings($timing->children);
	}
	return $timings;
}

/**
 * @param string $legacyData
 *
 * @return TimingResult[]
 * @phpstan-return array<int, TimingResult>
 */
function buildTree(string $legacyData) : array{
	$orphans = [];
	$parents = [];
	$group = "";
	foreach(explode("\n", $legacyData) as $line){
		$line = trim($line, "\r\n");
		if($line === ""){
			continue;
		}
		if($line[0] !== " "){
			if($line[0] === "#"){
				//ignore comments
				continue;
			}
			$group = trim($line);
			continue;
		}

		if (preg_match('/(*ANYCRLF)^(.+?) Time: (\d+) Count: (\d+) Avg: ([\d\.]+) Violations: (\d+) RecordId: (\d+) ParentRecordId: (\d+|none)(?: TimerId: (\d+))?$/m', $line, $matches) === 1) {
			[, $timingName, $timeNs, $count, $avg, $violations, $recordId, $parentRecordIdStr, $timerId] = $matches;
			$timingName = htmlspecialchars_decode(trim($timingName));

			$parentRecordId = $parentRecordIdStr === "none" ? null : (int) $parentRecordIdStr;
			$result = new TimingResult($timingName, $group, (int) $count, (int) $timeNs, (float) $avg, (int) $violations, $parentRecordId, (int) $timerId);
			if($parentRecordId === null){
				$parents[(int) $recordId] = $result;
			}else{
				$orphans[(int) $recordId] = $result;
			}
		}
	}
	$roots = $parents;
	while(true){

		$newParents = [];
		foreach($orphans as $recordId => $orphan){
			if(isset($parents[$orphan->parentId])){
				$parents[$orphan->parentId]->children[$recordId] = $orphan;
				$newParents[$recordId] = $orphan;
				unset($orphans[$recordId]);
			}
		}
		if(count($newParents) === 0){
			//we may have a circular reference or missing parent - or everything is fine, and we're done
			break;
		}
		$parents = $newParents;

	}

	return sortTimings($roots);
}

/**
 * @param int            $visibleRows
 * @param TimingResult[] $timings
 * @param string[]       $exclude
 *
 * @return mixed[]
 * @phpstan-return array{string, int}
 */
function generateTable(array $timings, string $plugin, float $ptotal, int $numTicks, ?float $sample, float $total, array $exclude, int $visibleRows) : array{
	$pctStyle = '';
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
		$totals = timeUnits($ptotal, 3);
	}
	$i = 0;
	$shown = 0;
	$rows = [];
	foreach ($timings as $time) {
		foreach(generateTableRow($time, $numTicks, $sample, $total, $time->name, $exclude, $i, $shown, $plugin, 0, $visibleRows) as $row){
			$rows[] = $row;
		}
	}

	ob_start();
	echo '<div class="timings-table-div">';
	echo <<<TITLE
<div class="title">
		<span>$plugin</span>
TITLE;
	if ($plugin != BREAKDOWN_SUBKEY){
		echo <<<TITLE
		<span>Total: $totals</span>
		<span class="$pctStyle">Pct: $pctStr</span>
TITLE;
	}
	if ($shown < $i) {
		echo "<span><button class='show_rest'>Expand all</button></span>";
	}
	echo <<<TITLE
</div>
TITLE;
	echo "<table class='timings-table'>";
	echo <<<HEADER
<tr>
	<th class="event-name-column"><span class="event-name">Event</span></th>
	<th class="metrics-column">Pct Total</th>
	<th class="metrics-column">Total</th>
	<th class="metrics-column">Avg</th>
	<th class="metrics-column">PerTick</th>
	<th class="metrics-column">Count</th>
	<th class="metrics-column">Violations</th>
</tr>
HEADER;

	foreach($rows as $row){
		echo $row;
	}
	echo "</table>";

	echo '</div>';
	return [ob_get_clean(), $shown];
}

/**
 * @param int|null       $visibleRows
 * @param TimingResult[] $timings
 * @param string[]       $exclude
 *
 * @return string[]
 */
function generateTableRow(TimingResult $time, int $numTicks, ?float $sample, float $total, string $event, array $exclude, int &$i, int &$shown, string $plugin, int $depth, int $visibleRows) : array{
	$i++;

	$isTreeTable = $depth > 0 || count($time->children) > 0;

	$avg = round($time->timeNs / $time->count, 3);
	$timesPerTick = round($time->count / $numTicks, 1);
	if($timesPerTick >= 1){
		$avg = $avg * $timesPerTick;
	}

	$countStr = amountUnits($time->count, 1);

	$pctTick = ($avg / 1000 / 1000 / 50) * 100;
	$pctTickStyle = pct($pctTick, 1 /*$count * 1000 / $numTicks*/, 50, 20, 10);
	$avgStr = timeUnits($avg);

	$timeStr = timeUnits($time->timeNs);
	$pctTotal = ($time->timeNs / ($sample ? $sample : $total)) * 100;
	$pctTotalStyle = pct($pctTotal, 1, 50, 20, 10);
	$pctTotalStr = number_format($pctTotal, 2) . '%';
	$origevent = $event;
	if(preg_match("/\.([a-zA-Z0-9\$_]+::.+)/s", $event, $em)){
		$event = $em[1];
	}
	$event = trim($event);
	$sevent = $event;
	if(in_array($event, $exclude) || substr($event, 0, 2) == "**"){
		$sevent = trim(substr($event, 2));
	}
	$sevent = preg_replace('/^Plugin: (.+) Event: (.+)$/', 'Event: $2', $sevent);
	$sevent = preg_replace('/^Task: (.+) Runnable: (.+)$/', 'Task: $2', $sevent);
	$sevent = htmlspecialchars($sevent);
	$sevent = str_replace(["\\", "/", "-&gt;", "::"], ["<wbr>\\", "<wbr>/", "<wbr>&#8209;&gt;", "<wbr>::"], $sevent);

	if($event == "Full Server Tick"){
		$sevent .= showInfo('fst', 'Full Server Tick');
		global $serverLoad, $serverLoadStr;
		$serverLoadStr = "<span class=\"$pctTickStyle\">" . number_format($pctTick, 2) . "%</span>";
		$serverLoad = $pctTick;
	}

	if($event == "** Connection Handler"){
		$sevent .= showInfo('connhandler', 'Connection Handler');
	}

	if($event == "** activatedTickEntity"){
		$sevent .= showInfo('ate', 'Activated Entities');
	}
	if($event == "Scheduler"){
		$sevent .= showInfo('sched', 'Plugin Scheduler');
	}
	$sevent = "<span class='event-name'>$sevent</span>";

	$hideBeyondDepth = 2;
	if($depth > $hideBeyondDepth || $pctTotal < 0.0003 || $i > $visibleRows){
		$hiddenelem = true;
		$rowClasses = " hidden";
	}else{
		$rowClasses = "";
		$hiddenelem = false;
		$shown++;
	}
	$title = $origevent;
	$children = count($time->children);
	if($isTreeTable){
		$indentSize = $depth;
		$sevent = "<span class='triangle-icon'><div></div></span>" . $sevent;
		if($children > 0){
			if($hiddenelem || $depth >= $hideBeyondDepth){
				$rowClasses .= " hidden-children children-hidden-by-default";
			}else{
				$rowClasses .= " visible-children";
			}
			$title = "$origevent ($children children)";
		}else{
			$rowClasses .= " no-children";
		}

		if($indentSize > 0){
			$sevent = "<span class='indent' style='width: " . $indentSize . "em'></span>" . $sevent;
		}
	}

	if(($i & 1) === 1){
		$rowStyle = "";//background-color: #dddddd;";
	}else{
		$rowStyle = "";
	}

	$timesPerTickStr = amountUnits($timesPerTick, 1);

	$violationsStyle = pct($time->violations, 1, $numTicks / (5 * 20), $numTicks / (30 * 20), 0);
	$violationsStr = amountUnits($time->violations, 1);

	$result = [];
	$result[] = <<<ROW
<tr class='event $rowClasses' style="$rowStyle" data-depth="$depth">
	<td class="event-name-column" title="$title">$sevent</td>
	<td class="metrics-column $pctTotalStyle" title="% of the sample time spent (see also: Total)">$pctTotalStr</td>
	<td class="metrics-column $pctTotalStyle" title="Total time spent">$timeStr</td>
	<td class="metrics-column $pctTickStyle" title="Average time spent when activated">$avgStr</td>
	<td class="metrics-column" title="Average number of occurrences per server tick">$timesPerTickStr</td>
	<td class="metrics-column" title="Total number of occurrences">$countStr</td>
	<td class="metrics-column $violationsStyle" title="Total number of ticks that took too long because of this event">$violationsStr</td>
</tr>
ROW;
	foreach($time->children as $child){
		foreach(generateTableRow($child, $numTicks, $sample, $total, $child->name, $exclude, $i, $shown, $plugin, $depth + 1, $visibleRows) as $row){
			$result[] = $row;
		}
	}
	return $result;
}

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
<div class="pageHeader">
	<br/>
	&copy; Aikar of <a href='http://ref.emc.gs/?gas=timingsphp' rel="nofollow">Empire Minecraft</a><br/>
	<a href="http://github.com/pmmp/timings" title="Source Code">[source]</a>
		Has timings helped you solve issues with performance? Consider
		<a
		href="https://www.paypal.com/cgi-bin/webscr?cmd=_xclick&business=payments%40starlis%2ecom&lc=US&item_name=Aikar%20Timings&no_note=0&currency_code=USD"><b>[donating]</b></a>
	<br/>
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
			<textarea id="uploadbox" name='data' cols="100" rows="8"></textarea><br/>
			<form type="hidden" name="browser" value="true">
				<input type='submit' value='Paste'/>
			</form>
		</form>
	</div>

	<?php

} else {
?>
<div id="reports" class="reports">
	<?php

	$spigotConfigPattern = "/&amp;amp;lt;spigotConfig&amp;amp;gt;(.*)&amp;amp;lt;\\/spigotConfig&amp;amp;gt;/ms";
	if (preg_match($spigotConfigPattern, $legacyData, $configMatch)) {
		$spigotConfig = $configMatch[1];
		$legacyData = preg_replace($spigotConfigPattern, "", $legacyData);
	}
	if (preg_match('/Sample time (.+?) \(/', $legacyData, $sampm)) {
		$sample = (float) $sampm[1];
	}else{
		$sample = null;
	}

	$report = [];
	$reportTotals = [BREAKDOWN_SUBKEY => 0, 'Minecraft' => 0];

	$current = null;
	$version = '';
	if (preg_match('/# PocketMine-MP (.*)/i', $legacyData, $m)) {
		$version = $m[1];
	}
	// legacy
	$exclude = array('entityAIJump', 'entityAILoot', 'entityAIMove',
		'entityTickRest', 'entityAI', 'entityBaseTick');

	$plugin = 'Minecraft';
	foreach (explode("\n", $legacyData) as $line) {
		if (empty($line)) continue;
		if ($line[0] != " " && $line[0] != "#") {
			$plugin = trim($line);
			if ($plugin == 'Custom Timings' || $plugin == "Minecraft - ** indicates it&#39;s already counted by another timing") {
				$plugin = 'Minecraft';
			}
			$report[$plugin] = [];
		} else if ($line[0] == " ") {
			if (preg_match('/(*ANYCRLF)^(.+?) Time: (\d+) Count: (\d+) Avg: ([\d\.]+) Violations: (\d+)/', $line, $m)) {


				[, $timingName, $timeNs, $count, $avg, $violations] = $m;
				$timingName = htmlspecialchars_decode(trim($timingName));
				$data = new TimingResult($timingName, (int) $count, (int) $count, (int) $timeNs, (float) $avg, (int) $violations, null, 0);
				if ($timingName == 'Player Tick' || $timingName == 'Connection Handler') {
					$timingName = '** Connection Handler';
				}
				if (isset($_GET['dev'])) {
					//print_r($m);
				}
				$pluginKey = $plugin;
				if (preg_match("/Plugin: (.*) Event:(.*)/", $timingName, $eventmatch)) {
					$xplugin = $eventmatch[1];
					$timingName = trim($eventmatch[2]);
					$pluginKey = trim($xplugin);
				}
				if (preg_match("/Task: (.*) Runnable: (.*)/", $timingName, $taskmatch)) {
					$xplugin = $taskmatch[1];
					$timingName = 'Task: ' . str_replace(':', ' ', preg_replace('/.*? Id\:\((.*)\)/', '\1', $taskmatch[2]));

					$pluginKey = trim($xplugin);
				}

				if (!in_array($timingName, $exclude) && substr($timingName, 0, 2) != "**") {
					if (!isset($report[$pluginKey][$timingName])) {
						$report[$pluginKey][$timingName] = $data;
					} else {
						$report[$pluginKey][$timingName]->timeNs += $data->timeNs;
						$report[$pluginKey][$timingName]->count += $data->count;
					}
					$tasks = '** Tasks';
					if (substr($timingName, 0, 5) == "Task:") {
						if (!isset($report[BREAKDOWN_SUBKEY][$tasks])) {
							$report[BREAKDOWN_SUBKEY][$tasks] = $data;
						} else {
							$report[BREAKDOWN_SUBKEY][$tasks]->timeNs += $data->timeNs;
							$report[BREAKDOWN_SUBKEY][$tasks]->timeNs += $data->count;
						}
					}
					if (!empty($timeNs)) {
						if(!isset($reportTotals[$pluginKey])){
							$reportTotals[$pluginKey] = 0;
						}
						$reportTotals[$pluginKey] += $data->timeNs;
					}
				} else {
					if (!isset($report[BREAKDOWN_SUBKEY][$timingName])) {
						$report[BREAKDOWN_SUBKEY][$timingName] = $data;
					} else {
						$report[BREAKDOWN_SUBKEY][$timingName]->timeNs += $data->timeNs;
						$report[BREAKDOWN_SUBKEY][$timingName]->timeNs += $data->count;
					}
				}
			}
		}
	}
	$reportTotals[BREAKDOWN_SUBKEY] = $reportTotals['Minecraft'] - 1;


	$total = 0;
	$numTicks = 0;
	$entityTicks = 0;
	$playerTicks = 0;
	$totalTimings = 0;

	/** @var TimingResult[][] $report */
	$report = array_sort($report, $reportTotals, SORT_DESC);
	foreach ($report as $plugin => $rep) {
		$rep = sortTimings($rep);
		$report[$plugin] = $rep;
		/** @var TimingResult[] $rep */
		foreach($rep as $k => $ent) {
			$totalTimings += $ent->count;

			if($k === 'Full Server Tick') {
				$total = $ent->timeNs;
			}
			if ($numTicks === 0 && (stristr($k, ' - entityBaseTick') || stristr($k, ' - entityTick') || $k == '** Full Server Tick') || $k == '** Server Tick Update Cycle') {
				$numTicks = max($ent->count, $numTicks);
			}
			if ($k == '** entityBaseTick' || $k == 'entityBaseTick' || $k == '** tickEntity') {
				$entityTicks = $ent->count;
			}
			if ($k == "** tickEntity - EntityPlayer") {
				$playerTicks = $ent->count;
			}
		}
	}
	if ($total !== 0) {
		$reportTotals["Minecraft"] = $total;
	}
	$recommendations = array();

	$numTicks = max(1, $numTicks);

	global $serverLoad, $serverLoadStr, $buildTree;
	$tree = buildTree($legacyData);
	if(count($tree) > 0){
		[$buffer, $shown] = generateTable($tree, "Minecraft (Tree View) - Click items to expand them", $reportTotals[$plugin], $numTicks, $sample, $total, $exclude, PHP_INT_MAX);
		echo $buffer;
	}
	foreach($report as $plugin => $timings){
		$visibleRows = 5;
		if($plugin === "Minecraft"){
			$visibleRows = 10;
		}
		[$buffer, $shown] = generateTable($timings, $plugin, $reportTotals[$plugin], $numTicks, $sample, $total, $exclude, $visibleRows);
		if($shown == 0){
			echo "<div class='hidden'>$buffer</div>";
		}else{
			echo $buffer;
		}
	}
	?>
	<button class="show_all">Toggle all hidden</button>
	<div class="footer">
		<a href="/?id=<?php echo $_GET['id'] ?? 0 ?>&amp;raw=1">View raw</a>
	</div>
	<?php
} ?>
</div>

<div style="display: none">
	<div id="info-connhandler" title="About Connection Handler">
		<b>Connection Handler</b> (previously labeled <b>Player Tick</b>) includes a wide variety of other things,
		such as sending network packets, handling incoming network packets, packet compression, and packet encryption.
		<br/><br/>
		Since received packets can trigger a wide variety of other events (e.g. <b>PlayerMoveEvent</b>, <b>PlayerInteractEvent</b>, <b>EntityDamageByEntityEvent</b>, <b>BlockPlaceEvent</b>, <b>BlockBreakEvent</b>), a high value for this entry might indicate
		performance problems in plugins, or it may mean that your server simply can't handle the number of players online.
		<br/><br/>
		Look for plugin event timings to see if any of them might be causing performance problems.
	</div>
	<div id="info-fst" title="About Full Server Tick">
		<b>Full Server Tick</b> is the total of all other timers. If this hits 100%, your server will begin losing TPS.
		<br/><br/>
		Full Server Tick cannot be improved directly. Look at other timers to find out where time is being spent.
	</div>
	<div id="info-sched" title="About Scheduler">
		<b>Scheduler</b> is the total of all Task timings. This includes all time spent running plugin tasks.
		Look at "Task: " entries to find out which tasks are taking the most time.
		<br/><br/>
		Note: Async Tasks are not counted here, since they run in a separate thread.
	</div>
</body>
</html>


<?php

function showInfo($id, $title) {
	return "<a class='learnmore' data-info='$id' title='$title' href='#'>[Learn More]</a></b>";
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
			<td class="metadataName">PocketMine-MP Version</td>
			<td><?php echo $version ?></td>
		</tr>
		<tr>
			<td class="metadataName">Sample time</td>
			<td><?php echo $sampleTimeS ?> s (Ticks: <?php echo $numTicks ?>)</td>
		</tr>
		<tr>
			<td class="metadataName">Total CPU time spent</td>
			<td><?php echo $totalTimeS ?> s</td>
		</tr>
	<?php
	$activatedPercent = 1;
	if ($entityTicks && $numTicks) {
		?>
		<tr>
			<td class="metadataName">Average Entities</td>
			<td><?php echo number_format($entityTicks / $numTicks, 2) ?></td>
		</tr>
		<?php
	}
	if ($playerTicks && $numTicks) {
		?>
		<tr>
			<td class="metadataName">Average Players</td>
			<td><?php echo number_format($playerTicks / $numTicks, 2) ?></td>
		</tr>
		<?php
	}
	if ($numTicks && $sample) {
		$desiredTicks = $sample / 1000 / 1000 / 1000 * 20;
		?>
		<tr>
			<td class="metadataName">Average TPS</td>
			<td><?php echo number_format($numTicks / $desiredTicks * 20, 2) ?></td>
		</tr>
		<?php
	}
	?>
		<tr>
			<td class="metadataName">Server Load</td>
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

function pct($pct, $mod = 1, $high = null, $med = null, $low = null) {
	if ($high !== null && $pct * $mod > $high) {
		return 'high-highlight';
	} elseif ($med !== null && $pct * $mod > $med) {
		return 'mid-highlight';
	} else if ($low !== null && $pct * $mod > $low) {
		return 'low-highlight';
	}

	return '';
}

function amountUnits(float $amount, int $precision = 2) : string{
	foreach([
		1000 * 1000 * 1000 * 1000 => "T",
		1000 * 1000 * 1000 => "B",
		1000 * 1000 => "M",
		1000 => "k",
	] as $factor => $unit){
		if($amount >= $factor){
			return number_format($amount / $factor, $precision) . $unit;
		}
	}
	return number_format($amount, $precision);
}
function timeUnits(float $nanoseconds, int $precision = 2) : string{
	foreach([
		1000 * 1000 * 1000 * 60 * 60 * 24 => "d",
		1000 * 1000 * 1000 * 60 * 60 => "h",
		1000 * 1000 * 1000 * 60 => "m",
		1000 * 1000 * 1000 => "s",
		1000 * 1000 => "ms",
		1000 => "μs",
	] as $factor => $unit){
		if($nanoseconds >= $factor){
			return number_format($nanoseconds / $factor, $precision) . " " . $unit;
		}
	}
	return number_format($nanoseconds, $precision) . " ns";
}

function pad($string, $len, $right = false) {
	return str_pad($string, $len, ' ', $right ? STR_PAD_RIGHT : STR_PAD_LEFT);
}

function array_sort(array $array, array $sortable_array, $order = SORT_ASC) {
	$new_array = array();

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

	return $new_array;
}

?>
