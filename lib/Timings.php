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
use Starlis\Timings\Parser\ParserException;
use function file_get_contents;
use function filter_var;
use function getenv;
use function header;
use function http_response_code;
use function is_array;
use function is_string;
use function json_encode;
use function ob_end_flush;
use function ob_start;
use function trim;
use const FILTER_VALIDATE_INT;

class Timings{

	private static function getenv_string(string $name) : string{
		$var = getenv($name);
		if(!is_string($var)){
			throw new \RuntimeException("Environment variable $name is not set correctly");
		}
		return $var;
	}

	public static function updateDB() : void{
		$mysqlHost = self::getenv_string('MYSQL_HOST');
		$mysqlDatabase = self::getenv_string('MYSQL_DATABASE');
		$mysqlUser = self::getenv_string('MYSQL_USER');
		$mysqlPassword = self::getenv_string('MYSQL_PASSWORD');

		$storage = new MySqlStorageService($mysqlHost, $mysqlDatabase, $mysqlUser, $mysqlPassword);

		foreach($storage->getAll() as $id){
			$timestamp = 0;
			$accessToken = "";
			$data = $storage->get($id, $timestamp, $accessToken);
			if($data === null){
				continue;
			}

			try{
				$report = Parser::buildTree($data);
			}catch(ParserException $e){
				echo "Error parsing report $id: " . $e->getMessage() . "\n";
				continue;
			}
			if($storage->update(
				$id,
				$data,
				$report->serverVersion,
				$report->sampleTimeNs,
				$report->getAverageTPS(),
				$report->getServerLoad(),
				$report->getAverageEntities(),
				$report->getAveragePlayers(),
				$report->formatVersion
			)){
				echo "Updated report $id\n";
			}else{
				echo "Failed to update report $id\n";
			}
		}
	}

	private static function badRequestNoReturn(string $error) : never{
		if(!empty($_POST['browser']) && $_POST['browser'] !== 'true'){
			http_response_code(400);
			header('Content-Type: application/json');
			echo json_encode(["error" => $error]);
		}else{
			//user-readable error
			http_response_code(400);
			header('Content-Type: text/plain');
			echo "Bad Request: " . $error;
		}
		die();
	}

	public static function bootstrap() : never{
		$mysqlHost = self::getenv_string('MYSQL_HOST');
		$mysqlDatabase = self::getenv_string('MYSQL_DATABASE');
		$mysqlUser = self::getenv_string('MYSQL_USER');
		$mysqlPassword = self::getenv_string('MYSQL_PASSWORD');

		if($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_GET['upload']) && $_GET['upload'] === 'true'){
			$storage = new MySqlStorageService($mysqlHost, $mysqlDatabase, $mysqlUser, $mysqlPassword);
			if(isset($_FILES['reportFile'])){
				if(!is_array($_FILES['reportFile']) || !isset($_FILES['reportFile']['tmp_name']) || !is_string($_FILES['reportFile']['tmp_name'])){
					self::badRequestNoReturn("File upload requested but no file provided");
				}
				$timingData = file_get_contents($_FILES['reportFile']['tmp_name']);
				if($timingData === false){
					self::badRequestNoReturn("Failed to read uploaded file");
				}
			}elseif(isset($_POST['data'])){
				if(!is_string($_POST['data'])){
					self::badRequestNoReturn("Invalid uploaded data");
				}
				$timingData = $_POST['data'];
			}else{
				self::badRequestNoReturn("Neither paste nor file upload provided");
			}
			try{
				//validate the report before saving it
				$report = Parser::buildTree($timingData);
			}catch(\Exception $e){
				self::badRequestNoReturn("Failed to parse report: " . $e->getMessage());
			}
			[$id, $token] = $storage->set(
				$timingData,
				$report->serverVersion,
				$report->sampleTimeNs,
				$report->getAverageTPS(),
				$report->getServerLoad(),
				$report->getAverageEntities(),
				$report->getAveragePlayers(),
				$report->formatVersion,
				($_POST['private'] ?? null) === 'true'
			);
			if(!empty($_POST['browser']) && $_POST['browser'] !== 'true'){
				header('Content-Type: application/json');
				echo json_encode(["id" => $id, "access_token" => $token]);
				die();
			}
			header('Location: ?id=' . $id . '&access_token=' . $token);
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
			$timestamp = 0;
			$accessToken = "";
			$rawData = $storage->get($id, $timestamp, $accessToken);
			if($rawData === null){
				http_response_code(404);
				header('Content-Type: application/json');
				echo json_encode(["error" => "Report not found"]);
				die();
			}
			if($accessToken !== "" && (!isset($_GET["access_token"]) || $_GET["access_token"] !== $accessToken)){
				http_response_code(403);
				header('Content-Type: application/json');
				echo json_encode(["error" => "Incorrect or no access token provided"]);
				die();
			}
			$timingData = trim($rawData);
			if(isset($_GET['raw'])){
				header('Content-Type: text/plain');
				echo $timingData;
				die();
			}

			$GLOBALS['reportData'] = $timingData;
			$GLOBALS['reportTimestamp'] = $timestamp;
			$GLOBALS['reportId'] = $id;
			$GLOBALS['accessToken'] = $accessToken;
			ob_start();
			require_once "legacy/index.php";
			ob_end_flush();
			die();
		}

		ob_start();
		require_once "templates/index.html";
		ob_end_flush();
		exit;
	}
}
