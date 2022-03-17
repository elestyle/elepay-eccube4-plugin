<?php

namespace Plugin\Elepay\Service;

/**
 * Class LoggerService
 */
class LoggerService
{
    /**
     * ログヘッダー
     */
    const LOG_CHANNEL = 'elepay';
    const LOG_HEADER = '[elepay] ';

    /**
     * LoggerService constructor.
     */
    public function __construct()
    {
    }

    public function emergency($message, array $context = [])
    {
        logs(self::LOG_CHANNEL)->emergency(self::LOG_HEADER . $message, $context);
    }

    public function alert($message, array $context = [])
    {
        logs(self::LOG_CHANNEL)->alert(self::LOG_HEADER . $message, $context);
    }

    public function critical($message, array $context = [])
    {
        logs(self::LOG_CHANNEL)->critical(self::LOG_HEADER . $message, $context);
    }

    public function error($message, array $context = [])
    {
        logs(self::LOG_CHANNEL)->error(self::LOG_HEADER . $message, $context);
    }

    public function warning($message, array $context = [])
    {
        logs(self::LOG_CHANNEL)->warning(self::LOG_HEADER . $message, $context);
    }

    public function notice($message, array $context = [])
    {
        logs(self::LOG_CHANNEL)->notice(self::LOG_HEADER . $message, $context);
    }

    public function info($message, array $context = [])
    {
        logs(self::LOG_CHANNEL)->info(self::LOG_HEADER . $message, $context);
    }

    public function debug($message, array $context = [])
    {
        logs(self::LOG_CHANNEL)->debug(self::LOG_HEADER . $message, $context);
    }
}
