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

global $reportData;

const BREAKDOWN_SUBKEY = 'Minecraft - Breakdown (counted by other timings, not included in total)  ';

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

class TimingsReport{
	public float $activeTimeNs;

	public int $numTicks;
	public int $entityTicks;
	public int $playerTicks;

	/**
	 * @param TimingResult[]|null                                $tree
	 * @param TimingResult[][]                                   $groups
	 * @param float[]                                            $groupTotals
	 *
	 * @phpstan-param array<int, TimingResult>|null              $tree
	 * @phpstan-param array<string, array<string, TimingResult>> $groups
	 * @phpstan-param array<string, float>                       $groupTotals
	 */
	public function __construct(
		public ?array $tree,
		public array $groups,
		public array $groupTotals,
		public string $serverVersion,
		public string $minecraftVersion,
		public float $sampleTimeNs,
	){
		$fullServerTick = null;
		$serverTickUpdateCycle = null;
		$entityTicks = null;
		$playerTicks = null;

		//this uses group timings, to ensure we don't get any duplicates
		foreach($this->groups as $timingGroup){
			foreach($timingGroup as $ent){
				match ($ent->name) {
					'Full Server Tick' => $fullServerTick = $ent,
					'Server Tick Update Cycle' => $serverTickUpdateCycle = $ent,
					'entityBaseTick', 'tickEntity', 'Entity Base Tick' => $entityTicks = $ent,
					'tickEntity - EntityPlayer', 'Entity Tick - Player' => $playerTicks = $ent,
					default => null,
				};
			}
		}
		$this->numTicks = $serverTickUpdateCycle?->count ?? $fullServerTick?->count ?? 0;
		$this->activeTimeNs = $fullServerTick?->timeNs ?? 0;
		$this->entityTicks = $entityTicks?->count ?? 0;
		$this->playerTicks = $playerTicks?->count ?? 0;
		if($this->activeTimeNs !== 0){
			//this is a hack to ensure we don't show the wrong total by adding up all the subtimings on reports where
			//tree timings are not available
			//for plugin timings we just hope for the best, and for breakdown timings we just don't show the total
			$this->groupTotals["Minecraft"] = $this->activeTimeNs;
		}
	}

	public function getServerLoad() : float{
		return $this->activeTimeNs / $this->sampleTimeNs * 100;
	}

	public function getAverageTPS() : float{
		$desiredTicks = $this->sampleTimeNs / 1_000_000_000 * 20;
		return $this->numTicks / $desiredTicks * 20;
	}
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

function cleanTimerName(string $name) : string{
	return preg_replace(
		[
			'/^Plugin: (.+) Event: (.+)$/',
			'/^Task: (.+) Runnable: (.+)$/'
		],
		[
			'Event: $2',
			'Task: $2'
		],
		$name
	);
}

/**
 * @param string $reportData
 *
 * @return TimingResult[]
 * @phpstan-return array<int, TimingResult>
 */
function buildTree(string $reportData) : TimingsReport{
	$orphans = [];
	$parents = [];
	$group = "";

	$groups = [];
	$groupTotals = [];

	foreach(explode("\n", $reportData) as $line){
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

		$overrideGroup = null;
		if(preg_match('/(*ANYCRLF)^(.+?) Time: (\d+) Count: (\d+) Avg: ([\d\.]+) Violations: (\d+)(?: RecordId: (\d+) ParentRecordId: (\d+|none) TimerId: (\d+))?$/m', $line, $matches) === 1){
			if(count($matches) === 6){
				[, $timingName, $timeNs, $count, $avg, $violations] = $matches;
				$parentRecordIdStr = "none";
				$recordId = 0;
				$timerId = 0;
			}else{
				[, $timingName, $timeNs, $count, $avg, $violations, $recordId, $parentRecordIdStr, $timerId] = $matches;
			}
			$timingName = htmlspecialchars_decode(trim($timingName));
			if(str_starts_with($timingName, "** ")){
				$timingName = substr($timingName, 3);
				$overrideGroup = BREAKDOWN_SUBKEY;
			}
			$timingName = cleanTimerName($timingName);

			$parentRecordId = $parentRecordIdStr === "none" ? null : (int) $parentRecordIdStr;
			$result = new TimingResult($timingName, $overrideGroup ?? $group, (int) $count, (int) $timeNs, (float) $avg, (int) $violations, $parentRecordId, (int) $timerId);
			if($parentRecordId === null){
				$parents[(int) $recordId] = $result;
			}else{
				$orphans[(int) $recordId] = $result;
			}

			if(isset($groups[$result->group][$result->name])){
				$groups[$result->group][$result->name]->count += $result->count;
				$groups[$result->group][$result->name]->timeNs += $result->timeNs;
			}else{
				$groups[$result->group][$result->name] = clone $result;
			}
			if($result->group !== BREAKDOWN_SUBKEY){
				$groupTotals[$result->group] = ($groupTotals[$result->group] ?? 0) + $result->timeNs;
			}
		}
	}

	if(count($orphans) !== 0){
		//this is a new timings report which has tree association metadata on the records

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

		$roots = sortTimings($roots);
	}else{
		$roots = null;
	}
	foreach($groups as $groupName => $groupTimings){
		$groups[$groupName] = sortTimings($groupTimings);
	}
	arsort($groupTotals);

	$sampleTimeNs = 0;
	$serverVersion = "unknown";
	$minecraftVersion = "unknown";

	if(preg_match('/(*ANYCRLF)^Sample time (\d+) \(([\d.]+s)\)$/mi', $reportData, $matches)){
		$sampleTimeNs = (float) $matches[1];
	}
	if(preg_match('/(*ANYCRLF)^# PocketMine-MP (.*)$/mi', $reportData, $matches)){
		$serverVersion = $matches[1];
	}
	if(preg_match('/^# Version (.*)$/mi', $reportData, $matches)){
		$minecraftVersion = $matches[1];
	}

	return new TimingsReport($roots, $groups, $groupTotals, $serverVersion, $minecraftVersion, $sampleTimeNs);
}

/**
 * @param TimingResult[] $timings
 * @param string[]       $exclude
 *
 * @return mixed[]
 * @phpstan-return array{string, int}
 */
function generateTable(array $timings, string $plugin, ?float $ptotal, int $numTicks, float $sample, float $total, array $exclude, int $visibleRows, float $ptotalHeatmapFactor) : array{
	$totalRows = 0;
	$shown = 0;
	$rows = [];
	foreach($timings as $time){
		foreach(generateTableRow($time, $numTicks, $sample, $total, $time->name, $exclude, $totalRows, $shown, $plugin, 0, $visibleRows) as $row){
			$rows[] = $row;
		}
	}
	$hidden = $totalRows - $shown;

	ob_start();
	echo "<div class='timings-table-border' data-hidden-rows='$hidden' data-expanded='0'>";
	echo <<<TITLE
<div class="title">
	<span>
		<span>$plugin</span>
TITLE;
	if($ptotal !== null){
		$pctStyle = '';
		$totals = 0;
		$pctStr = '';
		if($sample > 0){
			$pct = $ptotal / $sample;
			$pctStyle = heatmapColor($pct, $ptotalHeatmapFactor);
			$pctStr = number_format($pct * 100, 2) . '%';
			$totals = timeUnits($ptotal, 3);
		}

		echo <<<TITLE
		<span>Total: $totals</span>
		<span style="background-color: $pctStyle">Pct: $pctStr</span>
TITLE;
	}
	echo "</span>";
	if($hidden > 0 && $visibleRows === PHP_INT_MAX){
		echo "<span class='show-rest-span'><button class='show_rest'><span class='expand-all-text'>Expand all</span></button></span>";
	}
	echo <<<TITLE
</div>
TITLE;
	echo "<div class='timings-table-scroll'><table class='timings-table'>";
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
	if($hidden > 0 && $visibleRows !== PHP_INT_MAX){
		echo <<<FOOTER
<tr class="show-rest-row">
	<td colspan="7" class="show_rest show-rest-text">Show $hidden more rows</td>
</tr>
FOOTER;

	}
	echo "</table></div>";

	echo '</div>';
	return [ob_get_clean(), $shown];
}

function heatmapColor(float $amount, float $max) : string{
	$percentage = $amount / $max;
	if($percentage < 0.1){
		return "";
	}
	$hue = max(0, 1 - $percentage) * 60;
	return "hsl($hue, 90%, 70%);";
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

	$countStr = amountUnits($time->count, 1, 0);

	$pctTickStyle = "background-color: " . heatmapColor($avg, 1000 * 1000 * 50);
	$avgStr = timeUnits($avg);

	$timeStr = timeUnits($time->timeNs);
	$pctTotal = ($time->timeNs / ($sample ? $sample : $total)) * 100;
	$pctTotalStyle = "background-color: " . heatmapColor($pctTotal, 100);
	$pctTotalStr = number_format($pctTotal, 2) . '%';

	$learnMore = match ($event) {
		"Full Server Tick" => showInfo('fst', 'Full Server Tick'),
		"Connection Handler" => showInfo('connhandler', 'Connection Handler'),
		"Scheduler" => showInfo('sched', 'Plugin Scheduler'),
		default => ""
	};

	$cleanedEventName = str_replace(["\\", "/", "-&gt;", "::"], ["<wbr>\\", "<wbr>/", "<wbr>&#8209;&gt;", "<wbr>::"], htmlspecialchars($event));
	$eventNameCell = "<span class='event-name'>$cleanedEventName$learnMore</span>";

	$hideBeyondDepth = 2;
	if($depth > $hideBeyondDepth || $pctTotal < 0.0003 || $i > $visibleRows){
		$hiddenelem = true;
		$rowClasses = " hidden";
	}else{
		$rowClasses = "";
		$hiddenelem = false;
		$shown++;
	}
	$title = $event;
	$children = count($time->children);
	if($isTreeTable){
		$indentSize = $depth;
		$eventNameCell = "<span class='triangle-icon'><div></div></span>" . $eventNameCell;
		if($children > 0){
			if($hiddenelem || $depth >= $hideBeyondDepth){
				$rowClasses .= " hidden-children children-hidden-by-default";
			}else{
				$rowClasses .= " visible-children";
			}
			$title = "$event ($children children)";
		}else{
			$rowClasses .= " no-children";
		}

		if($indentSize > 0){
			$eventNameCell = "<span class='indent' style='width: " . $indentSize . "em'></span>" . $eventNameCell;
		}
	}

	$timesPerTickStr = amountUnits($timesPerTick, 1, 1);

	$violationsStyle = "background-color: " . heatmapColor($time->violations, $numTicks / (5 * 20));
	$violationsStr = amountUnits($time->violations, 1, 0);

	$result = [];
	$result[] = <<<ROW
<tr class='event $rowClasses' data-depth="$depth">
	<td class="event-name-column" title="$title">$eventNameCell</td>
	<td class="metrics-column" style="$pctTotalStyle" title="% of the sample time spent (see also: Total)">$pctTotalStr</td>
	<td class="metrics-column" style="$pctTotalStyle" title="Total time spent">$timeStr</td>
	<td class="metrics-column" style="$pctTickStyle" title="Average time spent when activated">$avgStr</td>
	<td class="metrics-column" title="Average number of occurrences per server tick">$timesPerTickStr</td>
	<td class="metrics-column" title="Total number of occurrences">$countStr</td>
	<td class="metrics-column" style="$violationsStyle" title="Total number of ticks that took too long because of this event">$violationsStr</td>
</tr>
ROW;
	foreach($time->children as $child){
		foreach(generateTableRow($child, $numTicks, $sample, $total, $child->name, $exclude, $i, $shown, $plugin, $depth + 1, $visibleRows) as $row){
			$result[] = $row;
		}
	}
	return $result;
}

?>
<!DOCTYPE html>
<html>
	<head>
		<meta charset="UTF-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<title>PocketMine-MP Timings Viewer</title>
		<link rel="stylesheet" href="legacy/timings.css"/>
		<script src="//ajax.googleapis.com/ajax/libs/jquery/2.1.1/jquery.min.js"></script>
		<link rel="stylesheet" href="//ajax.googleapis.com/ajax/libs/jqueryui/1.11.1/themes/smoothness/jquery-ui.css"/>
		<script src="//ajax.googleapis.com/ajax/libs/jqueryui/1.11.1/jquery-ui.min.js"></script>
		<script src="legacy/timings.js"></script>
		<meta name="robots" content="noindex">
	</head>
	<body>
		<div class="pageHeader">
			<img src="https://github.com/pmmp/PocketMine-MP/raw/stable/.github/readme/pocketmine.png" loading="eager"/>
			<br/>
			<h1>Timings Viewer</h1>
			&copy; Aikar of <a href='http://ref.emc.gs/?gas=timingsphp' rel="nofollow">Empire Minecraft</a> 2017<br/>
			&copy; <a href="https://github.com/pmmp">PMMP Team</a> 2017-2023<br/>

			<a href="http://github.com/pmmp/timings" title="Source Code">[source]</a><br/>
		</div>
		<?php

		if(!$reportData) {
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
		$spigotConfigPattern = "/&amp;amp;lt;spigotConfig&amp;amp;gt;(.*)&amp;amp;lt;\\/spigotConfig&amp;amp;gt;/ms";
		if(preg_match($spigotConfigPattern, $reportData, $configMatch)){
			$spigotConfig = $configMatch[1];
			$reportData = preg_replace($spigotConfigPattern, "", $reportData);
		}
		$report = buildTree($reportData);
		?>
		<div class="head">
			<table>
				<tr>
					<td class="metadataName">PocketMine-MP Version</td>
					<td><?php echo $report->serverVersion ?></td>
				</tr>
				<tr>
					<td class="metadataName">Sample time</td>
					<td><?php echo timeUnits($report->sampleTimeNs) ?> (Ticks: <?php echo $report->numTicks ?>)</td>
				</tr>
				<tr>
					<td class="metadataName">Total CPU time spent</td>
					<td><?php echo timeUnits($report->activeTimeNs) ?></td>
				</tr>
				<?php if($report->numTicks > 0){
					if($report->entityTicks > 0){
						?>
						<tr>
							<td class="metadataName">Average Entities</td>
							<td><?php echo number_format($report->entityTicks / $report->numTicks, 2) ?></td>
						</tr>
						<?php
					}
					if($report->playerTicks > 0){
						?>
						<tr>
							<td class="metadataName">Average Players</td>
							<td><?php echo number_format($report->playerTicks / $report->numTicks, 2) ?></td>
						</tr>
						<?php
					}
					if($report->sampleTimeNs > 0){
						$desiredTicks = $report->sampleTimeNs / 1000 / 1000 / 1000 * 20;
						?>
						<tr>
							<td class="metadataName">Average TPS</td>
							<td><?php echo number_format($report->getAverageTPS(), 2) ?></td>
						</tr>
						<?php
					}
				}
				?>
				<tr>
					<td class="metadataName">Server Load</td>
					<td>
						<span style="background-color: <?php echo heatmapColor($report->getServerLoad(), 100) ?>"><?php echo number_format($report->getServerLoad(), 2) ?>%</span>
					</td>
				</tr>
			</table>
		</div>
		<?php
		$recommendations = [];
		if($report->getServerLoad() < 95 && $report->getAverageTPS() < 19){
			$recommendations[] = [
				"<b>Notice: Your AVG TPS is less than 19 but server load is less than 95.</b><br/>",
				"This means that something (not the server's main thread) is hogging the CPU.",
				"This might be because of overloaded AsyncWorkers (plugins scheduling too many AsyncTasks or AsyncTasks running for too long),",
				"too much activity on the network, or something else might be running on the machine and hogging the CPU.",
				"You should check the machine's overall CPU usage to see if anything else might be using a lot of CPU."
			];
		}else if($report->getServerLoad() >= 97){
			$recommendations[] = ["<b>Your server is lagging because it is overloaded (97%+ Server Load). Try reducing View Distance if it is above 4.</b>"];
		}
		foreach($recommendations as $recommendation){
			?>
			<span class="recommendation">
				<?php
				echo implode("\n", $recommendation);
				?>
			</span>
			<?php
		}
		?>
		<div id="reports" class="reports">
			<?php

			$exclude = ['entityAIJump', 'entityAILoot', 'entityAIMove',
				'entityTickRest', 'entityAI', 'entityBaseTick'];

			$recommendations = [];

			if($report->tree !== null){
				[$buffer, $shown] = generateTable($report->tree, "Minecraft (Tree View)", $report->groupTotals["Minecraft"], $report->numTicks, $report->sampleTimeNs, $report->activeTimeNs, $exclude, PHP_INT_MAX, 1);
				echo $buffer;
			}
			$tableOrder = ["Minecraft" => true, BREAKDOWN_SUBKEY => true];
			foreach($report->groupTotals as $groupName => $total){
				if(!isset($tableOrder[$groupName])){
					$tableOrder[$groupName] = true;
				}
			}
			foreach($tableOrder as $groupName => $timings){
				$visibleRows = 5;
				$loadHeatmapFactor = 0.06;
				if($groupName === "Minecraft"){
					$visibleRows = 10;
					$loadHeatmapFactor = 1.0;
				}
				[$buffer, $shown] = generateTable($report->groups[$groupName], $groupName, $report->groupTotals[$groupName] ?? null, $report->numTicks, $report->sampleTimeNs, $report->activeTimeNs, $exclude, $visibleRows, $loadHeatmapFactor);
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
				<b>Connection Handler</b> (previously labeled
				<b>Player Tick</b>) includes a wide variety of other things,
				such as sending network packets, handling incoming network packets, packet compression, and packet encryption.
				<br/><br/>
				Since received packets can trigger a wide variety of other events (e.g. <b>PlayerMoveEvent</b>,
				<b>PlayerInteractEvent</b>, <b>EntityDamageByEntityEvent</b>, <b>BlockPlaceEvent</b>,
				<b>BlockBreakEvent</b>), a high value for this entry might indicate
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
		</div>
	</body>
</html>

<?php

function showInfo($id, $title){
	return "<a class='learnmore' data-info='$id' title='$title' href='#'>[Learn More]</a></b>";
}

function amountUnits(float $amount, int $dividedPrecision = 2, int $unitPrecision = 2) : string{
	foreach([
		1000 * 1000 * 1000 * 1000 => "T",
		1000 * 1000 * 1000 => "B",
		1000 * 1000 => "M",
		1000 => "k",
	] as $factor => $unit){
		if($amount >= $factor){
			return number_format($amount / $factor, $dividedPrecision) . $unit;
		}
	}
	return number_format($amount, $unitPrecision);
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

?>
