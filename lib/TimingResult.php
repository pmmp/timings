<?php

namespace Starlis\Timings;

use function max;

class TimingResult{
	/** @var self[] */
	public array $children = [];

	public bool $selfRecord = false;

	/**
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	public array $mergedRecords = [];

	public function __construct(
		public string $name,
		public string $group,
		public int $count,
		public int $timeNs,
		public int $violations,
		public ?string $parentId,
		public int $timerId,
		public ?int $ticks,
		public ?int $peakNs
	){}

	public function add(self $other, string $recordId) : void{
		$this->count += $other->count;
		$this->timeNs += $other->timeNs;
		$this->violations += $other->violations;
		if($other->peakNs !== null){
			$this->peakNs = $this->peakNs !== null ? max($this->peakNs, $other->peakNs) : $other->peakNs;
		}

		//different records may have been active on the same ticks, so we can't just add their ticksActive
		//together - force the table display to use total time / count instead
		$this->ticks = null;

		$this->mergedRecords[] = $recordId;
	}
}
