<?php

class GitHelper
{
    private ?string $repoUrl;
    private string $branch;
    private string $localPath;
    private ?string $sshKeyPath;
    private string $userName;
    private string $userEmail;
    private string $gitSshCommand;

    public function __construct()
    {
        $this->repoUrl = get_setting('git_repository_url');
        $this->branch = get_setting('git_branch', 'main');
        $this->sshKeyPath = get_setting('git_ssh_key_path');
        $this->userName = get_setting('git_user_name', 'Config Manager');
        $this->userEmail = get_setting('git_user_email', 'bot@config-manager.local');
        
        // Define a dedicated directory for the git repo clone
        $this->localPath = rtrim(sys_get_temp_dir(), '/') . '/config-manager-git';
    }

    /**
     * Clones a specific repository into a unique temporary directory.
     * @param string $repoUrl The URL of the repository to clone.
     * @param string $branch The branch to clone.
     * @return string The path to the temporary directory where the repo was cloned.
     * @throws Exception
     */
    public function cloneOrPull(string $repoUrl, string $branch = 'main'): string
    {
        // Create a unique temporary directory for this clone operation
        $tempDir = rtrim(sys_get_temp_dir(), '/') . '/git_clone_' . uniqid();
        if (is_dir($tempDir)) {
            $this->cleanup($tempDir);
        }
        mkdir($tempDir, 0700, true);

        // Clone the specific repo into the temp directory
        $isSsh = str_starts_with($repoUrl, 'git@');
        $this->execute("clone --branch " . escapeshellarg($branch) . " --single-branch " . escapeshellarg($repoUrl) . " " . escapeshellarg($tempDir), null, $isSsh);

        return $tempDir;
    }

    /**
     * Tests the connection to a remote Git repository.
     * @param string $repoUrl The URL of the repository to test.
     * @throws Exception If the connection fails.
     */
    public function testConnection(string $repoUrl): void
    {
        // The command `ls-remote` is a lightweight way to check if a repo is accessible.
        $isSsh = str_starts_with($repoUrl, 'git@');
        $this->execute("ls-remote --heads " . escapeshellarg($repoUrl), null, $isSsh);
    }

    /**
     * Clones the repository if it doesn't exist locally, or pulls the latest changes if it does.
     * @throws Exception
     */
    public function setupRepository(): string
    {
        if (empty($this->repoUrl)) {
            throw new Exception("Git repository URL is not configured in settings.");
        }
        $isSsh = str_starts_with($this->repoUrl, 'git@');
        if (!is_dir($this->localPath)) {
            // Clone the repo
            $this->execute("clone --branch {$this->branch} {$this->repoUrl} " . escapeshellarg($this->localPath), null, $isSsh);
        } else {
            // Clean up any previous failed attempts and pull
            $this->execute("reset --hard HEAD", $this->localPath, $isSsh);
            $this->execute("clean -fd", $this->localPath, $isSsh);
            $this->execute("pull origin {$this->branch}", $this->localPath, $isSsh);
        }

        // Configure user for this repository
        $this->execute("config user.name " . escapeshellarg($this->userName), $this->localPath);
        $this->execute("config user.email " . escapeshellarg($this->userEmail), $this->localPath);

        return $this->localPath;
    }

    /**
     * Writes the YAML content to the file, adds, commits, and pushes it.
     * @param string $yamlContent The YAML content to write.
     * @param string $commitMessage The commit message.
     * @throws Exception
     */
    public function commitAndPush(string $commitMessage): void
    {
        $isSsh = str_starts_with($this->repoUrl, 'git@');
        // Add all changes (new files, modified files, deleted files)
        $this->execute("add -A", $this->localPath, $isSsh);
        
        // Check for changes before committing
        $statusOutput = $this->execute("status --porcelain", $this->localPath, $isSsh);
        if (empty($statusOutput)) {
            // No changes to commit
            return;
        }

        $this->execute("commit -m " . escapeshellarg($commitMessage), $this->localPath, $isSsh);
        $this->execute("push origin {$this->branch}", $this->localPath, $isSsh);
    }

    /**
     * Removes the temporary local repository.
     * @param string|null $path The specific path to remove. If null, uses the default path.
     */
    public function cleanup(?string $path = null): void
    {
        $dirToRemove = $path ?? $this->localPath;
        if (is_dir($dirToRemove)) {
            $command = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? "rmdir /s /q" : "rm -rf";
            shell_exec($command . " " . escapeshellarg($dirToRemove));
        }
    }

    /**
     * Executes a Git command.
     * @param string $command The git command to run (e.g., "clone ...", "pull ...").
     * @param string|null $workingDir The directory to run the command in.
     * @param bool $isSsh Whether to use the SSH command wrapper.
     * @return string The output from the command.
     * @throws Exception If the command fails.
     */
    private function execute(string $command, ?string $workingDir = null, bool $isSsh = false): string
    {
        $originalDir = null;
        if ($workingDir) {
            $originalDir = getcwd();
            if (!@chdir($workingDir)) {
                throw new Exception("Could not change directory to {$workingDir}");
            }
        }

        $fullCommand = "git {$command} 2>&1";
        if ($isSsh) {
            if (empty($this->sshKeyPath)) {
                throw new Exception("SSH key path is not configured in settings, which is required for SSH URLs.");
            }
            if (!file_exists($this->sshKeyPath)) {
                throw new Exception("SSH key not found at: {$this->sshKeyPath}");
            }
            $this->gitSshCommand = "ssh -i {$this->sshKeyPath} -o IdentitiesOnly=yes -o StrictHostKeyChecking=no";
            $fullCommand = "GIT_SSH_COMMAND='{$this->gitSshCommand}' " . $fullCommand;
        }
        
        exec($fullCommand, $output, $return_var);

        if ($originalDir) {
            chdir($originalDir);
        }

        $outputString = implode("\n", $output);

        if ($return_var !== 0) {
            throw new Exception("Git command failed: [{$fullCommand}]. Output: {$outputString}");
        }

        return $outputString;
    }
}