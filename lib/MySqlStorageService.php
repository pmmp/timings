<?php

namespace Starlis\Timings;

use function assert;
use function htmlentities;
use function is_string;
use function strip_tags;

class MySqlStorageService{

	private \PDO $db;

	public function __construct(string $host, string $database, string $username, string $password){
		$this->db = new \PDO("mysql:host=$host;dbname=$database;charset=utf8", $username, $password);
		$this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$this->db->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
	}

	public function get(int $id) : ?string{
		$stmt = $this->db->prepare("SELECT data FROM timings WHERE ID=:ID");
		$stmt->bindParam(":ID", $id);
		$stmt->execute();
		$data = $stmt->fetchColumn();
		assert(is_string($data) || $data === false);

		return is_string($data) ? htmlentities(strip_tags($data)) : null;
	}

	public function set(string $data) : int{
		if(substr($data, 0, 9) !== "Minecraft") return -1;
		$stmt = $this->db->prepare("INSERT INTO timings (data) VALUES (:data)");
		$stmt->bindParam(':data', $data);
		$stmt->execute();
		return (int) $this->db->lastInsertId();
	}
}