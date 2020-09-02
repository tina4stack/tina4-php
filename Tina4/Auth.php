<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2018/05/30
 * Time: 22:24
 */
namespace Tina4;

use Nowakowskir\JWT\Exceptions\IntegrityViolationException;
use Nowakowskir\JWT\Exceptions\InvalidStructureException;
use Nowakowskir\JWT\TokenDecoded;
use Nowakowskir\JWT\TokenEncoded;
use Nowakowskir\JWT\JWT;

/**
 * Class Auth for creating and validating secure tokens for use with the API layers
 * @package Tina4
 */
class Auth extends \Tina4\Data
{
    private $documentRoot;
    private $configured = false;
    private $privateKey;
    private $publicKey;

    /**
     * Auth constructor.
     * @param string $documentRoot
     * @throws \Exception
     */
    function __construct($documentRoot="", $lastPath="/")
    {
        parent::__construct();
        self::initSession();

        $_SESSION["tina4:lastPath"] = $lastPath;

        $this->documentRoot = $documentRoot;

        //Load secrets
        if (file_exists($this->documentRoot."secrets".DIRECTORY_SEPARATOR."private.key")) {
            $this->privateKey = file_get_contents($this->documentRoot . "secrets" . DIRECTORY_SEPARATOR . "private.key");
        }
        if (file_exists($this->documentRoot."secrets".DIRECTORY_SEPARATOR."public.pub")) {
            $this->publicKey = file_get_contents($this->documentRoot . "secrets" . DIRECTORY_SEPARATOR . "public.pub");
        }
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
        `openssl rsa -in secrets/private.key -pubout -outform PEM -out secrets/public.pub`;
        return true;
    }

    /**
     * Checks for $_SESSION["tokens"]
     */
    function tokenExists () {
        if (isset($_SESSION["tina4:authToken"]) && $this->validToken($_SESSION["tina4:authToken"])) {
            return true;
        } else {
            return false;
        }
    }

    function getSessionToken() {
        if (isset($_SESSION["tina4:authToken"]) && $this->validToken($_SESSION["tina4:authToken"])) {
            return $_SESSION["tina4:authToken"];
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
            unset($_SESSION["tina4:authToken"]);
        }
    }

    /**
     * Gets an auth token for validating against secure URLS for the session
     * @param array $payLoad
     * @param string $privateKey
     * @param string $encryption
     * @return string
     */
    public function getToken($payLoad=[], $privateKey="", $encryption=JWT::ALGORITHM_RS256) {
        self::initSession();

        if (!empty($privateKey)) {
            $this->privateKey = $privateKey;
        }

        if (!is_array($payLoad) && !empty($payLoad)) {
            if (is_object($payLoad)) {
                $payLoad = (array)$payLoad;
            } else {
                $payLoad = ["value" => $payLoad];
            }
        }

        $tokenDecoded = new TokenDecoded([], $payLoad);

        $tokenEncoded = $tokenDecoded->encode($this->privateKey, JWT::ALGORITHM_RS256);
        $tokenString = $tokenEncoded->__toString();
        $_SESSION["tina4:authToken"] = $tokenString;
        session_write_close();
        return $tokenString;
    }

    /**
     * Checks if an auth token in the header is valid against the session
     * @param $token
     * @param string $publicKey
     * @param string $encryption
     * @return bool
     */
    public function validToken($token, $publicKey="", $encryption=JWT::ALGORITHM_RS256) {
        \Tina4\DebugLog::message("Validating token");
        self::initSession();

        if (!empty($publicKey)) {
            $this->publicKey = $publicKey;
        }

        $token = trim(str_replace("Bearer ", "", $token));

        if (isset($_SESSION["tina4:authToken"]) && empty($token)) {
            $token = $_SESSION["tina4:authToken"];
        }

        try {
            $tokenEncoded = new TokenEncoded($token);
        } catch (\Exception $e) {
            \Tina4\DebugLog::message("Encoded token input failed! ".$e->getMessage());
            return false;
        }

        try {
            $tokenEncoded->validate($this->publicKey, $encryption);
            return true;
        } catch (IntegrityViolationException $e) {
            // Handle token not trusted
            \Tina4\DebugLog::message("Validating {$token} failed!");
            return false;
        } catch (\Exception $e) {
            // Handle other validation exceptions
            \Tina4\DebugLog::message("Validating {$token} failed! ".$e->getMessage());
            return false;
        }
    }

    function getPayLoad($token, $publicKey="", $encryption=JWT::ALGORITHM_RS256) {
        \Tina4\DebugLog::message("Getting token payload");

        if (!empty($publicKey)) {
            $this->publicKey = $publicKey;
        }

        try {
            $tokenEncoded = new TokenEncoded($token);
        } catch (Exception $e) {
            \Tina4\DebugLog::message("Encoded token input failed! ".$e->getMessage());
            return false;
        }

        try {
            $tokenEncoded->validate($this->publicKey, $encryption);
        } catch (IntegrityViolationException $e) {
            // Handle token not trusted
            \Tina4\DebugLog::message("Validating {$token} failed!");
            return false;
        } catch (Exception $e) {
            // Handle other validation exceptions
            \Tina4\DebugLog::message("Validating {$token} failed! ".$e->getMessage());
            return false;
        }

        $tokenDecoded = $tokenEncoded->decode();

        return $tokenDecoded->getPayload();
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