<?php

namespace Starlis\Timings;

use function assert;
use function htmlentities;
use function is_int;
use function is_string;
use function strip_tags;

class MySqlStorageService{

	private \PDO $db;

	public function __construct(string $host, string $database, string $username, string $password){
		$this->db = new \PDO("mysql:host=$host;dbname=$database;charset=utf8", $username, $password);
		$this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$this->db->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
	}

	public function get(int $id, int &$timestamp) : ?string{
		$stmt = $this->db->prepare("SELECT data, UNIX_TIMESTAMP(timestamp) AS timestamp FROM timings WHERE ID=:ID");
		$stmt->bindParam(":ID", $id);
		if(!$stmt->execute()){
			$timestamp = 0;
			return null;
		}
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		$data = $row["data"];
		assert(is_string($data));
		assert(is_int($row["timestamp"]));
		$timestamp = $row["timestamp"];

		return htmlentities(strip_tags($data));
	}

	public function set(string $data) : int{
		$stmt = $this->db->prepare("INSERT INTO timings (data) VALUES (:data)");
		$stmt->bindParam(':data', $data);
		$stmt->execute();
		return (int) $this->db->lastInsertId();
	}
}
