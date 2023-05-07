<?php

use Starlis\Timings\Timings;

require dirname(__DIR__) . '/vendor/autoload.php';

ini_set('display_errors', '1');
Timings::updateDB();
echo "Done\n";
