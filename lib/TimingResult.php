<?php

namespace Starlis\Timings;

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
		public int $violations,
		public ?int $parentId,
		public int $timerId
	){}
}