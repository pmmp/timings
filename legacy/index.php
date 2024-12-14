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
global $reportTimestamp;

use Starlis\Timings\Parser\Parser;
use Starlis\Timings\Parser\ParserException;
use Starlis\Timings\TimingResult;
use Starlis\Timings\TimingsReport;

/**
 * @param TimingResult[] $timings
 * @param string[]       $exclude
 */
function generateTable(array $timings, string $plugin, ?float $ptotal, int $numTicks, float $sample, float $total, array $exclude, int $visibleRows, float $ptotalHeatmapFactor) : string{
	$totalRows = 0;
	$shown = 0;
	$rows = [];
	foreach($timings as $time){
		foreach(generateTableRow($time, $numTicks, $sample, $total, $exclude, $totalRows, $shown, 0, $visibleRows) as $row){
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
		<span>Pct: <span style="background-color: $pctStyle" class="highlighted-metric">$pctStr</span></span>
TITLE;
	}
	echo "</span>";
	if($hidden > 0 && $visibleRows === PHP_INT_MAX){
		echo <<<BUTTONS
<span class='show-rest-span'>
	<button class="show-hot-path">Expand hot path</button>
	<button class='show_rest'><span class='expand-all-text'>Expand all</span></button>
</span>
BUTTONS;
	}
	echo <<<TITLE
</div>
TITLE;
	echo "<div class='timings-table-scroll'><table class='timings-table'>";
	echo <<<HEADER
<tr>
	<th class="event-name-column"><span class="event-name">Event</span></th>
	<th class="metrics-column">% Total</th>
	<th class="metrics-column">Time ÷ Count</th>
	<th class="metrics-column">Violations</th>
	<th class="metrics-column">Peak</th>
	<th class="metrics-column">Count ÷ Ticks</th>
	<th class="metrics-column">Count</th>
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
	$buffer = ob_get_clean();
	if($buffer === false){
		throw new \LogicException("We enabled output buffering above, so this should never happen");
	}
	return $buffer;
}

function heatmapColor(float $amount, float $max) : string{
	$percentage = $amount / $max;
	if($percentage < 0.1){
		return "";
	}
	$hue = max(0, 1 - $percentage) * 60;
	return "hsl($hue, 90%, 70%);";
}

function getTotalDescendants(TimingResult $time) : int{
	$total = count($time->children);
	foreach($time->children as $child){
		$total += getTotalDescendants($child);
	}
	return $total;
}

/**
 * @param string[] $exclude
 *
 * @return string[]
 *
 * @phpstan-param-out int $i
 * @phpstan-param-out int $shown
 */
function generateTableRow(TimingResult $time, int $numTicks, ?float $sample, float $total, array $exclude, int &$i, int &$shown, int $depth, int $visibleRows) : array{
	$i++;

	$isTreeTable = $depth > 0 || count($time->children) > 0;

	$timesPerTick = $time->count / $numTicks;

	$avgPerTick = round($time->timeNs / $time->count, 3) * $timesPerTick;
	$avgPerTickStr = timeUnits($avgPerTick);

	$avgPerCount = $time->timeNs / $time->count;
	$avgPerCountStyle = "background-color: " . heatmapColor($avgPerCount, 1000 * 1000 * 50);
	$avgPerCountStr = timeUnits($avgPerCount);

	$countStr = amountUnits($time->count, 1, 0);

	if($time->peakNs !== null){
		$peakStyle = "background-color: " . heatmapColor($time->peakNs, 1000 * 1000 * 50);
		$peakStr = timeUnits($time->peakNs);
	}else{
		$peakStyle = "";
		$peakStr = "N/A";
	}

	$timeStr = timeUnits($time->timeNs);
	$pctTotal = ($time->timeNs / ($sample ? $sample : $total)) * 100;
	$pctTotalStyle = "background-color: " . heatmapColor($pctTotal, 100);
	$pctTotalStr = number_format($pctTotal, 2) . '%';

	$learnMore = match ($time->name) {
		"Full Server Tick" => showInfo('fst', 'Full Server Tick'),
		"Connection Handler" => showInfo('connhandler', 'Connection Handler'),
		"Scheduler" => showInfo('sched', 'Plugin Scheduler'),
		"Async Task Workers" => showInfo('async', 'Async Task Workers'),
		default => ""
	};
	if($time->selfRecord){
		$learnMore = showInfo('self', 'Self Timer Records');
	}

	$baseName = $time->name;
	if(count($time->mergedRecords) > 0){
		$baseName .= " (" . (count($time->mergedRecords) + 1) . " combined)";
	}
	$cleanedEventName = str_replace(["\\", "/", "-&gt;", "::"], ["<wbr>\\", "<wbr>/", "<wbr>&#8209;&gt;", "<wbr>::"], htmlspecialchars($baseName));
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
	$title = $cleanedEventName;
	$children = count($time->children);
	if($isTreeTable){
		$indentSize = $depth;
		$eventNameCell = "<span class='triangle-icon'><div></div></span>" . $eventNameCell;
		if($children > 0){
			$totalDescendents = getTotalDescendants($time);
			if($hiddenelem || $depth >= $hideBeyondDepth){
				$rowClasses .= " hidden-children children-hidden-by-default";
			}else{
				$rowClasses .= " visible-children";
			}
			$title = "$cleanedEventName ($children children, $totalDescendents total descendants)";
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
<tr class='event $rowClasses' data-depth="$depth" data-children="$children">
	<td class="event-name-column" title="$title">$eventNameCell</td>
	<td class="metrics-column" style="$pctTotalStyle" title="% of the sample time spent ($timeStr total, $avgPerTickStr per tick)">$pctTotalStr</td>
	<td class="metrics-column" style="$avgPerCountStyle" title="Average time per occurrence">$avgPerCountStr</td>
	<td class="metrics-column" style="$violationsStyle" title="Total number of ticks that took too long because of this event">$violationsStr</td>
	<td class="metrics-column" style="$peakStyle" title="The longest time spent by this timer in a single activation">$peakStr</td>
	<td class="metrics-column" title="Average number of occurrences per server tick">$timesPerTickStr</td>
	<td class="metrics-column" title="Total number of occurrences">$countStr</td>
</tr>
ROW;
	foreach($time->children as $child){
		foreach(generateTableRow($child, $numTicks, $sample, $total, $exclude, $i, $shown, $depth + 1, $visibleRows) as $row){
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
		<script src="https://ajax.googleapis.com/ajax/libs/jquery/2.1.1/jquery.min.js"></script>
		<link rel="stylesheet" href="https://ajax.googleapis.com/ajax/libs/jqueryui/1.11.1/themes/smoothness/jquery-ui.css"/>
		<script src="https://ajax.googleapis.com/ajax/libs/jqueryui/1.11.1/jquery-ui.min.js"></script>
		<script src="static/js/timings.js"></script>
		<meta name="robots" content="noindex">
		<link rel="stylesheet" href="static/css/timings.css"/>
	</head>
	<body>
		<div class="pageHeader">
			<img src="static/img/pocketmine-rgb.gif" loading="eager"/>
			<br/>
			<h1>Timings Viewer</h1>
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
			try{
				$report = Parser::buildTree($reportData);
			}catch(ParserException $e){
				?>
				<span class="recommendation">
					Sorry, this timings report appears to be invalid: <?php echo $e->getMessage() ?><br/>
					If this is incorrect, please submit an issue on our <a href="https://github.com/pmmp/timings/issues">Issues Page</a>.
				</span>
				<?php
				$report = null;
			}
			if($report !== null){
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
						<td class="metadataName">Main thread CPU time spent</td>
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
							$tps = $report->getAverageTPS();
							//10 TPS will be red, 20 will be normal
							$tpsHighlight = heatmapColor(10 - ($tps - 10), 10);
							?>
							<tr>
								<td class="metadataName">Average TPS</td>
								<td>
									<span class="highlighted-metric" style="background-color: <?php echo $tpsHighlight ?>"><?php echo number_format($tps, 2) ?></span>
								</td>
							</tr>
							<?php
						}
					}
					?>
					<tr>
						<td class="metadataName">Main Thread Load</td>
						<td>
							<span class="highlighted-metric" style="background-color: <?php echo heatmapColor($report->getServerLoad(), 100) ?>"><?php echo number_format($report->getServerLoad(), 2) ?>%</span>
						</td>
					</tr>
					<?php if(isset($reportTimestamp) && is_int($reportTimestamp)){
						?>
						<tr>
							<td class="metadataName">Submitted</td>
							<td><?php echo date("Y-m-d H:i:s P", $reportTimestamp) ?></td>
						</tr>
						<?php
					}
					?>
				</table>
				<div class="links">
					<a href="/?id=<?php echo $_GET['id'] ?? 0 ?>&amp;raw=1">View raw</a>
				</div>
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
			}elseif($report->getAverageTPS() < 19){
				$recommendations[] = [
					"<b>Your server is lagging because it is overloaded. Your server may have more players online than it can handle.</b>"
				];
			}
			if($report->sampleTimeNs < 60_000_000_000){
				$recommendations[] = [
					"<b>This report is very short. It may not be representative of your server's actual performance.</b><br/>",
					"Timings should be run for at least 1 minute to gather useful data.",
				];
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
					echo generateTable($report->tree, "Minecraft (Tree View)", $report->groupTotals["Minecraft"], $report->numTicks, $report->sampleTimeNs, $report->activeTimeNs, $exclude, PHP_INT_MAX, 1);
				}
				$tableOrder = ["Minecraft" => true, TimingsReport::BREAKDOWN_SUBKEY => true];
				foreach($report->groupTotals as $groupName => $total){
					if(!isset($tableOrder[$groupName])){
						$tableOrder[$groupName] = true;
					}
				}
				foreach($tableOrder as $groupName => $timings){
					if(!isset($report->groups[$groupName])){
						continue;
					}
					$visibleRows = 5;
					$loadHeatmapFactor = 0.06;
					if($groupName === "Minecraft"){
						$visibleRows = 10;
						$loadHeatmapFactor = 1.0;
					}
					$groupTitle = $groupName;
					$groupTotal = $report->groupTotals[$groupName] ?? null;
					if($groupTotal === null){
						$groupTitle .= " (counted by other timings)";
					}
					echo generateTable($report->groups[$groupName], $groupTitle, $groupTotal, $report->numTicks, $report->sampleTimeNs, $report->activeTimeNs, $exclude, $visibleRows, $loadHeatmapFactor);
				} ?>
			</div>
				<?php
			}
		} ?>
		<div class="footer">
			&copy; Aikar of <a href='http://ref.emc.gs/?gas=timingsphp' rel="nofollow">Empire Minecraft</a> 2017<br/>
			&copy; <a href="https://github.com/pmmp">PMMP Team</a> 2017-2023<br/>

			<a href="http://github.com/pmmp/timings" title="Source Code">[source]</a><br/>
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
			<div id="info-self" title="Self Timings">
				<b>Self Timings</b> account for time when the parent timer was active, but none of its child timers were
				active. For example, <b>Entity Movement</b> might use specialized timers to cover certain parts of the
				movement code, leaving the remainder to self timings.
			</div>
			<div id="info-async" title="Async Task Workers">
				This timer accounts for the combined CPU time spent by all server Async Task worker threads. This is
				subdivided into timings for individual async tasks.
				High numbers here don't directly affect TPS and usually aren't a cause for concern. However, slow async
				tasks can cause other problems, such as delayed terrain loading.
				<br/><br/>
				You might see very large Pct Total numbers here. A value of 100% means that async tasks are maxing out
				1 CPU core on average. It will be larger than 100% if multiple workers (and therefore multiple CPU
				cores) are being used. For example, if you have 4 async workers and they are all maxed out, you would
				see a value close to 400%.
			</div>
		</div>
	</body>
</html>

<?php

function showInfo(string $id, string $title) : string{
	return "<a class='learnmore' data-info='$id' title='$title' href='#'>[?]</a></b>";
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
