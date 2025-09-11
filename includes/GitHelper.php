<?php

class GitHelper
{
    private string $repoUrl;
    private string $branch;
    private string $localPath;
    private string $sshKeyPath;
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

        if (empty($this->repoUrl) || empty($this->sshKeyPath)) {
            throw new Exception("Git repository URL and SSH key path must be configured in settings.");
        }

        if (!file_exists($this->sshKeyPath)) {
            throw new Exception("SSH key not found at: {$this->sshKeyPath}");
        }

        $this->gitSshCommand = "ssh -i {$this->sshKeyPath} -o IdentitiesOnly=yes -o StrictHostKeyChecking=no";
    }

    /**
     * Clones the repository if it doesn't exist locally, or pulls the latest changes if it does.
     * @throws Exception
     */
    public function setupRepository(): string
    {
        if (!is_dir($this->localPath)) {
            // Clone the repo
            $this->execute("clone --branch {$this->branch} {$this->repoUrl} " . escapeshellarg($this->localPath));
        } else {
            // Clean up any previous failed attempts and pull
            $this->execute("reset --hard HEAD", $this->localPath);
            $this->execute("clean -fd", $this->localPath);
            $this->execute("pull origin {$this->branch}", $this->localPath);
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
        // Add all changes (new files, modified files, deleted files)
        $this->execute("add -A", $this->localPath);
        
        // Check for changes before committing
        $statusOutput = $this->execute("status --porcelain", $this->localPath);
        if (empty($statusOutput)) {
            // No changes to commit
            return;
        }

        $this->execute("commit -m " . escapeshellarg($commitMessage), $this->localPath);
        $this->execute("push origin {$this->branch}", $this->localPath);
    }

    /**
     * Removes the temporary local repository.
     */
    public function cleanup(): void
    {
        if (is_dir($this->localPath)) {
            $command = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? "rmdir /s /q" : "rm -rf";
            shell_exec($command . " " . escapeshellarg($this->localPath));
        }
    }

    /**
     * Executes a Git command.
     * @param string $command The git command to run (e.g., "clone ...", "pull ...").
     * @param string|null $workingDir The directory to run the command in.
     * @return string The output from the command.
     * @throws Exception If the command fails.
     */
    private function execute(string $command, ?string $workingDir = null): string
    {
        $originalDir = null;
        if ($workingDir) {
            $originalDir = getcwd();
            if (!@chdir($workingDir)) {
                throw new Exception("Could not change directory to {$workingDir}");
            }
        }

        $fullCommand = "GIT_SSH_COMMAND='{$this->gitSshCommand}' git {$command} 2>&1";
        
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