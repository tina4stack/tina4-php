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
        $_SESSION["tina4:lastPath"] = $lastPath;
        $this->documentRoot = $documentRoot;
        //send to the wizard if config settings don't exist
        $tina4Auth = (new Tina4Auth())->load ('id = 1');
        if (!empty($tina4Auth->username)) {
            $this->configured = true;
        }
    }


    /**
     * Setup an auth database
     * @param $request
     * @return string
     * @throws \Exception
     */
    function setupAuth ($request) {
        if (empty($this->DBA)) {
            throw new \Exception("No global for DBA");
        }

        $this->DBA->exec ("
            create table tina4_auth (
                id integer default 0 not null,
                username varchar (100) default '',
                password varchar (300) default '',
                email varchar (300) default '',
                primary key (id) 
            );
        ");

        $this->DBA->exec ("
            create table tina4_log (
                id integer default 0 not null,
                user_id integer default 0 not null references tina4_auth(id),
                content varchar (5000) default '',
                primary key (id) 
            );
        ");


        $tina4Auth = new \Tina4\Tina4Auth($request->params);
        if (!empty($request->params["password"])) {
            $tina4Auth->password = password_hash($request->params["password"], PASSWORD_BCRYPT);
        } else {
            $tina4Auth->password = null;
        }
        $tina4Auth->save();

        return "/auth/login";
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
        $tina4Auth = (new \Tina4\Tina4Auth())->load ("username = '{$request["username"]}'");
        if (!empty($tina4Auth->username)) {
            if (password_verify($request["password"], $tina4Auth->password)) {
                $_SESSION["tina4:currentUser"] = $tina4Auth->getTableData();
                $this->getToken();
            }
        } else {
            $this->clearTokens();
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
        if (isset($_SERVER["REMOTE_ADDR"])) {
            $token = md5($_SERVER["REMOTE_ADDR"].date("Y-m-d h:i:s"));
            $_SESSION["tina4:tokens"][$_SERVER["REMOTE_ADDR"]] = $token;
        } else {
            $token = md5(date("Y-m-d h:i:s"));
        }
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
