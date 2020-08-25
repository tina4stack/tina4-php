<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2018/05/30
 * Time: 22:24
 */
namespace Tina4;

/**
 * Class Auth for creating and validating secure tokens for use with the API layers
 * @package Tina4
 */
class Auth extends \Tina4\Data
{
    private  $documentRoot;
    private  $configured = false;

    /**
     * Auth constructor.
     * @param string $documentRoot
     * @throws \Exception
     */
    function __construct($documentRoot="", $lastPath="/")
    {
        parent::__construct();
        self::initSession();
        if ($lastPath !== "/auth/login" && $lastPath !== "/auth/validate") {
            $_SESSION["tina4:lastPath"] = $lastPath;
        }
        $this->documentRoot = $documentRoot;
        //Load secrets
    }

    function generateSecureKeys() {
        DebugLog::message("Generating Auth keys");
        if (file_exists($this->documentRoot."secrets")) {
            DebugLog::message("Secrets folder exists already, please remove");
            return false;
        }
        mkdir($this->documentRoot."secrets");
        `ssh-keygen -t rsa -b 4096 -m PEM -f secrets/private.key`;
        `chmod 600 secrets/private.key`;
        `openssl rsa -in private.key -pubout -outform PEM -out secrets/public.pub`;
        return true;
    }

    /**
     * Checks for $_SESSION["tokens"]
     */
    function tokenExists () {
        if (!$this->configured) {
            \Tina4\redirect("/auth/wizard");
        }
        if (isset($_SESSION["tina4:tokens"][$_SERVER["REMOTE_ADDR"]])) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Validate the request
     * @param $request
     * @param string $lastPath
     * @return string
     * @throws \Exception
     */
    function validateAuth ($request, $lastPath=null) {
        self::initSession();
        if (empty($lastPath) && !isset($_SESSION["tina4:lastPath"]) && !empty($_SESSION["tina4:lastPath"])) {
            $lastPath = $_SESSION["tina4:lastPath"];
        } else {
            $lastPath = "/";
        }

        return $lastPath;
    }

    /**
     * Clears the session auth tokens
     */
    function clearTokens () {
        self::initSession();
        if (isset($_SERVER["REMOTE_ADDR"])) {
            unset($_SESSION["tina4:tokens"]);
            unset($_SESSION["tina4:authToken"]);
        }
    }

    /**
     * Gets an auth token for validating against secure URLS for the session
     * @return string
     */
    public function getToken() {
        self::initSession();

        $token = "";
        $_SESSION["tina4:authToken"] = $token;
        session_write_close();
        return $token;
    }

    /**
     * Checks if an auth token in the header is valid against the session
     * @param $token
     * @return bool
     */
    public function validToken($token) {
        self::initSession();
        if (isset($_SESSION["tina4:authToken"]) && $_SESSION["tina4:authToken"] === $token) {
            return true;
        } else {
            \Tina4\DebugLog::message("Validating {$token} failed!");
            return false;
        }
    }

    /**
     * Starts a php session
     */
    public function initSession()
    {
        //make sure the session is started
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
    }
}