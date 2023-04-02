<?php

use Starlis\Timings\LegacyHandler;

require 'vendor/autoload.php';

if(count($argv) < 3){
	echo "Usage: php generate-report-cli.php <input file> <output file>\n";
	exit(1);
}

$raw = file_get_contents($argv[1]);
ob_start();
$GLOBALS['reportData'] = $raw;
require 'legacy/index.php';
file_put_contents($argv[2], ob_get_clean());