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

use Starlis\Timings\Json\TimingsMaster;

class Timings {
	use Singleton;
	public $id;

	/**
	 * @var StorageService
	 */
	private $storage;

	public static function bootstrap() {
		$timings = self::getInstance();
		$timings->prepareData();
	}

	public function prepareData() {
		/**
		 * @var StorageService $storage
		 */

        $timingData = '';

        if (!empty($_GET['url'])) {
            $id = $_GET['url'];
            $storage = new LegacyStorageService();
            $id = util::sanitizeHex($id);
            $this->id = $id;
            $this->storage = $storage;
            $timingData = trim($storage->get($id));
        }

        LegacyHandler::load($timingData);
        exit;
	}
} 
