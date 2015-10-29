<?php
class Connection {
	public $db;
	private static $instance;

	protected function __construct(){

		/*
		$host = "localhost";
		$dbname = "unwdmi";
		$user = "root";
		$password = "root";
		*/
		$host = "based.rkallenkoot.nl";
		$dbname = "unwdmi";
		$user = "unwdmi";
		$password = "";

		$connectionString = "mysql:host={$host};dbname={$dbname};charset=utf8;connect_timeout=15";
		$this->db = new PDO($connectionString, $user, $password);

		// Set some default attributes
		$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
	}

	/**
	 * Returns the Singleton instance of this Connection class
	 */
	public static function getInstance(){
		if(!is_object(self::$instance)){
			self::$instance = new Connection();
		}
		return self::$instance;
	}

	/**
	 * Methods overwritten so Singleton cannot be Cloned or Unserialized
	 */
	private function __clone(){}
	private function __wakeup(){}

}
?>