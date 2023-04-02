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

namespace Starlis\Timings;

class util{
	public static function sanitize(string $inp) : string{
		return htmlentities(strip_tags($inp));
	}
}

