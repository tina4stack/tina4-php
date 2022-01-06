<?php

namespace Coyl\Git;

use Coyl\Git\Exception\BranchNotFoundException;

/**
 * Git Repository Interface Class
 *
 * This class enables the creating, reading, and manipulation
 * of a git repository
 *
 * @class  GitRepo
 */
class GitRepo
{

    const BRANCH_LIST_MODE_LOCAL = 'local';
    const BRANCH_LIST_MODE_REMOTE = 'remote';
    const BRANCH_LIST_MODE_All = 'all';
    const CONFLICT_FILE_MARK = '/CONFLICT.*?([\w\/\.]*)$/';

    protected $repoPath = null;
    protected $bare = false;
    /** @var Console */
    protected $console;

    /**
     * Create a new git repository
     *
     * Accepts a creation path, and, optionally, a source path
     *
     * @access public
     * @param string $repoPath repository path
     * @param string $source directory to source
     * @param bool $remoteSource reference path
     * @param string|null $reference
     * @throws \Exception
     * @return GitRepo
     */
    public static function create($repoPath, $source = null, $remoteSource = false, $reference = null, $commandString = "")
    {
        if (is_dir($repoPath) && file_exists($repoPath . "/.git") && is_dir($repoPath . "/.git")) {
            throw new GitException(sprintf('"%s" is already a git repository', $repoPath));
        } else {
            $repo = new self($repoPath, true, false);
            if (is_string($source)) {
                if ($remoteSource) {
                    // check for reference repository
                    if (!is_dir($reference) || !is_dir($reference . '/.git')) {
                        throw new GitException('"' . $reference . '" is not a git repository. Cannot use as reference.');
                    } else if (strlen($reference)) {
                        $reference = realpath($reference);
                        $reference = "--reference $reference";
                    }
                    $repo->cloneRemote($source, $reference);
                } else {
                    $repo->cloneFrom($source, $commandString);
                }
            } else {
                $repo->run('init .');
            }
            return $repo;
        }
    }

    /**
     * Constructor
     *
     * Accepts a repository path
     *
     * @access  public
     * @param string $repo_path repository path
     * @param bool $create_new create if not exists?
     * @param bool $_init
     * @return GitRepo
     */
    public function __construct($repo_path = null, $create_new = false, $_init = true)
    {
        $this->console = new Console();
        if (is_string($repo_path)) {
            $this->setRepoPath($repo_path, $create_new, $_init);
        }
    }

    /**
     * @return Console
     */
    public function getConsole()
    {
        return $this->console;
    }

    /**
     * Set the repository's path
     *
     * Accepts the repository path
     *
     * @access public
     * @param  string $repo_path repository path
     * @param  bool $create_new create if not exists?
     * @param  bool $_init initialize new Git repo if not exists?
     * @throws \Exception
     * @return void
     */
    public function setRepoPath($repo_path, $create_new = false, $_init = true)
    {
        if (is_string($repo_path)) {
            if ($new_path = realpath($repo_path)) {
                $repo_path = $new_path;
                if (is_dir($repo_path)) {
                    // Is this a work tree?
                    if (file_exists($repo_path . "/.git") && is_dir($repo_path . "/.git")) {
                        $this->repoPath = $repo_path;
                        $this->bare = false;
                        // Is this a bare repo?
                    } else if (is_file($repo_path . "/config")) {
                        $parse_ini = parse_ini_file($repo_path . "/config");
                        if ($parse_ini['bare']) {
                            $this->repoPath = $repo_path;
                            $this->bare = true;
                        }
                    } else {
                        if ($create_new) {
                            $this->repoPath = $repo_path;
                            if ($_init) {
                                $this->run('init .');
                            }
                        } else {
                            throw new GitException(sprintf('"%s" is not a git repository', $repo_path));
                        }
                    }
                } else {
                    throw new GitException(sprintf('"%s" is not a directory', $repo_path));
                }
            } else {
                if ($create_new) {
                    if ($parent = realpath(dirname($repo_path))) {
                        mkdir($repo_path);
                        $this->repoPath = $repo_path;
                        if ($_init) $this->run('init .');
                    } else {
                        throw new GitException('cannot create repository in non-existent directory');
                    }
                } else {
                    throw new GitException(sprintf('"%s" does not exist', $repo_path));
                }
            }
        }
        $this->console->setCurrentPath($this->repoPath);
    }

    /**
     * Get the path to the git repo directory (eg. the ".git" directory)
     *
     * @access public
     * @return string
     */
    public function getGitDirectoryPath()
    {
        return ($this->bare) ? $this->repoPath : $this->repoPath . "/.git";
    }

    /**
     * Get the path to the git repo directory
     *
     * @access public
     * @return string
     */
    public function getRepoPath()
    {
        return $this->repoPath;
    }

    /**
     * Tests if git is installed
     *
     * @access public
     * @return bool
     * @deprecated move it to another class, for example – Git
     */
    public function test_git()
    {
        $descriptorspec = array(
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $pipes = array();
        $resource = proc_open(Git::getBin(), $descriptorspec, $pipes);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        $status = trim(proc_close($resource));
        return ($status != 127);
    }


    /**
     * Run a git command in the git repository
     *
     * Accepts a git command to run
     *
     * @access public
     * @param  string $command command to run
     * @return string
     */
    public function run($command, array $parameters = null)
    {
        if (is_null($parameters))
            $parameters = [];
        return $this->console->runCommand(vsprintf(Git::getBin() . " " . $command, $parameters));
    }

    /**
     * Runs a 'git status' call
     *
     * Accept a convert to HTML bool
     *
     * @access public
     * @param bool $html return string with <br />
     * @return string
     * @todo – add decorators, remove in-place formatting
     */
    public function status($html = false)
    {
        $msg = $this->run("status");
        if ($html == true) {
            $msg = str_replace("\n", "<br />", $msg);
        }
        return $msg;
    }

    /**
     * Runs a `git add` call
     *
     * Accepts a list of files to add
     *
     * @access public
     * @param  mixed $files files to add
     * @return string
     */
    public function add($files = "*")
    {
        if (is_array($files)) {
            $files = '"' . implode('" "', $files) . '"';
        }
        return $this->run("add %s -v", [$files]);
    }

    /**
     * Runs a `git rm` call
     *
     * Accepts a list of files to remove
     *
     * @access public
     * @param  mixed $files files to remove
     * @param  boolean $cached use the --cached flag?
     * @return string
     */
    public function rm($files = "*", $cached = false)
    {
        if (is_array($files)) {
            $files = '"' . implode('" "', $files) . '"';
        }
        return $this->run("rm %s %s", [($cached ? '--cached ' : ''), $files]);
    }

    /**
     * Runs a `git commit` call
     *
     * Accepts a commit message string
     *
     * @access public
     * @param  string $message commit message
     * @param  boolean $commit_all should all files be committed automatically (-a flag)
     * @return string
     */
    public function commit($message = "", $commit_all = true)
    {
        $flags = $commit_all ? '-av' : '-v';
        return $this->run("commit %s -m %s", [$flags, escapeshellarg($message)]);
    }

    /**
     * Runs a `git clone` call to clone the current repository
     * into a different directory
     *
     * Accepts a target directory
     *
     * @access public
     * @param  string $target target directory
     * @return string
     * @todo move to common purpose clone command
     */
    public function cloneTo($target)
    {
        return $this->run("clone --local %s %s", [$this->repoPath, $target]);
    }

    /**
     * Runs a `git clone` call to clone a different repository
     * into the current repository
     *
     * Accepts a source directory
     *
     * @access public
     * @param  string $source source directory
     * @return string
     * @todo move to common purpose clone command
     */
    public function cloneFrom($source, $commandString = "")
    {
        return $this->run("clone --local %s %s %s", [$source, $this->repoPath, $commandString]);
    }

    /**
     * Runs a `git clone` call to clone a remote repository
     * into the current repository
     *
     * Accepts a source url
     *
     * @access public
     * @param  string $source source url
     * @param  string $reference reference path
     * @return string
     * @todo move to common purpose clone command
     */
    public function cloneRemote($source, $reference)
    {
        return $this->run("clone %s %s %s", [$reference, $source, $this->repoPath]);
    }

    /**
     * Runs a `git clean` call
     *
     * Accepts a remove directories flag
     *
     * @access public
     * @param  bool $dirs delete directories?
     * @param  bool $force force clean?
     * @return string
     */
    public function clean($dirs = false, $force = false)
    {
        return $this->run("clean %s %s", [(($force) ? " -f" : ""), (($dirs) ? " -d" : "")]);
    }

    /**
     * Runs a `git branch` call
     *
     * Accepts a name for the branch
     *
     * @access public
     * @param  string $branch branch name
     * @return string
     */
    public function branchNew($branch)
    {
        return $this->run("checkout -b $branch");
    }

    /**
     * Runs a `git branch -[d|D]` call
     *
     * Accepts a name for the branch
     *
     * @access public
     * @param  string $branch branch name
     * @param  bool $force
     * @return string
     */
    public function branchDelete($branch, $force = false)
    {
        return $this->run("branch %s %s", [(($force) ? '-D' : '-d'), $branch]);
    }

    /**
     * Returns array of branch names
     *
     * @access public
     * @param string $mode
     * @param  bool  $keepAsterisk keep asterisk mark on active branch
     * @return array
     * @todo   add decorators
     */
    public function branches($mode = GitRepo::BRANCH_LIST_MODE_LOCAL, $keepAsterisk = false)
    {
        if ($mode === GitRepo::BRANCH_LIST_MODE_LOCAL) {
            $branches = explode("\n", $this->run("branch"));
        } elseif ($mode === GitRepo::BRANCH_LIST_MODE_REMOTE) {
            $branches = explode("\n", $this->run("branch -r"));
        } else {
            $branches = explode("\n", $this->run("branch -a"));
        }

        if (in_array($mode, [GitRepo::BRANCH_LIST_MODE_LOCAL, GitRepo::BRANCH_LIST_MODE_All]))
            $branches = $this->decorateLocalBranches($branches, $keepAsterisk);
        elseif (in_array($mode, [GitRepo::BRANCH_LIST_MODE_REMOTE, GitRepo::BRANCH_LIST_MODE_All]))
            $branches = $this->decorateRemoteBranches($branches);

        $branches = array_filter($branches);
        return $branches;
    }

    protected function decorateLocalBranches(array $branches, $keepAsterisk)
    {
        foreach ($branches as $i => &$branch) {
            $branch = trim($branch);
            if (!$keepAsterisk) {
                $branch = str_replace("* ", "", $branch);
            }
        }
        return $branches;
    }

    protected function decorateRemoteBranches($branches)
    {
        $branches = array_filter($branches, function ($val) {
            return (strpos($val, 'HEAD -> ') === false);
        });
        return $branches;
    }

    /**
     * Returns name of active branch
     *
     * @access public
     * @param  bool $keep_asterisk keep asterisk mark on branch name
     * @return string
     */
    public function getActiveBranch($keep_asterisk = false)
    {
        $branchArray = $this->branches(GitRepo::BRANCH_LIST_MODE_LOCAL, true);
        $active_branch = preg_grep("/^\\*/", $branchArray);
        reset($active_branch);
        if ($keep_asterisk) {
            return current($active_branch);
        } else {
            return str_replace("* ", "", current($active_branch));
        }
    }

    /**
     * Runs a `git checkout` call
     *
     * Accepts a name for the branch
     *
     * @access  public
     * @param   string $branch branch name
     * @return string
     * @throws BranchNotFoundException
     */
    public function checkout($branch)
    {
        try {
            return $this->run("checkout %s", [$branch]);
        } catch (ConsoleException $e) {
            throw new BranchNotFoundException(sprintf('Branch %s not found', $branch), 0, $e);
        }
    }


    /**
     * Runs a `git merge` call
     *
     * Accepts a name for the branch to be merged
     *
     * @access  public
     * @param   string $branch branch name
     * @param   string $message commit message (optional)
     * @return  string
     */
    public function merge($branch, $message = null)
    {
        $branch = escapeshellarg($branch);
        if (null !== $message) {
            $message = sprintf('-m "%s"', trim($message));
        }
        return $this->run("merge %s --no-ff %s", [$branch, $message]);
    }

    /**
     * Runs a `git merge --abort`
     *
     * Reverts last merge
     *
     * @access  public
     * @return  string
     */
    public function mergeAbort()
    {
        return $this->run('merge --abort');
    }

    /**
     * Runs a `git reset` with params
     *
     * @access  public
     * @param   string $resetStr
     * @return  string
     */
    public function reset($resetStr)
    {
        return $this->run("reset %s", [$resetStr]);
    }

    /**
     * Runs a git fetch on the current branch
     *
     * @param   bool $dry
     *
     * @access  public
     * @return  string
     */
    public function fetch($dry = false)
    {
        $dry = $dry ? ' --dry-run' : '';
        return $this->run("fetch%s", [$dry]);
    }

    /**
     * Runs a git stash
     *
     * @access  public
     * @return  string
     */
    public function stash()
    {
        return $this->run("stash");
    }

    /**
     * Runs a git stash pop
     *
     * @access  public
     * @return  string
     */
    public function stashPop()
    {
        return $this->run("stash pop");
    }

    /**
     * Add a new tag on the current position
     *
     * Accepts the name for the tag and the message
     *
     * @param string $tag
     * @param string $message
     * @return string
     */
    public function tag($tag, $message = null)
    {
        if ($message === null) {
            $message = $tag;
        }
        return $this->run("tag -a %s -m %s", [$tag, escapeshellarg($message)]);
    }

    /**
     * List all the available repository tags.
     *
     * Optionally, accept a shell wildcard pattern and return only tags matching it.
     *
     * @access public
     * @param  string $pattern Shell wildcard pattern to match tags against.
     * @return array Available repository tags.
     */
    public function tags($pattern = null)
    {
        $tagArray = explode("\n", $this->run("tag -l %s", [$pattern]));
        foreach ($tagArray as $i => &$tag) {
            $tag = trim($tag);
            if ($tag == '') {
                unset($tagArray[$i]);
            }
        }

        return $tagArray;
    }

    /**
     * Push specific branch to a remote
     *
     * Accepts the name of the remote and local branch
     *
     * @param string $remote
     * @param string $branch
     * @param bool $force
     * @return string
     */
    public function push($remote, $branch, $force = false)
    {
        return $this->run("push --tags %s %s %s", [$force ? '--force' : '', $remote, $branch]);
    }

    /**
     * Pull specific branch from remote
     *
     * Accepts the name of the remote and local branch
     *
     * @param string $remote
     * @param string $branch
     * @return string
     */
    public function pull($remote, $branch)
    {
        return $this->run("pull %s %s", [$remote, $branch]);
    }

    /**
     * List log entries.
     *
     * @param string $format
     * @param string $file
     * @param string|null $limit
     * @return string
     */
    public function logFormatted($format = null, $file = '', $limit = null)
    {
        if ($limit === null) {
            $limitArg = "";
        } else {
            $limitArg = "-{$limit}";
        }

        if ($format === null) {
            return $this->run("log %s %s", [$limitArg, $file]);
        } else {
            return $this->run("log %s --pretty=format:'%s' %s", [$limitArg, $format, $file]);
        }
    }

    /**
     * List log entries with `--grep`
     *
     * @param  string $grep grep by ...
     * @param  string $format
     * @return string
     */
    public function logGrep($grep, $format = null)
    {
        if ($format === null) {
            return $this->run("log --grep='%s'", [$grep]);
        } else {
            return $this->run("log --grep='%s' --pretty=format:'%s'", [$grep, $format]);
        }
    }

    /**
     * @param string $params
     *
     * @return string
     */
    public function log($params = '')
    {
        return $this->run('log %s', [$params]);
    }

    /**
     * Runs a `git diff`
     *
     * @param  string $params
     * @return string
     * @access public
     */
    public function diff($params = '')
    {
        return $this->run("diff %s", [$params]);
    }

    public function show($params = '')
    {
        return $this->run("show %s", [$params]);
    }

    /**
     * Runs a `git diff --cached`
     *
     * @access  public
     */
    public function diffCached()
    {
        return $this->run('diff --cached');
    }

    /**
     * Sets the project description.
     *
     * @param string $new
     */
    public function setDescription($new)
    {
        $path = $this->getGitDirectoryPath();
        file_put_contents($path . "/description", $new);
    }

    /**
     * Gets the project description.
     *
     * @return string
     */
    public function getDescription()
    {
        $path = $this->getGitDirectoryPath();
        return file_get_contents($path . "/description");
    }

    /**
     * Sets custom environment options for calling Git
     *
     * @param string $key key
     * @param string $value value
     */
    public function setenv($key, $value)
    {
        $this->console->setenv($key, $value);
    }

    /*
     * Clears the local repository from the branches, which were deleted from the remote repository.
     *
     * @return string
     */
    public function remotePruneOrigin()
    {
        return $this->run('remote prune origin');
    }

    /**
     * Gets remote branches by pattern
     *
     * @param  string $pattern
     *
     * @access public
     * @return string
     */
    public function getRemoteBranchesByPattern($pattern)
    {
        try {
            return $this->run("branch -r | grep '%s'", [$pattern]);
        } catch (\Exception $ex) {
            /**  @todo handle exceptions right */
            return '';
        }
    }

    /**
     * Gets remote branches count
     *
     * @access public
     * @return int
     */
    public function getRemoteBranchesCount()
    {
        try {
            return $this->run("branch -r | wc -l");
        } catch (\Exception $ex) {
            /**  @todo handle exceptions right */
            return '';
        }
    }

    /**
     * Deletes remote branches
     *
     * @param  array $branches
     *
     * @access public
     * @return void
     */
    public function deleteRemoteBranches(array $branches)
    {
        $this->run("push origin --delete %s", [implode(" ", $branches)]);
    }

    /**
     * Runs git gc command
     *
     * @param  string $command
     *
     * @access public
     * @return bool
     */
    public function gc($command = '')
    {
        try {
            $this->run("gc %s", [$command]);
            return true;
        } catch (\Exception $ex) {
            /**  @todo handle exceptions right */
            return false;
        }
    }

    /**
     * Runs a `rev-parse HEAD`
     *
     * @access  public
     */
    public function revParseHead()
    {
        return trim($this->run('rev-parse HEAD'));
    }

    /**
     * List log entries.
     *
     * @param string $format
     * @param string $file
     * @param string $startHash
     * @param string $endHash
     * @return string
     */
    public function logFileRevisionRange($startHash, $endHash, $format = null, $file = '')
    {
        if ($format === null) {
            return $this->run("log %s..%s %s", [$startHash, $endHash, $file]);
        } else {
            return $this->run("log %s..%s --pretty=format:'%s' %s", [$startHash, $endHash, $format, $file]);
        }
    }
}