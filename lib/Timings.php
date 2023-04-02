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

use function filter_var;
use function is_string;

class Timings{

	private static function getenv_string(string $name) : string{
		$var = getenv($name);
		if(!is_string($var)){
			throw new \RuntimeException("Environment variable $name is not set correctly");
		}
		return $var;
	}

	public static function bootstrap() : never{
		$filterOptions = [
			'options' => [
				'min_range' => 1
			],
		];

		$timingData = '';

		$mysqlHost = self::getenv_string('MYSQL_HOST');
		$mysqlDatabase = self::getenv_string('MYSQL_DATABASE');
		$mysqlUser = self::getenv_string('MYSQL_USER');
		$mysqlPassword = self::getenv_string('MYSQL_PASSWORD');

		if(!empty($_GET['id']) && ($id = filter_var($_GET['id'], FILTER_VALIDATE_INT, $filterOptions)) !== false){
			$id = (int) $id;
			$storage = new MySqlStorageService($mysqlHost, $mysqlDatabase, $mysqlUser, $mysqlPassword);
			$rawData = $storage->get($id);
			$timingData = $rawData !== null ? trim($rawData) : null;
		}else if($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_GET['upload']) && $_GET['upload'] === 'true'){
			$storage = new MySqlStorageService($mysqlHost, $mysqlDatabase, $mysqlUser, $mysqlPassword);
			$id = $storage->set($_POST['data']);
			if(!empty($_POST['browser']) && $_POST['browser'] !== 'true'){
				header('Content-Type: application/json');
				echo \json_encode(["id" => $id]);
				die();
			}
			header('Location: ?id=' . $id);
			die();
		}

		if(isset($_GET['raw'])){
			header('Content-Type: text/plain');
			echo $timingData;
			die();
		}

		$GLOBALS['reportData'] = $timingData;
		require_once "legacy/index.php";
		exit;
	}
}
