<?php
/**
 * Created by PhpStorm.
 * User: andrevanzuydam
 * Date: 2018/05/30
 * Time: 22:24
 */
class Auth
{
    /**
     * Gets an auth token for validating against secure URLS for the session
     * @return string
     */
    public static function getToken() {
        self::initSession();
        if (isset($_SERVER["REMOTE_ADDR"])) {
            $token = md5($_SERVER["REMOTE_ADDR"].date("Y-m-d h:i:s"));
        } else {
            $token = md5(date("Y-m-d h:i:s"));
        }
        $_SESSION["tina4:authToken"] = $token;
        return $token;
    }

    /**
     * Checks if an auth token in the header is valid against the session
     * @param $token
     * @return bool
     */
    public static function validToken($token) {
        self::initSession();
        if (isset($_SESSION["tina4:authToken"]) && $_SESSION["tina4:authToken"] === $token) {
            return true;
        } else {
            error_log("Validating {$_SESSION["tina4:authToken"]} {$token} failed!");
            return false;
        }

    }

    public static function initSession()
    {
        //make sure the session is started
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
    }
}