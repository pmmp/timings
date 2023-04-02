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

class Timings{
	public static function bootstrap() : never{
		$filterOptions = [
			'options' => [
				'min_range' => 1
			],
		];

		$timingData = '';

		$mysqlHost = getenv('MYSQL_HOST');
		$mysqlDatabase = getenv('MYSQL_DATABASE');
		$mysqlUser = getenv('MYSQL_USER');
		$mysqlPassword = getenv('MYSQL_PASSWORD');

		if(!empty($_GET['id']) && filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, $filterOptions)){
			$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, $filterOptions);
			$storage = new MySqlStorageService($mysqlHost, $mysqlDatabase, $mysqlUser, $mysqlPassword);
			$timingData = trim($storage->get($id));
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
