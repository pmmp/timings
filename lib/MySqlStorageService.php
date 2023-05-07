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
		$stmt->execute();
		/** @var mixed[]|false $row */
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		if($row === false){
			$timestamp = 0;
			return null;
		}
		$data = $row["data"];
		assert(is_string($data));
		assert(is_int($row["timestamp"]));
		$timestamp = $row["timestamp"];

		return htmlentities(strip_tags($data));
	}

	/**
	 * @phpstan-return \Generator<int, int>
	 */
	public function getAll() : \Generator{
		$stmt = $this->db->prepare("SELECT ID FROM timings");
		$stmt->execute();
		while(($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false){
			assert(is_array($row));
			assert(is_int($row["ID"]));
			yield $row["ID"];
		}
	}

	public function set(
		string $data,
		string $serverVersion,
		int $sampleTimeNs,
		float $averageTPS,
		float $averageLoad,
		float $averageEntities,
		float $averagePlayers,
		int $formatVersion
	) : int{
		$stmt = $this->db->prepare(<<<'QUERY'
			INSERT INTO timings (
				data,
				serverVersion,
				sampleTimeNs,
				averageTPS,
				averageLoad,
				averageEntities,
				averagePlayers,
				formatVersion
			) VALUES (
				:data,
				:serverVersion,
				:sampleTimeNs,
				:averageTPS,
				:averageLoad,
				:averageEntities,
				:averagePlayers,
				:formatVersion
			)
		QUERY);
		$stmt->bindParam(':data', $data);
		$stmt->bindParam(':serverVersion', $serverVersion);
		$stmt->bindParam(':sampleTimeNs', $sampleTimeNs);
		$stmt->bindParam(':averageTPS', $averageTPS);
		$stmt->bindParam(':averageLoad', $averageLoad);
		$stmt->bindParam(':averageEntities', $averageEntities);
		$stmt->bindParam(':averagePlayers', $averagePlayers);
		$stmt->bindParam(':formatVersion', $formatVersion);

		$stmt->execute();
		return (int) $this->db->lastInsertId();
	}

	public function update(
		int $id,
		string $data,
		string $serverVersion,
		int $sampleTimeNs,
		float $averageTPS,
		float $averageLoad,
		float $averageEntities,
		float $averagePlayers,
		int $formatVersion
	) : bool{
		$stmt = $this->db->prepare(<<<'QUERY'
			UPDATE timings SET
				data = :data,
				serverVersion = :serverVersion,
				sampleTimeNs = :sampleTimeNs,
				averageTPS = :averageTPS,
				averageLoad = :averageLoad,
				averageEntities = :averageEntities,
				averagePlayers = :averagePlayers,
				formatVersion = :formatVersion
			WHERE ID = :ID
		QUERY);
		$stmt->bindParam(':data', $data);
		$stmt->bindParam(':serverVersion', $serverVersion);
		$stmt->bindParam(':sampleTimeNs', $sampleTimeNs);
		$stmt->bindParam(':averageTPS', $averageTPS);
		$stmt->bindParam(':averageLoad', $averageLoad);
		$stmt->bindParam(':averageEntities', $averageEntities);
		$stmt->bindParam(':averagePlayers', $averagePlayers);
		$stmt->bindParam(':formatVersion', $formatVersion);

		$stmt->bindParam(':ID', $id);

		return $stmt->execute();
	}
}
