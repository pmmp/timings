<?php

namespace Starlis\Timings\Parser;

use Starlis\Timings\TimingResult;
use Starlis\Timings\TimingsReport;
use function arsort;
use function assert;
use function count;
use function explode;
use function htmlspecialchars_decode;
use function preg_match;
use function spl_object_id;
use function str_starts_with;
use function substr;
use function trim;
use function uasort;
use const PHP_INT_MAX;

class Parser{
	public const VERSION_INITIAL = 0;
	public const VERSION_PEAK_BORKED = 1;
	public const VERSION_PEAK_FIXED = 2;
	public const VERSION_THREAD_TIMINGS = 3;

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
		$threadId = "";

		$groups = [];
		$groupTotals = [];

		$formatVersion = self::VERSION_INITIAL;
		if(preg_match('/(*ANYCRLF)^# FormatVersion (\d+)$/mi', $reportData, $matches)){
			$formatVersion = (int) $matches[1];
		}

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
				if($formatVersion >= self::VERSION_THREAD_TIMINGS && preg_match('/ThreadId: (\d+)$/', $line, $matches) === 1){
					$threadId = trim($matches[1]);
				}else{
					$threadId = "";
				}
				continue;
			}

			$overrideGroup = null;
			//average is unused (we calculate it by dividing time by count anyway) and may not be present in newer reports
			if(preg_match('/(*ANYCRLF)^(.+?) Time: (\d+) Count: (\d+)(?: Avg: ([\d\.]+))? Violations: (\d+)(?: RecordId: (\d+) ParentRecordId: (\d+|none) TimerId: (\d+)(?: Ticks: (\d+) Peak: (\d+))?)?$/m', $line, $matches) === 1){
				if(count($matches) === 6){
					[, $timingName, $timeNs, $count, /* avg unused */, $violations] = $matches;
					$parentRecordIdStr = "none";
					$recordId = 0;
					$timerId = 0;
					$ticksActive = null;
					$peakTime = null;
				}elseif(count($matches) === 9){
					[, $timingName, $timeNs, $count, /* avg unused */, $violations, $recordId, $parentRecordIdStr, $timerId] = $matches;
					$ticksActive = null;
					$peakTime = null;
				}else{
					[, $timingName, $timeNs, $count, /* avg unused */, $violations, $recordId, $parentRecordIdStr, $timerId, $ticksActive, $peakTime] = $matches;
					if($formatVersion < self::VERSION_PEAK_FIXED){
						//peak was incorrectly calculated across all activations in a tick in older versions, rather than just the current activation
						$peakTime = null;
					}
				}
				$timingName = htmlspecialchars_decode(trim($timingName));
				if(str_starts_with($timingName, "** ")){
					$timingName = substr($timingName, 3);
					$overrideGroup = TimingsReport::BREAKDOWN_SUBKEY;
				}elseif(preg_match('/^Plugin: (.+) Event: (.+)$/', $timingName, $matches) === 1){
					$overrideGroup = $matches[1];
					$timingName = "Event: " . $matches[2];
				}elseif(preg_match('/^Task: (.+) Runnable: (.+)$/', $timingName, $matches) === 1){
					$overrideGroup = $matches[1];
					$timingName = "Task: " . $matches[2];
				}

				$parentRecordId = $parentRecordIdStr === "none" ? null : (int) $parentRecordIdStr;
				$result = new TimingResult(
					$timingName,
					$overrideGroup ?? $group,
					(int) $count,
					(int) $timeNs,
					(int) $violations,
					$parentRecordId !== null ? $threadId . ":" . $parentRecordId : null,
					(int) $timerId,
					$ticksActive !== null ? (int) $ticksActive : null,
					$peakTime !== null ? (int) $peakTime : null
				);
				if($parentRecordId === null){
					$parents[$threadId . ":" . $recordId] = $result;
				}else{
					$orphans[$threadId . ":" . $recordId] = $result;
				}

				if(isset($groups[$result->group][$result->name])){
					$groups[$result->group][$result->name]->add($result, $threadId . ":" . $recordId);
				}else{
					$groups[$result->group][$result->name] = clone $result;
				}
				if($result->group !== TimingsReport::BREAKDOWN_SUBKEY){
					$groupTotals[$result->group] = ($groupTotals[$result->group] ?? 0) + $result->timeNs;
				}
			}
		}

		if(count($orphans) !== 0){
			//this is a new timings report which has tree association metadata on the records

			$roots = self::compressAndConnectChildren($parents, []);

			$parents = $roots;
			while(true){
				$newParents = self::compressAndConnectChildren($orphans, $parents);
				foreach($newParents as $recordId => $newParent){
					unset($orphans[$recordId]);
					foreach($newParent->mergedRecords as $mergedRecordId){
						unset($orphans[$mergedRecordId]);
					}
				}

				foreach($parents as $parentId => $parent){
					if(count($parent->children) === 0){
						continue;
					}
					$self = clone $parent;
					$self->selfRecord = true;
					$self->name = "[self]";
					$self->peakNs = null; //we can't calculate this with the available data
					$self->children = [];
					$self->parentId = $parentId;

					foreach($parent->children as $child){
						$self->timeNs -= $child->timeNs;
						$self->violations -= $child->violations;
					}
					if($self->timeNs > 0){
						//the parent should not have been referencing itself, so using parent ID here should be fine
						assert(!isset($parent->children[$parentId]));
						$parent->children[$parentId] = $self;
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

		$serverVersion = "unknown";
		$minecraftVersion = "unknown";

		if(preg_match('/(*ANYCRLF)^Sample time (\d+) \(([\d.]+s)\)$/mi', $reportData, $matches)){
			$sampleTimeNs = (int) $matches[1];
		}else{
			throw new ParserException("Sample time not found");
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
				if($playerTicks === null && str_starts_with($ent->name, 'Entity Tick - Player')){
					$playerTicks = $ent;
				}
			}
		}
		if($fullServerTick === null){
			throw new ParserException("Missing 'Full Server Tick' timing entry");
		}
		$numTicks = $serverTickUpdateCycle?->count ?? $fullServerTick->count;
		$activeTimeNs = $fullServerTick->timeNs;
		$entityTicks = $entityTicks?->count ?? 0;
		$playerTicks = $playerTicks?->count ?? 0;

		return new TimingsReport($formatVersion, $roots, $groups, $groupTotals, $serverVersion, $minecraftVersion, $sampleTimeNs, $activeTimeNs, $numTicks, $entityTicks, $playerTicks);
	}

	/**
	 * Combines duplicate records with the same parents into a single record, and connects the children to the parents
	 *
	 * @param TimingResult[] $records
	 * @param TimingResult[] $parents
	 * @phpstan-param array<int, TimingResult> $records
	 * @phpstan-param array<int, TimingResult> $parents
	 *
	 * @return TimingResult[]
	 */
	private static function compressAndConnectChildren(array $records, array $parents) : array{
		$parentIndex = [];
		foreach($parents as $recordId => $parent){
			$parentIndex[$recordId] = $parent;
			foreach($parent->mergedRecords as $mergedRecordId){
				$parentIndex[$mergedRecordId] = $parent;
			}
		}

		/**
		 * @var TimingResult[][] $compressedRecordIndex
		 * @phpstan-var array<int, array<string, TimingResult>> $compressedRecordIndex
		 */
		$compressedRecordIndex = [];
		$compressedRecords = [];

		foreach($records as $recordId => $root){
			//null parent ID means this record is a report root
			if($root->parentId !== null){
				$parentRecord = $parentIndex[$root->parentId] ?? null;
				if($parentRecord === null){
					//parent is not in the current set - most likely belongs to a deeper level of the tree
					continue;
				}
			}else{
				$parentRecord = null;
			}
			$parentKey = $parentRecord !== null ? spl_object_id($parentRecord) : PHP_INT_MAX;

			$existingRoot = $compressedRecordIndex[$parentKey][$root->name] ?? null;
			if($existingRoot === null){
				$compressedRecordIndex[$parentKey][$root->name] = $root;
				$compressedRecords[$recordId] = $root;
				if($parentRecord !== null){
					$parentRecord->children[$recordId] = $root;
				}
			}else{
				$existingRoot->add($root, $recordId);
			}
		}

		return $compressedRecords;
	}
}
