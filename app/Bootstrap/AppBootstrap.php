<?php
namespace App\Bootstrap;

use Dotenv\Dotenv;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use GuzzleHttp\Client;

class AppBootstrap {
    public static function init(): array {
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
        $dotenv->load();

        $enableDebug = ($_ENV['ENABLE_DEBUG_LOG'] ?? 'false') === 'true';

        $logger = new Logger('sync_logger');
        $logger->pushHandler(new StreamHandler(__DIR__ . '/../../logs/app.log'));

        $http = new Client();

        return [
            'logger' => $logger,
            'http' => $http,
            'env' => $_ENV,
            'debug' => $enableDebug
        ];
    }
}