<?php

namespace Starlis\Timings;

class TimingResult{
	/** @var self[] */
	public array $children = [];

	public bool $selfRecord = false;

	public function __construct(
		public string $name,
		public string $group,
		public int $count,
		public int $timeNs,
		public int $violations,
		public ?int $parentId,
		public int $timerId,
		public ?int $ticks,
		public ?int $peakNs
	){}
}
