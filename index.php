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

use function error_get_last;
use function error_log;
use function http_response_code;
use function implode;
use function ob_end_clean;
use function register_shutdown_function;

require_once __DIR__ . '/vendor/autoload.php';
// To make it a little harder to try to exploit the uploader, implement a closed source version
// of the security class if it exists, else fall back to the simple rules.
if(!empty($ini['custom_security'])){
	// should error if misconfigured
	/** @noinspection PhpIncludeInspection */
	require_once $ini['custom_security'];
}

register_shutdown_function(function(){
	if(error_get_last() !== null && (error_get_last()["type"] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR)) !== 0){
		ob_end_clean();
		http_response_code(500);
		$errorId = implode("-", str_split(bin2hex(random_bytes(6)), 4));
		echo "<h1>An error occurred while processing your request</h1>";
		echo "<p>Please contact a site administrator with the following error ID: $errorId</p>";
		error_log("Error ID: $errorId", message_type: 4);
	}
});
Timings::bootstrap();
