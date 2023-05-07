<?php

namespace Starlis\Timings;

class TimingsReport{
	public const BREAKDOWN_SUBKEY = 'Minecraft - Breakdown';

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
		public int $formatVersion,
		public ?array $tree,
		public array $groups,
		public array $groupTotals,
		public string $serverVersion,
		public string $minecraftVersion,
		public int $sampleTimeNs,
		public int $activeTimeNs,
		public int $numTicks,
		public int $entityTicks,
		public int $playerTicks
	){
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

	public function getAverageEntities() : float{
		return $this->entityTicks / $this->numTicks;
	}

	public function getAveragePlayers() : float{
		return $this->playerTicks / $this->numTicks;
	}
}
