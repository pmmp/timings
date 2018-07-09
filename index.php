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

require_once __DIR__ . "/lib/util.php";
require_once __DIR__ . '/vendor/autoload.php';
// To make it a little harder to try to exploit the uploader, implement a closed source version
// of the security class if it exists, else fall back to the simple rules.
if (!empty($ini['custom_security'])) {
	// should error if misconfigured
	/** @noinspection PhpIncludeInspection */
	require_once $ini['custom_security'];
}
libxml_disable_entity_loader(true);
Timings::bootstrap();
