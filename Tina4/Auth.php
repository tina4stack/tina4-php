<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2018/05/30
 * Time: 22:24
 */

namespace Tina4;

use Nowakowskir\JWT\Exceptions\IntegrityViolationException;
use Nowakowskir\JWT\JWT;
use Nowakowskir\JWT\TokenDecoded;
use Nowakowskir\JWT\TokenEncoded;

/**
 * Class Auth for creating and validating secure tokens for use with the API layers
 * @package Tina4
 */
class Auth extends Data
{
    /**
     * The path where the website is served from
     * @var string
     */
    private $documentRoot;
    /**
     * Is the authentication configured, so it only runs once in a page load
     * @var bool
     */
    private $configured = false;
    /**
     * Private key read from the secrets folder
     * @var false|string
     */
    protected $privateKey;
    /**
     * Public key read from the secrets folder
     * @var false|string
     */
    protected $publicKey;

    /**
     * The auth constructor looks for a secrets folder and tries to generate keys for the site
     * @param string $documentRoot
     * @throws \Exception
     */
    public function __construct($documentRoot = "")
    {
        parent::__construct();
        $this->initSession();

        $this->documentRoot = $documentRoot;

        //Check security
        if (!file_exists($this->documentRoot . "secrets")) {
            $this->generateSecureKeys();
        }

        //Load secrets
        if (file_exists($this->documentRoot . "secrets" . DIRECTORY_SEPARATOR . "private.key")) {
            $this->privateKey = file_get_contents($this->documentRoot . "secrets" . DIRECTORY_SEPARATOR . "private.key");
        }
        if (file_exists($this->documentRoot . "secrets" . DIRECTORY_SEPARATOR . "public.pub")) {
            $this->publicKey = file_get_contents($this->documentRoot . "secrets" . DIRECTORY_SEPARATOR . "public.pub");
        }

        if (static::class === "Tina4\Auth") {
            $this->getToken("default");
        }
    }

    /**
     * Starts a php session
     */
    public function initSession()
    {
        //make sure the session is started
        if (session_status() === PHP_SESSION_NONE && !$this->configured ) {
            $this->configured = true;
            session_start();
        }
    }

    /**
     * Creates a secrets folder and generates keys for the application
     * @return bool
     */
    public function generateSecureKeys()
    {
        DebugLog::message("Generating Auth keys");
        if (file_exists($this->documentRoot . "secrets")) {
            DebugLog::message("Secrets folder exists already, please remove");
            return false;
        }
        if (!mkdir($concurrentDirectory = $this->documentRoot . "secrets") && !is_dir($concurrentDirectory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
        }
        `ssh-keygen -t rsa -b 4096 -m PEM -f secrets/private.key -q -N ""`;
        `chmod 600 secrets/private.key`;
        `openssl rsa -in secrets/private.key -pubout -outform PEM -out secrets/public.pub`;
        return true;
    }

    /**
     * Checks for $_SESSION["tokens"]
     */
    public function tokenExists()
    {
        if (isset($_SESSION["tina4:authToken"]) && $this->validToken($_SESSION["tina4:authToken"])) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Checks if an auth token in the header is valid against the session
     * @param $token
     * @param string $publicKey
     * @param string $encryption
     * @return bool
     */
    public function validToken($token, $publicKey = "", $encryption = JWT::ALGORITHM_RS256)
    {
        DebugLog::message("Validating token");
        $this->initSession();

        if (!empty($publicKey)) {
            $this->publicKey = $publicKey;
        }

        if (empty($this->publicKey))
        {
            return false;
        }

        $token = trim(str_replace("Bearer ", "", $token));

        if (isset($_SESSION["tina4:authToken"]) && empty($token)) {
            $token = $_SESSION["tina4:authToken"];
        }

        try {
            $tokenEncoded = new TokenEncoded($token);
        } catch (\Exception $e) {
            DebugLog::message("Encoded token input failed! " . $e->getMessage());
            return false;
        }

        try {
            $tokenEncoded->validate($this->publicKey, $encryption);
            $payLoad = $this->getPayLoad($token);

            if (time() > $payLoad["expires"]) {
                return false;
            } else {
                return true;
            }
        } catch (IntegrityViolationException $e) {
            // Handle token not trusted
            DebugLog::message("Validating {$token} failed!");
            return false;
        } catch (\Exception $e) {
            // Handle other validation exceptions
            DebugLog::message("Validating {$token} failed! " . $e->getMessage());
            return false;
        }
    }

    /**
     * Gets a session token
     * @return false|mixed
     */
    public function getSessionToken()
    {
        if (isset($_SESSION["tina4:authToken"]) && $this->validToken($_SESSION["tina4:authToken"])) {
            return $_SESSION["tina4:authToken"];
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
    public function validateAuth($request, $lastPath = null)
    {
        $this->initSession();

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
    public function clearTokens()
    {
        $this->initSession();
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
    public function getToken($payLoad = [], $privateKey = "", $encryption = JWT::ALGORITHM_RS256)
    {
        $this->initSession();

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

        $payLoad["expires"] = time() + TINA4_TOKEN_MINUTES*60;

        $tokenDecoded = new TokenDecoded([], $payLoad);

        if (!empty($this->privateKey)) {

            $tokenEncoded = $tokenDecoded->encode($this->privateKey, $encryption);
            $tokenString = $tokenEncoded->__toString();
            $_SESSION["tina4:authToken"] = $tokenString;
        } else {
            $tokenString = "secrets folder is empty of tokens";
        }
        return $tokenString;
    }

    /**
     * Gets the payload from token
     * @param $token
     * @param string $publicKey
     * @param string $encryption
     * @return array|false
     */
    public function getPayLoad($token, $publicKey = "", $encryption = JWT::ALGORITHM_RS256)
    {
        DebugLog::message("Getting token payload");

        if (!empty($publicKey)) {
            $this->publicKey = $publicKey;
        }

        try {
            $tokenEncoded = new TokenEncoded($token);
        } catch (\Exception $e) {
            DebugLog::message("Encoded token input failed! " . $e->getMessage());
            return false;
        }

        try {
            $tokenEncoded->validate($this->publicKey, $encryption);
        } catch (IntegrityViolationException $e) {
            // Handle token not trusted
            DebugLog::message("Validating {$token} failed!");
            return false;
        } catch (\Exception $e) {
            // Handle other validation exceptions
            DebugLog::message("Validating {$token} failed! " . $e->getMessage());
            return false;
        }

        $tokenDecoded = $tokenEncoded->decode();

        return $tokenDecoded->getPayload();
    }
}