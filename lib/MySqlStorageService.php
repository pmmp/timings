<?php

namespace Starlis\Timings;

class MySqlStorageService extends StorageService {
    public function __construct($host, $database, $username, $password) {
        $this->db = new \PDO("mysql:host=$host;dbname=$database;charset=utf8", $username, $password);
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
    }
	public function get($id) {
        $stmt = $this->db->prepare("SELECT data FROM timings WHERE ID=:ID");
        $stmt->bindParam(":ID", $id);
        $stmt->execute();
        $data = $stmt->fetchColumn();

        return util::sanitize($data);
    }

    public function set($data) {
        if (substr($data, 0, 9) !== "Minecraft") return -1;
        $stmt = $this->db->prepare("INSERT INTO timings (data) VALUES (:data)");
        $stmt->bindParam(':data', $data);
        $stmt->execute();
        return $this->db->lastInsertId();
    }
}