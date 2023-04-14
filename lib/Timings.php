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

use Starlis\Timings\Parser\Parser;
use function filter_var;
use function header;
use function http_response_code;
use function is_string;
use function json_encode;

class Timings{

	private static function getenv_string(string $name) : string{
		$var = getenv($name);
		if(!is_string($var)){
			throw new \RuntimeException("Environment variable $name is not set correctly");
		}
		return $var;
	}

	public static function bootstrap() : never{
		$mysqlHost = self::getenv_string('MYSQL_HOST');
		$mysqlDatabase = self::getenv_string('MYSQL_DATABASE');
		$mysqlUser = self::getenv_string('MYSQL_USER');
		$mysqlPassword = self::getenv_string('MYSQL_PASSWORD');

		if($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_GET['upload']) && $_GET['upload'] === 'true'){
			$storage = new MySqlStorageService($mysqlHost, $mysqlDatabase, $mysqlUser, $mysqlPassword);
			if(!isset($_POST['data'])){
				http_response_code(400);
				header('Content-Type: application/json');
				echo json_encode(["error" => "No data provided"]);
				die();
			}
			try{
				//validate the report before saving it
				Parser::buildTree($_POST['data']);
			}catch(\Exception $e){
				http_response_code(400);
				header('Content-Type: application/json');
				echo json_encode(["error" => "Failed to parse report: " . $e->getMessage()]);
				die();
			}
			$id = $storage->set($_POST['data']);
			if(!empty($_POST['browser']) && $_POST['browser'] !== 'true'){
				header('Content-Type: application/json');
				echo \json_encode(["id" => $id]);
				die();
			}
			header('Location: ?id=' . $id);
			die();
		}

		$filterOptions = [
			'options' => [
				'min_range' => 1
			],
		];
		if(!empty($_GET['id']) && ($id = filter_var($_GET['id'], FILTER_VALIDATE_INT, $filterOptions)) !== false){
			$id = (int) $id;
			$storage = new MySqlStorageService($mysqlHost, $mysqlDatabase, $mysqlUser, $mysqlPassword);
			$rawData = $storage->get($id);
			$timingData = $rawData !== null ? trim($rawData) : null;
			if($timingData === null){
				http_response_code(404);
				header('Content-Type: application/json');
				echo json_encode(["error" => "Report not found"]);
				die();
			}
			if(isset($_GET['raw'])){
				header('Content-Type: text/plain');
				echo $timingData;
				die();
			}

			$GLOBALS['reportData'] = $timingData;
			require_once "legacy/index.php";
		}

		require_once "legacy/index.php";
		exit;
	}
}
