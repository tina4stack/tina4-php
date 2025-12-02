<?php

namespace Tina4;


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
        file_put_contents($this->workingPath . DIRECTORY_SEPARATOR . "log/deploy.log", $output, FILE_APPEND);
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
    final public function doDeploy(): void
    {
        try {
            //Pull the repository from the git repository
            if (!empty($_ENV["SLACK_CHANNEL"])) {
                (new \Tina4\Slack())->postMessage(
                    "A deployment has started for " . $_ENV["GIT_BRANCH"],
                    $_ENV["SLACK_CHANNEL"]
                );
            }
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
            $this->cleanPath($stagingPath, true);

            $gitBinary = $this->getBinPath("git");

            if (empty($gitBinary)) {
                $this->log("Deployment failed! Git binary not found, trying to do wget", TINA4_LOG_ERROR);

                $re = '/github.com\/(.*)\/(.*)\.git/m';
                $str = $repository;
                $subst = "codeload.github.com/$1/$2/zip/refs/heads/$branch";

                $result = preg_replace($re, $subst, $str);
                $zipFile = $stagingPath . "/" . $branch . ".zip";

                $this->downloadFile($result, $zipFile);

                //downloads the zip file and then extracts it into the staging Path
                $zip = new \ZipArchive;
                $this->log("Unzipping $zipFile to $stagingPath");

                if ($zip->open($zipFile) === true) {
                    $baseFolder = substr($zip->getNameIndex(0), 0, -1);
                    for ($i = 1; $i < $zip->numFiles; $i++) {
                        $filename = $zip->getNameIndex($i);

                        $stagingPath = realpath($stagingPath);
                        $fileInfo = pathinfo($filename);

                        if (isset($fileInfo["dirname"])) {
                            $destination = str_replace(
                                "/",
                                DIRECTORY_SEPARATOR,
                                str_replace($baseFolder, "", $fileInfo["dirname"])
                            );
                            if (!file_exists($stagingPath . $destination)) {
                                mkdir($stagingPath . $destination, "0777", true);
                            }
                        }

                        if (isset($fileInfo["extension"])) {
                            copy(
                                "zip://" . $zipFile . "#" . $filename,
                                $stagingPath . $destination . DIRECTORY_SEPARATOR . $fileInfo['basename']
                            );
                        }
                    }
                    $zip->close();

                    $this->log("Unzipped $zipFile to $stagingPath");
                } else {
                    $this->log("Failed to unzip $zipFile to $stagingPath");
                }
            } else {
                $this->log("Cloning " . $_ENV["GIT_REPOSITORY"] . " into " . $stagingPath);

                $runClone = "{$gitBinary} clone --single-branch --branch {$branch} {$repository} {$stagingPath}";
                $this->log($runClone);
                shell_exec($runClone);
            }

            // run composer install
            $currentDir = getcwd();
            $this->log("Current directory is {$currentDir}");

            $this->log("Change to {$projectRoot}");
            chdir($projectRoot);

            $this->log("Checking for composer");

            $composer = $this->getBinPath("composer");

            if (empty($composer)) {
                if (isWindows()) {
                    $this->downloadFile("https://getcomposer.org/installer", $stagingPath . "/composer-setup.php");
                    `php composer-setup.php`;
                } else {
                    $this->downloadFile("https://getcomposer.org/installer", $stagingPath . "/composer-setup.php");
                    `php composer-setup.php`;
                }

                if (file_exists("./composer.phar")) {
                    $composer = "php composer.phar";
                } else {
                    $composer = $this->getBinPath("composer");
                }

                if (empty($composer)) {
                    $this->log("Composer could not be downloaded");
                    throw new \Exception("Composer could not be located!");
                }
            }

            $this->log("Running composer install");

            if (isWindows()) {
                `{$composer} install --no-interaction`;
            } else {
                `export COMPOSER_HOME={$projectRoot} && {$composer} install --no-interaction`;
            }

            //check for lock file and autoloader
            if (is_file($projectRoot . DIRECTORY_SEPARATOR . "composer.lock") && is_file(
                    $projectRoot . DIRECTORY_SEPARATOR . "vendor" . DIRECTORY_SEPARATOR . "autoload.php"
                )) {
                //@todo Run inbuilt tests or other, if fails then don't deploy
                //get back to where we should be
                chdir($currentDir);

                //Clean up src/app, src/routes , src/orm , src/sass, src/routes & src/templates
                foreach (["app", "orm", "sass", "routes", "templates"] as $removePath) {
                    $this->log(
                        "Cleaning " . $deploymentPath . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR . $removePath
                    );
                    $this->cleanPath($deploymentPath . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR . $removePath);
                }

                //deploy the src folder to deployment path
                $this->log("Deploying system to " . $deploymentPath);

                foreach (["src", "vendor", "migrations", ...$deployDirectories] as $copyPath) {
                    Utilities::recurseCopy(
                        $projectRoot . DIRECTORY_SEPARATOR . $copyPath,
                        $deploymentPath . DIRECTORY_SEPARATOR . $copyPath
                    );
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
            $this->log("Error occurred: " . $exception->getMessage());
        }
    }

    /**
     * Deletes a directory
     * @param $dirPath
     * @return void
     */
    final public function deleteDirectory(string $dirPath):void
    {
        if (file_exists($dirPath))
        {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dirPath,
                    \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($dirPath);
        }
    }

    /**
     * Deletes all folders and files under a path / directory
     * @param string $path
     * @param bool $makeDir
     * @return bool
     */
    final public function cleanPath($path, $makeDir = false): bool
    {
        $this->log("Deleting all files and folders under {$path}");

        $this->deleteDirectory($path);

        if ($makeDir) {
            if (!mkdir($path) && !is_dir($path)) {
                $this->log("Could not make directory {$path}");
                return false;
            }
        }

        return !is_dir($path);
    }

    /**
     * Gets the path to the binary
     * @param string $binary
     * @return string
     */
    final public function getBinPath(string $binary): string
    {
        if (!empty($_ENV[strtoupper($binary)."_PATH"])) {
            return $_ENV[strtoupper($binary)."_PATH"];
        }

        if (isWindows()) {
            $path = `where {$binary}`;
        } else {
            $path = `which {$binary}`;
        }

        $path = explode("\n", $path);
        $this->log("Found $binary at {$path[0]}");
        if (isWindows()) {
            return '"' . $path[0] . '"' ?? "";
        }

        return $path[0] ?? "";
    }

    /**
     * Download a file with curl
     * @param $url
     * @param string $fileName
     * @return bool
     */
    private function downloadFile($url, string $fileName): bool
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $st = curl_exec($ch);
        $fd = fopen($fileName, 'w');
        fwrite($fd, $st);
        fclose($fd);
        curl_close($ch);

        return file_exists($fileName);
    }
}
