<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

use Nowakowskir\JWT\Exceptions\IntegrityViolationException;
use Nowakowskir\JWT\JWT;
use Nowakowskir\JWT\TokenDecoded;
use Nowakowskir\JWT\TokenEncoded;

/**
 * For creating and validating secure tokens for use with the API layers
 * @package Tina4
 * @tests tina4
 *   assert session_status() !== PHP_SESSION_NONE, "Session should not be active"
 *   assert $configured === true, "Configured is supposed to be true"
 */
class Auth extends Data
{
    /**
     * The path where the website is served from
     * @var string
     */
    public string $documentRoot;
    /**
     * Is the authentication configured, so it only runs once in a page load
     * @var bool
     */
    public $configured = false;
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
     * @tests tina4
     *   assert $configured === true, "Auth state of configured must be true"
     */
    public function __construct()
    {
        parent::__construct();
        $this->initSession();

        //Check security

        if (!file_exists($this->documentRoot . "secrets/private.key")) {
            $this->generateSecureKeys();
        }


        if (!file_exists($this->documentRoot . "secrets" . DIRECTORY_SEPARATOR . ".htaccess")) {
            file_put_contents($this->documentRoot . "secrets" . DIRECTORY_SEPARATOR . ".htaccess", "Deny from all");
        }

        //Load secrets
        if (file_exists($this->documentRoot . "secrets" . DIRECTORY_SEPARATOR . "private.key")) {
            $this->privateKey = file_get_contents($this->documentRoot . "secrets" . DIRECTORY_SEPARATOR . "private.key");
        } else {
            die("OpenSSL is probably not installed or secrets/private.key is missing, please run this on your project root : <pre>ssh-keygen -t rsa -b 1024 -m PEM -f secrets/private.key -q -N \"\"</pre>");
        }

        if (file_exists($this->documentRoot . "secrets" . DIRECTORY_SEPARATOR . "public.pub")) {
            $this->publicKey = file_get_contents($this->documentRoot . "secrets" . DIRECTORY_SEPARATOR . "public.pub");
        } else {
            die("OpenSSL is probably not installed or secrets/public.pub is missing, please run this on your project root : <pre>openssl rsa -in secrets/private.key -pubout -outform PEM -out secrets/public.pub</pre>");
        }

        if (static::class === "Tina4\Auth") {
            $this->getToken(["name" => "default"]);
        }
    }

    /**
     * Starts a php session
     * @tests tina4
     *   assert session_status() !== PHP_SESSION_NONE, "Session should be active by now"
     */
    final public function initSession(): void
    {
        //make sure the session is started
        if (!$this->configured && session_status() === PHP_SESSION_NONE) {
            $this->configured = true;
            if (TINA4_CACHE_ON) {
                session_cache_limiter(false);
            }

            if (!headers_sent()) {
                // Prevents javascript XSS attacks aimed to steal the session ID
                ini_set('session.cookie_httponly', 1);
                // Prevent Session ID from being passed through  URLs
                ini_set('session.use_only_cookies', 1);
                session_start();
            }
        } else {
            $this->configured = true;
        }
    }

    /**
     * Creates a secrets folder and generates keys for the application
     * @return bool
     * @tests tina4
     *   assert file_exists("./secrets/private.key") === true,"private.key does not exist - openssl installed ?"
     *   assert file_exists("./secrets/private.key.pub") === true,"private.key.pub does not exist - openssl installed ?"
     *   assert file_exists("./secrets/public.pub") === true,"public.pub does not exist - openssl installed ?"
     */
    final public function generateSecureKeys(): bool
    {
        Debug::message("Generating Auth keys - {$this->documentRoot}secrets");
        if (file_exists($this->documentRoot . "secrets/private.key")) {
            Debug::message("Secrets folder exists already, please remove");
            return false;
        }

        if (!file_exists($this->documentRoot . "secrets") && !mkdir($concurrentDirectory = $this->documentRoot . DIRECTORY_SEPARATOR . "secrets") && !is_dir($concurrentDirectory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
        }

        //`ssh-keygen -t rsa -b 1024 -m PEM -f {$this->documentRoot}secrets/private.key -q -N ""`;
        //`chmod 600 {$this->documentRoot}secrets/private.key`;
        //`openssl rsa -in {$this->documentRoot}secrets/private.key -pubout -outform PEM -out {$this->documentRoot}secrets/public.pub`;

        $keys = openssl_pkey_new(array('digest_alg' => 'sha256', 'private_key_bits' => 2048,'private_key_type' => OPENSSL_KEYTYPE_RSA));

        if (!empty($keys)) {
            $public_key_pem = openssl_pkey_get_details($keys)['key'];
            openssl_pkey_export($keys, $private_key_pem);

            if (!file_exists($this->documentRoot . "secrets")) {
                if (!mkdir($concurrentDirectory = $this->documentRoot . 'secrets') && !is_dir($concurrentDirectory)) {
                    throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
                }
            }

            //write keys to files #1
            file_put_contents($this->documentRoot . 'secrets/' . 'public.pub', $public_key_pem);
            file_put_contents($this->documentRoot . 'secrets/' . 'private.key', $private_key_pem);

            if (!file_exists($this->documentRoot . "secrets" . DIRECTORY_SEPARATOR . ".gitignore")) {
                file_put_contents($this->documentRoot . "secrets" . DIRECTORY_SEPARATOR . ".gitignore", "*");
            }

            if (!file_exists($this->documentRoot . "secrets" . DIRECTORY_SEPARATOR . ".htaccess")) {
                file_put_contents($this->documentRoot . "secrets" . DIRECTORY_SEPARATOR . ".htaccess", "Deny from all");
            }
        } else {
            \Tina4\Debug::message("Could not generate Secrets folder the code way", TINA4_LOG_NOTICE);
            if (!file_exists($this->documentRoot . "secrets")) {
                if (!mkdir($concurrentDirectory = $this->documentRoot . 'secrets') && !is_dir($concurrentDirectory)) {
                    throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
                }
            }
            `ssh-keygen -t rsa -b 1024 -m PEM -f {$this->documentRoot}secrets/private.key -q -N ""`;
            `chmod 600 {$this->documentRoot}secrets/private.key`;
            `openssl rsa -in {$this->documentRoot}secrets/private.key -pubout -outform PEM -out {$this->documentRoot}secrets/public.pub`;
        }

        return true;
    }

    /**
     * Gets an auth token for validating against secure URLS for the session
     * @param array $payLoad
     * @param int $expiresInDays
     * @param string $privateKey
     * @param string $encryption
     * @return string
     * @tests tina4
     *   assert $this->getPayload($this->getToken(["name" => "tina4"])) === ["name" => "tina4"],"Return back the token"
     */
    public function getToken(array $payLoad = [], int $expiresInDays=0, string $privateKey = "", string $encryption = JWT::ALGORITHM_RS256): string
    {
        if (!empty($privateKey)) {
            $this->privateKey = $privateKey;
        }

        if (!empty($payLoad)) {
            if (is_object($payLoad)) {
                $payLoad = (array)$payLoad;
            } elseif (!is_array($payLoad)) {
                $payLoad = ["value" => $payLoad];
            }

            if ($expiresInDays !== 0) {
                $payLoad["expires"] = time() + $expiresInDays * 24 * 60 * 60;
            } else
            if (!isset($payLoad["expires"])) { //take care of expires if the user forgets to set it
                $payLoad["expires"] = time() + TINA4_TOKEN_MINUTES * 60;
            }
        } else {
            if ($expiresInDays !== 0) {
                $payLoad["expires"] = time() + $expiresInDays * 24 * 60 * 60;
            } else {
                $payLoad["expires"] = time() + TINA4_TOKEN_MINUTES * 60;
            }
        }

        if (!empty($_SERVER["HTTP_REFERER"])) {
            $payLoad["httpReferer"] = $_SERVER["HTTP_REFERER"];
        }

        if (!empty($_SERVER["REQUEST_URI"])) {
            $url = explode("?", $_SERVER["REQUEST_URI"]);
            if (isset($url[0])) {
                if (empty($payLoad["payload"])) {
                    $payLoad["payload"] = $url[0];
                }
                $payLoad["requestURI"] = $url[0];
            } else {
                $payLoad["requestURI"] = $_SERVER["REQUEST_URI"];
            }
        }

        $tokenDecoded = new TokenDecoded($payLoad);

        if (!empty($this->privateKey)) {
            $tokenEncoded = $tokenDecoded->encode($this->privateKey, $encryption);
            $tokenString = $tokenEncoded->toString();
        } else {
            $tokenString = "secrets folder is empty of tokens";
        }
        return $tokenString;
    }

    /**
     * Checks if an auth token in the header is valid against the session
     * @param string $token
     * @param string $publicKey
     * @param string $encryption
     * @return bool
     * @tests tina4
     *   assert $this->getToken() !== "", "Token must not be blank"
     *   assert ($this->getToken()) === true,"The token is not valid"
     */
    public function validToken(string $token, string $publicKey = "", string $encryption = JWT::ALGORITHM_RS256): bool
    {
        Debug::message("Validating token", TINA4_LOG_DEBUG);

        if (isset($_ENV["API_KEY"]) && trim(str_ireplace("bearer", "", $token)) === $_ENV["API_KEY"]) {
            Debug::message("Using generic .env API_KEY token", TINA4_LOG_WARNING);
            return true;
        }

        if (!empty($publicKey)) {
            $this->publicKey = $publicKey;
        }

        if (empty($this->publicKey)) {
            return false;
        }

        $token = trim(str_replace("Bearer ", "", $token));

        try {
            $tokenEncoded = new TokenEncoded($token);
        } catch (\Exception $e) {
            Debug::message("Encoded token input failed! " . $e->getMessage());
            return false;
        }

        try {
            $tokenEncoded->validate($this->publicKey, $encryption);
            $payLoad = $this->getPayLoad($token, true);

            if (isset($payLoad["expires"])) {
                if (time() > $payLoad["expires"]) {
                    return false;
                } else {
                    return true;
                }
            } else {
                return false;
            }
        } catch (IntegrityViolationException $e) {
            // Handle token not trusted
            Debug::message("Validating {$token} failed!");
            return false;
        } catch (\Exception $e) {
            // Handle other validation exceptions
            Debug::message("Validating {$token} failed! " . $e->getMessage());
            return false;
        }
    }

    /**
     * Gets the payload from token
     * @param $token
     * @param bool $returnExpires
     * @param string $publicKey
     * @param string $encryption
     * @return array|false
     */
    public function getPayLoad($token, bool $returnExpires = false, string $publicKey = "", string $encryption = JWT::ALGORITHM_RS256): ?array
    {
        Debug::message("Getting token payload");

        if (!empty($publicKey)) {
            $this->publicKey = $publicKey;
        }

        try {
            $tokenEncoded = new TokenEncoded($token);
        } catch (\Exception $e) {
            Debug::message("Encoded token input failed! " . $e->getMessage());
            return [];
        }

        try {
            $tokenEncoded->validate($this->publicKey, $encryption);
        } catch (IntegrityViolationException $e) {
            // Handle token not trusted
            Debug::message("Validating {$token} failed!");
            return [];
        } catch (\Exception $e) {
            // Handle other validation exceptions
            Debug::message("Validating {$token} failed! " . $e->getMessage());
            return [];
        }

        $tokenDecoded = $tokenEncoded->decode();

        if ($returnExpires) {
            return $tokenDecoded->getPayload();
        }

        $payLoad = $tokenDecoded->getPayload();

        if (isset($payLoad["expires"])) {
            unset($payLoad["expires"]);
        }
        return $payLoad;
    }

    /**
     * Validate the request
     * @param $request
     * @param string|null $lastPath
     * @return string
     * @throws \Exception
     */
    public function validateAuth($request, ?string $lastPath = null): string
    {
        if (empty($lastPath) && !isset($_SESSION["tina4:lastPath"]) && !empty($_SESSION["tina4:lastPath"])) {
            $lastPath = $_SESSION["tina4:lastPath"];
        } else {
            $lastPath = "/";
        }

        return $lastPath;
    }

}
