<?php

namespace Tina4;

use Coyl\Git\Git;
use Mpdf\Tag\U;
use function PHPUnit\Framework\throwException;

class GitDeploy
{
    private string $gitTag = "";

    /**
     * Log messages
     * @param $message
     * @return void
     */
    function log($message): void
    {
        Debug::message($message, TINA4_LOG_INFO);
        file_put_contents("./log/deploy.log", date("Y-m-d H:i:s") . ": ($this->gitTag) " . $message . "\n", FILE_APPEND);
    }

    /**
     * Validation of the GIT hook request
     * @param Response $response
     * @param Request $request
     * @return bool
     */
    public function validateHook(Response $response, Request $request): bool
    {
        if (!isset($_ENV["GIT_SECRET"])) {
            $this->log("GIT_SECRET not set in .env");

            return false;
        }

        $signature = "sha1=" . hash_hmac('sha1', $request->rawRequest, $_ENV["GIT_SECRET"]);

        //Validate the post
        if (isset($request->headers["x-hub-signature"]) && $signature !== $request->headers["x-hub-signature"]) {
            $this->log("Invalid signature from GIT, make sure you have set your secret properly on your webhook");

            return false;
        }

        if (!empty($_ENV["GIT_REPOSITORY"])) {
            //Make sure branch matches the branch specified in the GIT_BRANCH

            if ($request->headers["x-github-event"] !== "push") {

                return false;
            }

            //check the branch && event
            if ($request->data->ref !== "refs/heads/" . $_ENV["GIT_BRANCH"]) {
                $this->log("Got a git event but not a push or not the right branch", TINA4_LOG_INFO);

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
        try {
            //Pull the repository from the git repository
            $this->log("=== STARTING DEPLOYMENT ===");
            $this->log("Current working path " . getcwd());
            $stagingPath = ($_ENV["GIT_DEPLOYMENT_STAGING"] ?? TINA4_DOCUMENT_ROOT . "staging"); //workspace for cloning and testing the repository
            $projectRoot = realpath($stagingPath . DIRECTORY_SEPARATOR . $_ENV["GIT_TINA4_PROJECT_ROOT"]) ?? $stagingPath;
            $this->log("Project root " . $projectRoot);
            $deploymentPath = $_ENV["GIT_DEPLOYMENT_PATH"] ?? getcwd();
            $deployDirectories = $_ENV["GIT_DEPLOYMENT_DIRS"] ?? [];

            $repository = $_ENV["GIT_REPOSITORY"];
            $branch = $_ENV["GIT_BRANCH"];

            //clean up deployment
            $this->cleanPath($stagingPath);

            $gitBinary = $this->getBinPath("git");

            if (empty($gitBinary)) {
                $this->log("Deployment failed! Git binary not found, please install git on your system", TINA4_LOG_ERROR);

                throwException("Git binary not found, please install git on your system");
            }

            $this->log("Cloning " . $_ENV["GIT_REPOSITORY"] . " into " . $stagingPath);


            $runClone = "{$gitBinary} clone --recurse-submodules {$repository} {$stagingPath}";
            $this->log($runClone);
            $this->log(shell_exec($runClone));

            // run composer install
            $currentDir = getcwd();
            $this->log("Current directory is {$currentDir}");

            chdir($stagingPath);

            $this->log("Checking out {$branch}");
            $runCheckout = "{$gitBinary} checkout {$branch}";
            shell_exec($runCheckout);

            //Make sure if this lands under a webserver that everything is blocked
            $this->log("Putting .htaccess in {$projectRoot}");
            file_put_contents($projectRoot . DIRECTORY_SEPARATOR . ".htaccess", "Deny from all");

            chdir($projectRoot);

            $this->log("Checking for composer");
            $composer = $this->getBinPath("composer");
            if (empty($composer)) {
                `php -dxdebug.mode=off -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"`;
                `php -dxdebug.mode=off composer-setup.php`;
                $composer = "php -dxdebug.mode=off composer.phar";
            }

            $this->log("Running composer install");
            shell_exec("{$composer} install");


            //check for lock file and autoloader
            if (is_file($projectRoot . DIRECTORY_SEPARATOR . "composer.lock") && is_file($projectRoot . DIRECTORY_SEPARATOR . "vendor" . DIRECTORY_SEPARATOR . "autoload.php"))
            {
                //@todo Run inbuilt tests or other, if fails then don't deploy
                //get back to where we should be
                chdir($currentDir);

                //Clean up src/app, src/routes , src/orm , src/sass, src/routes & src/templates
                foreach (["app", "orm", "sass", "routes", "templates"] as $removePath) {
                    $this->log("Cleaning " . $deploymentPath . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR . $removePath);
                    $this->cleanPath($deploymentPath . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR . $removePath);
                }

                //deploy the src folder to deployment path
                $this->log("Deploying system to " . $deploymentPath);

                foreach (["src", "vendor", "migrations", ...$deployDirectories] as $copyPath) {
                    Utilities::recurseCopy($projectRoot . DIRECTORY_SEPARATOR . $copyPath, $deploymentPath . DIRECTORY_SEPARATOR . $copyPath);
                }

                //deploy index.php to deployment path
                //deploy composer.lock and composer.json to deployment path
                foreach (["index.php", "composer.json", "composer.lock"] as $copyFile) {
                    if (is_file($projectRoot . DIRECTORY_SEPARATOR . $copyFile)) {
                        $contents = file_get_contents($projectRoot . DIRECTORY_SEPARATOR . $copyFile);
                        $this->log("Copying " . $copyFile);
                        file_put_contents($deploymentPath . DIRECTORY_SEPARATOR . $copyFile, $contents);
                    }
                }

                //run the migrations if found
                if (is_dir($deploymentPath . DIRECTORY_SEPARATOR . "migrations")) {
                    $this->log("Running migrations");
                    (new \Tina4\Migration($deploymentPath . DIRECTORY_SEPARATOR . "migrations"))->doMigration();
                }

                $this->log("Done installing");
            } else {
                $this->log("Deployment failed!", TINA4_LOG_ERROR);
            }

            $this->log("=== END DEPLOYMENT ===");
        } catch (\Exception $exception) {
            $this->log("Error occurred: ".$exception->getMessage());
        }
    }

    /**
     * Deletes all folders and files under a path / directory
     * @param $path
     * @return bool
     */

    function cleanPath($path): bool
    {
        $this->log("Deleting all files and folders under {$path}");
        if (!is_dir($path)) {
            return false;
        }

        if (isWindows()) {
            `rmdir /s /q {$path}`;
        } else {
            `rm -Rf {$path}`;
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
        if (isWindows()) {
            $path = `where {$binary}`;
        } else {
            $path = `which {$binary}`;
        }

        $path = explode("\n", $path);
        $this->log("Found $binary at {$path[0]}");
        return '"' . $path[0] . '"' ?? "";
    }
}