<?php

namespace Starlis\Timings\Parser;

use Starlis\Timings\TimingResult;
use Starlis\Timings\TimingsReport;
use function arsort;
use function count;
use function explode;
use function htmlspecialchars_decode;
use function preg_match;
use function substr;
use function trim;
use function uasort;
use const BREAKDOWN_SUBKEY;

class Parser{
	/**
	 * @param TimingResult[] $timings
	 *
	 * @return TimingResult[]
	 */
	private static function sortTimings(array $timings) : array{
		uasort($timings, function(TimingResult $a, TimingResult $b) : int{
			return $b->timeNs <=> $a->timeNs;
		});
		foreach($timings as $timing){
			$timing->children = self::sortTimings($timing->children);
		}
		return $timings;
	}

	public static function buildTree(string $reportData) : TimingsReport{
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
				}elseif(preg_match('/^Plugin: (.+) Event: (.+)$/', $timingName, $matches) === 1){
					$overrideGroup = $matches[1];
					$timingName = "Event: " . $matches[2];
				}elseif(preg_match('/^Task: (.+) Runnable: (.+)$/', $timingName, $matches) === 1){
					$overrideGroup = $matches[1];
					$timingName = "Task: " . $matches[2];
				}

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

			$roots = self::sortTimings($roots);
		}else{
			$roots = null;
		}
		foreach($groups as $groupName => $groupTimings){
			$groups[$groupName] = self::sortTimings($groupTimings);
		}
		arsort($groupTotals);

		$sampleTimeNs = 0;
		$serverVersion = "unknown";
		$minecraftVersion = "unknown";

		if(preg_match('/(*ANYCRLF)^Sample time (\d+) \(([\d.]+s)\)$/mi', $reportData, $matches)){
			$sampleTimeNs = (int) $matches[1];
		}
		if(preg_match('/(*ANYCRLF)^# PocketMine-MP (.*)$/mi', $reportData, $matches)){
			$serverVersion = $matches[1];
		}
		if(preg_match('/^# Version (.*)$/mi', $reportData, $matches)){
			$minecraftVersion = $matches[1];
		}

		$fullServerTick = null;
		$serverTickUpdateCycle = null;
		$entityTicks = null;
		$playerTicks = null;

		//this uses group timings, to ensure we don't get any duplicates
		foreach($groups as $timingGroup){
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
		if($fullServerTick === null){
			throw new ParserException("Missing 'Full Server Tick' timing entry");
		}
		$numTicks = $serverTickUpdateCycle?->count ?? $fullServerTick->count;
		$activeTimeNs = $fullServerTick->timeNs;
		$entityTicks = $entityTicks?->count ?? 0;
		$playerTicks = $playerTicks?->count ?? 0;

		return new TimingsReport($roots, $groups, $groupTotals, $serverVersion, $minecraftVersion, $sampleTimeNs, $activeTimeNs, $numTicks, $entityTicks, $playerTicks);
	}
}