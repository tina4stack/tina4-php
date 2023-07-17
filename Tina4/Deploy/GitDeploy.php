<?php

namespace Tina4;

use function PHPUnit\Framework\throwException;



class GitDeploy
{
    private string $gitTag = "";
    private string $workingPath = './';

    private $deployLog = '';

    /**
     * Log messages
     * @param $message
     * @return void
     */
    function log($message): void
    {
        Debug::message($message, TINA4_LOG_INFO);
        $output = date("Y-m-d H:i:s") . ": ($this->gitTag) " . $message . "\n";
        $this->deployLog .= $output;
        file_put_contents($this->workingPath.DIRECTORY_SEPARATOR."log/deploy.log", $output, FILE_APPEND);
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
            $this->workingPath = getcwd();
            $stagingPath = ($_ENV["GIT_DEPLOYMENT_STAGING"] ?? TINA4_DOCUMENT_ROOT . "staging"); //workspace for cloning and testing the repository
            $projectRoot = $stagingPath . DIRECTORY_SEPARATOR . $_ENV["GIT_TINA4_PROJECT_ROOT"] ?? $stagingPath;
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


            $runClone = "{$gitBinary} clone --single-branch --branch {$branch} {$repository} {$stagingPath}";
            $this->log($runClone);
            shell_exec($runClone);

            // run composer install
            $currentDir = getcwd();
            $this->log("Current directory is {$currentDir}");

            $this->log("Change to {$projectRoot}");
            chdir($projectRoot);

            $this->log("Checking for composer");
            if (isWindows()) {
                `php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"`;
                `php composer-setup.php`;
            } else {
                `php -r "eval('?>'.file_get_contents('http://getcomposer.org/installer'));"`;
            }

            if (file_exists("./composer.phar")) {
                $composer = "php composer.phar";
            } else {
                $composer = $this->getBinPath("composer");
            }

            $this->log("Running composer install");

            if (isWindows()) {
                `{$composer} install --no-interaction`;
            } else {
                `export COMPOSER_HOME={$projectRoot} && {$composer} install --no-interaction`;
            }

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
            if (!empty($_ENV["SLACK_CHANNEL"])) {
                (new \Tina4\Slack())->postMessage($this->deployLog, $_ENV["SLACK_CHANNEL"]);
            }
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
        if (isWindows()) {
            return '"' . $path[0] . '"' ?? "";
        } else {
            return $path[0] ?? "";
        }

    }
}