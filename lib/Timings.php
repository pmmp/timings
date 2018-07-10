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

                $filterOptions = array(
                        'options' => array(
                                'min_range' => 1
                        ),
                );

                $timingData = '';

                $mysqlHost = getenv('MYSQL_HOST');
                $mysqlDatabase = getenv('MYSQL_DATABASE');
                $mysqlUser = getenv('MYSQL_USER');
                $mysqlPassword = getenv('MYSQL_PASSWORD');

                if (!empty($_GET['url']) && strlen($id) < 20 && preg_match('/[A-Za-z0-9+\/=]+/', $_GET['url'])) {
                        $id = $_GET['url'];
                        $storage = new LegacyStorageService();
                        $this->id = $id;
                        $this->storage = $storage;
                        $timingData = trim($storage->get($id));
                } else if(!empty($_GET['id']) && filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, $filterOptions)) {
                        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, $filterOptions);
                        $storage = new MySqlStorageService($mysqlHost, $mysqlDatabase, $mysqlUser, $mysqlPassword);
                        $this->id = $id ;
                        $this->storage = $storage;
                        $timingData = trim($storage->get($id));
                } else if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_GET['upload']) && $_GET['upload'] === 'true') {
                        $storage = new MySqlStorageService($mysqlHost, $mysqlDatabase, $mysqlUser, $mysqlPassword);
                        $id = $storage->set($_POST['data']);
                        if (!empty($_POST['browser']) && $_POST['browser'] !== 'true') {
                            header('Content-Type: application/json');
                            echo \json_encode(["id" => $id]);
                            die();
                        }
                        header('Location: ?id='.$id);
                        die();
                }

                LegacyHandler::load($timingData);
                exit;
        }
}
