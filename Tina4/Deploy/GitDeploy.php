<?php

namespace Tina4;

use Coyl\Git\Git;
use Mpdf\Tag\U;
use function PHPUnit\Framework\throwException;

class GitDeploy
{

    /**
     * Validation of the GIT hook request
     * @param Response $response
     * @param Request $request
     * @return bool
     */
    public function validateHook(Response $response, Request $request): bool
    {
        Debug::message("Validated signature from GIT, pulling repository", TINA4_LOG_INFO);
        if (!isset($_ENV["GIT_SECRET"])) {
            Debug::message("GIT_SECRET not set in .env");

            return false;
        }

        $signature = "sha1=".hash_hmac('sha1', $request->rawRequest, $_ENV["GIT_SECRET"]);

        //Validate the post
        if (isset($request->headers["X-Hub-Signature"]) && $signature !== $request->headers["X-Hub-Signature"] || !TINA4_DEBUG) {
            Debug::message("Invalid signature from GIT, make sure you have set your secret properly on your webhook");

            return false;
        }

        if (!empty($_ENV["GIT_REPOSITORY"])) {
            //Make sure branch matches the branch specified in the GIT_BRANCH

            if ($request->headers["X-GitHub-Event"] !== "push") {

                return false;
            }

            //check the branch && event
            if ($request->data->ref !== "refs/heads/".$_ENV["GIT_BRANCH"]) {
                Debug::message("Got a git event but not a push or not the right branch", TINA4_LOG_INFO);
                return false;
            }

            return true;

        }

        return false;
    }

    /***
     * Deploying the system from a git repository
     * @return void
     */
    public function doDeploy()
    {
        //Pull the repository from the git repository

        Debug::message("Current working path ".getcwd());
        $stagingPath = $_ENV["GIT_DEPLOYMENT_STAGING"] ?? TINA4_DOCUMENT_ROOT."staging"; //workspace for cloning and testing the repository
        $projectRoot = realpath($stagingPath.DIRECTORY_SEPARATOR.$_ENV["GIT_TINA4_PROJECT_ROOT"]) ?? $stagingPath;
        Debug::message( "Project root ".$projectRoot);
        $deploymentPath = $_ENV["GIT_DEPLOYMENT_PATH"] ?? getcwd();
        $deployDirectories = $_ENV["GIT_DEPLOYMENT_DIRS"] ?? [];
        Debug::message("Cloning ".$_ENV["GIT_REPOSITORY"]." into ".$stagingPath);
        $repository = $_ENV["GIT_REPOSITORY"];
        $branch = $_ENV["GIT_REPOSITORY"];

        //clean up deployment
        $this->cleanPath($stagingPath);

        $gitBinary = $this->getBinPath("git");

        if (empty($gitBinary))
        {
            Debug::message("Deployment failed! Git binary not found, please install git on your system", TINA4_LOG_ERROR);

            throwException("Git binary not found, please install git on your system");
        }

        `{$gitBinary} clone --recurse-submodules {$repository} {$stagingPath}`;

        `{$gitBinary} checkout {$branch}`;

        $this->cleanPath($stagingPath.DIRECTORY_SEPARATOR.".git");
        $this->cleanPath($stagingPath.DIRECTORY_SEPARATOR.".github");

        //Make sure if this lands under a webserver that everything is blocked
        file_put_contents($stagingPath.DIRECTORY_SEPARATOR.".htaccess", "Deny from all");

        // run composer install
        $currentDir = getcwd();
        chdir($projectRoot);

        $composer = $this->getBinPath("composer");
        if (empty($composer))
        {
            `php -dxdebug.mode=off -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"`;
            `php -dxdebug.mode=off composer-setup.php`;
            $composer = "php -dxdebug.mode=off composer.phar";
        }

        $composerResults = `{$composer} install`;

        //check for lock file and autoloader
        if (is_file($stagingPath.DIRECTORY_SEPARATOR."composer.lock") && is_file($stagingPath.DIRECTORY_SEPARATOR."vendor".DIRECTORY_SEPARATOR."autoload.php")) {
            //@todo Run inbuilt tests or other, if fails then don't deploy


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
                    Debug::message("Copying ".$copyFile);
                    file_put_contents($deploymentPath . DIRECTORY_SEPARATOR . $copyFile, $contents);

                }
            }

            //run the migrations if found
            if (is_dir($deploymentPath.DIRECTORY_SEPARATOR."migrations")) {
                Debug::message("Running migrations");
                (new \Tina4\Migration($deploymentPath.DIRECTORY_SEPARATOR."migrations"))->doMigration();
            }

            Debug::message("Done installing");
        }
          else
        {
            Debug::message("Deployment failed!", TINA4_LOG_ERROR);
        }
    }

    /**
     * Deletes all folders and files under a path / directory
     * @param $path
     * @return bool
     */

    function cleanPath($path): bool
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

    /**
     * Gets the path to the binary
     * @param $binary
     * @return string
     */
    function getBinPath($binary): string
    {
        if (isWindows())
        {
            $path = `where {$binary}`;
        } else {
            $path = `which {$binary}`;
        }

        $path = explode("\n", $path);
        Debug::message("Found $binary at {$path[0]}");
        return '"'.$path[0].'"' ?? "";
    }
}