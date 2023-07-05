<?php

namespace Tina4;

use Coyl\Git\Git;
use Mpdf\Tag\U;

class GitDeploy
{

    /***
     * Deploying the system from a git repository
     * @param Response $response
     * @param Request $request
     * @return void
     * @throws \JsonException
     */
    public function deploy(Response $response, Request $request) : array
    {
        if (!isset($_ENV["GIT_SECRET"])) {
            throw Exception("GIT_SECRET not set in .env");
        }

        $signature = "sha1=".hash_hmac('sha1', $request->rawRequest, $_ENV["GIT_SECRET"]);

        //Validate the post
        if (isset($request->headers["X-Hub-Signature"]) && $signature !== $request->headers["X-Hub-Signature"] || !TINA4_DEBUG) {
            throw new \Exception("Invalid signature from GIT, make sure you have set your secret properly on your webhook");
        }

        //Pull the repository from the git repository
        Debug::message("Validated signature from GIT, pulling repository", TINA4_LOG_INFO);

        if (!empty($_ENV["GIT_REPOSITORY"])) {
            Debug::message("Current working path ".getcwd());
            $stagingPath = $_ENV["GIT_DEPLOYMENT_STAGING"] ?? TINA4_DOCUMENT_ROOT."staging";
            $deploymentPath = $_ENV["GIT_DEPLOYMENT_PATH"] ?? getcwd();
            $deployDirectories = $_ENV["GIT_DEPLOYMENT_DIRS"] ?? [];
            Debug::message("Cloning ".$_ENV["GIT_REPOSITORY"]." into ".$stagingPath);
            $repository = $_ENV["GIT_REPOSITORY"];
            $branch = $_ENV["GIT_REPOSITORY"];

            //clean up deployment
            $this->cleanPath($stagingPath);

            $results = `{$this->getBinPath("git")} clone --recurse-submodules {$repository} {$stagingPath}`;

            `git checkout {$branch}`;

            $this->cleanPath($stagingPath.DIRECTORY_SEPARATOR.".git");
            $this->cleanPath($stagingPath.DIRECTORY_SEPARATOR.".github");

            //Make sure if this lands under a webserver that everything is blocked
            file_put_contents($stagingPath.DIRECTORY_SEPARATOR.".htaccess", "Deny from all");

            // run composer install
            $currentDir = getcwd();
            chdir($stagingPath);
            $composerResults = `composer install`;

            //check for lock file and autoloader
            if (is_file($stagingPath.DIRECTORY_SEPARATOR."composer.lock") && is_file($stagingPath.DIRECTORY_SEPARATOR."vendor".DIRECTORY_SEPARATOR."autoload.php")) {
                //get back to where we should be
                chdir($currentDir);

                //Clean up src/app, src/routes , src/orm , src/sass, src/routes & src/templates
                foreach (["app","orm", "sass", "routes", "templates"] as $removePath) {
                    Debug::message("Cleaning ".$deploymentPath.DIRECTORY_SEPARATOR."src".DIRECTORY_SEPARATOR.$removePath);
                    $this->cleanPath($deploymentPath . DIRECTORY_SEPARATOR ."src".DIRECTORY_SEPARATOR.$removePath);
                }

                //deploy the src folder to deployment path
                Debug::message("Deploying system to ".$deploymentPath);

                foreach (["src", "vendor", "migrations", ...$deployDirectories] as $copyPath)
                {
                    Utilities::recurseCopy($stagingPath.DIRECTORY_SEPARATOR.$copyPath, $deploymentPath.DIRECTORY_SEPARATOR.$copyPath);
                }

                //deploy index.php to deployment path
                //deploy composer.lock and composer.json to deployment path
                foreach (["index.php", "composer.json", "composer.lock"] as $copyFile) {
                    if (is_file($stagingPath . DIRECTORY_SEPARATOR . $copyFile)) {
                        $contents = file_get_contents($stagingPath . DIRECTORY_SEPARATOR . $copyFile);
                        file_put_contents($deploymentPath . DIRECTORY_SEPARATOR . $copyFile, $contents);

                    }
                }

                //run the migrations if found
                if (is_dir($deploymentPath.DIRECTORY_SEPARATOR."migrations")) {
                    (new \Tina4\Migration($deploymentPath.DIRECTORY_SEPARATOR."migrations"))->doMigration();
                }
            }
        } else {
            return $response("GIT_REPOSITORY not configured in .env");
        }

        //If it does, pull the files from the repository and deploy the src folder, including changes to the index.php
        return $response("OK!");
    }

    function cleanPath($path)
    {
        if (!is_dir($path))
        {
            return false;
        }

        if (isWindows()) {
            `rmdir /s /q {$path}`;
        } else {
            `rmdir -Rf {$path}`;
        }

        return !is_dir($path);
    }

    function getBinPath($binary): string
    {
        if (isWindows())
        {
            $path = `where {$binary}`;
        } else {
            $path = `which {$binary}`;
        }

        $path = explode("\n", $path);
        Debug::message("Found git at {$path[0]}");
        return '"'.$path[0].'"' ?? $binary;
    }
}