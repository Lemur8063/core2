<?php
namespace Core2;


/**
 * Class JsonException
 * @package Core2
 */
class JsonException extends \Exception {

    /**
     * HttpException constructor.
     * @param string          $message
     * @param int|string|null $http_code
     */
    public function __construct(string $message, int $http_code = 400) {

        parent::__construct($message, $http_code);
    }
}