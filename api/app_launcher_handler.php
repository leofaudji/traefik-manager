<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/GitHelper.php';
require_once __DIR__ . '/../includes/DockerClient.php';
require_once __DIR__ . '/../includes/DockerComposeParser.php';
require_once __DIR__ . '/../includes/AppLauncherHelper.php';
require_once __DIR__ . '/../includes/Spyc.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$conn = Database::getInstance()->getConnection();
$repo_path = null;
$temp_dir = null;
$docker_config_dir = null;
$git = new GitHelper();

try {
    // --- Input Validation ---
    $host_id = $_POST['host_id'] ?? null;
    $stack_name = strtolower(trim($_POST['stack_name'] ?? ''));
    $source_type = $_POST['source_type'] ?? 'git';
    $git_url = trim($_POST['git_url'] ?? '');
    $git_branch = trim($_POST['git_branch'] ?? 'main');
    $compose_path = trim($_POST['compose_path'] ?? '');

    // Resource settings
    $replicas = !empty($_POST['deploy_replicas']) ? (int)$_POST['deploy_replicas'] : null;
    $cpu = !empty($_POST['deploy_cpu']) ? $_POST['deploy_cpu'] : null;
    $memory = !empty($_POST['deploy_memory']) ? $_POST['deploy_memory'] : null;
    $network = !empty($_POST['network_name']) ? $_POST['network_name'] : null;
    $volume_path = !empty($_POST['volume_path']) ? trim($_POST['volume_path']) : null;

    // Port mapping settings
    $host_port = !empty($_POST['host_port']) ? (int)$_POST['host_port'] : null;
    $container_port = !empty($_POST['container_port']) ? (int)$_POST['container_port'] : null;
    $container_ip = !empty($_POST['container_ip']) ? trim($_POST['container_ip']) : null;
    $is_update = isset($_POST['update_stack']) && $_POST['update_stack'] === 'true';

    if (empty($host_id) || empty($stack_name)) {
        throw new InvalidArgumentException("Host and Stack Name are required.");
    }

    // Validate stack name format to match Docker's project name constraints
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $stack_name)) {
        throw new InvalidArgumentException("Invalid Stack Name. It must start with a letter or number and can only contain letters, numbers, underscores, periods, or hyphens.");
    }

    $form_params = [
        'stack_name' => $stack_name,
        'replicas' => $replicas,
        'cpu' => $cpu,
        'memory' => $memory,
        'network' => $network,
        'volume_path' => $volume_path,
        'host_port' => $host_port,
        'container_port' => $container_port,
        'container_ip' => $container_ip,
    ];

    $compose_content = '';

    // --- Get Host Details ---
    $stmt = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
    $stmt->bind_param("i", $host_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!($host = $result->fetch_assoc())) {
        throw new Exception("Host not found.");
    }
    $stmt->close();

    // --- Check Host Type (before generating compose file) ---
    $dockerClient = new DockerClient($host);
    $dockerInfo = $dockerClient->getInfo();
    $is_swarm_manager = (isset($dockerInfo['Swarm']['ControlAvailable']) && $dockerInfo['Swarm']['ControlAvailable'] === true);

    // --- Pre-deployment check: Verify stack name is not already in use ---
    $stackExists = false;
    if ($is_swarm_manager) {
        $services = $dockerClient->listServices();
        foreach ($services as $service) {
            if (($service['Spec']['Labels']['com.docker.stack.namespace'] ?? null) === $stack_name) {
                $stackExists = true;
                break;
            }
        }
    } else {
        $containers = $dockerClient->listContainers();
        foreach ($containers as $container) {
            if (($container['Labels']['com.docker.compose.project'] ?? null) === $stack_name) {
                $stackExists = true;
                break;
            }
        }
    }

    if ($stackExists && !$is_update) {
        throw new Exception("A stack with the name '{$stack_name}' already exists on the host '{$host['name']}'. Please choose a different name or use the update feature.");
    }

    // --- Generate Compose Content ---
    if ($source_type === 'git') {
        if (empty($git_url)) throw new InvalidArgumentException("Git URL is required for Git-based deployment.");
        $is_ssh = str_starts_with($git_url, 'git@');
        $is_https = str_starts_with($git_url, 'https://');
        if (!$is_ssh && !$is_https) throw new InvalidArgumentException("Invalid Git URL format. Please use SSH (git@...) or HTTPS (https://...) format.");

        $repo_path = $git->cloneOrPull($git_url, $git_branch);

        // --- Find the correct compose file with fallback ---
        $final_compose_path = '';
        $paths_to_try = [];
        // 1. Path from form input
        if (!empty($compose_path)) $paths_to_try[] = $compose_path;
        // 2. Path from global settings
        if (!empty(get_setting('default_git_compose_path'))) $paths_to_try[] = get_setting('default_git_compose_path');
        // 3. Final fallback
        $paths_to_try[] = 'docker-compose.yml';
        $paths_to_try = array_unique($paths_to_try);

        foreach ($paths_to_try as $path) {
            if (file_exists($repo_path . '/' . $path)) {
                $final_compose_path = $path;
                break; // Found it
            }
        }

        if (empty($final_compose_path)) {
            throw new Exception("Compose file not found. Tried: " . implode(', ', array_map(fn($p) => "'$p'", $paths_to_try)));
        }
        // --- End of find logic ---
        $compose_file_full_path = $repo_path . '/' . $final_compose_path;
        $base_compose_content = file_get_contents($compose_file_full_path);
        if (empty($base_compose_content)) throw new Exception("Compose file '{$final_compose_path}' is empty.");

        $compose_data = DockerComposeParser::YAMLLoad($base_compose_content);
        AppLauncherHelper::applyFormSettings($compose_data, $form_params, $host, $is_swarm_manager);

        $compose_content = Spyc::YAMLDump($compose_data, 2, 0);

    } elseif ($source_type === 'image') {
        $image_name = $_POST['image_name'] ?? '';
        if (empty($image_name)) throw new InvalidArgumentException("Image Name is required for image-based deployment.");
        $form_params['image_name'] = $image_name;

        $compose_data = [
            'version' => '3.8',
            'services' => [
                $stack_name => ['image' => $image_name]
            ]
        ];
        AppLauncherHelper::applyFormSettings($compose_data, $form_params, $host, $is_swarm_manager);
        if (empty($compose_data['networks'])) unset($compose_data['networks']);

        $compose_content = Spyc::YAMLDump($compose_data, 2, 0);

    } else {
        throw new InvalidArgumentException("Invalid source type specified.");
    }

    // --- Deployment ---
    if ($is_swarm_manager) {
        $dockerClient->createStack($stack_name, $compose_content);
        $log_details = "Launched app '{$stack_name}' on Swarm host '{$host['name']}'. Source: {$source_type}.";
        $success_message = "Application '{$stack_name}' is being launched on Swarm host.";
    } else {
        // --- Standalone Host Deployment ---
        $deployment_dir = '';
        $compose_file_name = ''; // Relative path to the compose file within the deployment_dir
        $base_compose_path = get_setting('default_compose_path', '');
        $is_persistent_path = !empty($base_compose_path);

        if ($source_type === 'git') {
            // For Git source, we need the entire repository content, not just the compose file.
            // $repo_path holds the path to the temporarily cloned repo.
            if ($is_persistent_path) {
                $deployment_dir = rtrim($base_compose_path, '/') . '/' . $stack_name;
                // Ensure a clean state by removing the old directory if it exists.
                if (is_dir($deployment_dir)) {
                    shell_exec("rm -rf " . escapeshellarg($deployment_dir));
                }
                // Use a recursive copy for cross-device compatibility, as rename() can fail.
                // First, create the destination directory.
                if (!mkdir($deployment_dir, 0755, true) && !is_dir($deployment_dir)) {
                    throw new \RuntimeException(sprintf('Deployment directory "%s" could not be created.', $deployment_dir));
                }
                // Then, copy the contents of the cloned repo.
                exec("cp -a " . escapeshellarg($repo_path . '/.') . " " . escapeshellarg($deployment_dir), $output, $return_var);
                if ($return_var !== 0) throw new Exception("Failed to copy repository to deployment directory. Output: " . implode("\n", $output));
                // The original temporary repo at $repo_path will be cleaned up by the 'finally' logic.
            } else {
                // Use the temporary cloned repo path directly for deployment.
                $deployment_dir = $repo_path;
                $temp_dir = $deployment_dir; // Mark it for cleanup.
                $repo_path = null; // Prevent double cleanup.
            }
            // The compose file is inside the deployment directory. We just need its full path.
            $compose_file_name = $final_compose_path; // Use the path that was actually found
            $compose_file_full_path = $deployment_dir . '/' . $compose_file_name;
            // Overwrite the original compose file with the one modified by the form settings.
            if (file_put_contents($compose_file_full_path, $compose_content) === false) throw new Exception("Could not write modified compose file to: " . $compose_file_full_path);

        } else {
            // For 'image' source, we only need to create a directory with a single compose file.
            if ($is_persistent_path) {
                $deployment_dir = rtrim($base_compose_path, '/') . '/' . $stack_name;
            } else {
                $deployment_dir = rtrim(sys_get_temp_dir(), '/') . '/app_launcher_' . uniqid();
                $temp_dir = $deployment_dir; // Mark for cleanup.
            }
            if (!is_dir($deployment_dir) && !mkdir($deployment_dir, 0755, true)) throw new Exception("Could not create deployment directory: {$deployment_dir}.");
            $compose_file_name = 'docker-compose.yml';
            $compose_file_full_path = $deployment_dir . '/' . $compose_file_name;
            if (file_put_contents($compose_file_full_path, $compose_content) === false) throw new Exception("Could not write compose file to: " . $compose_file_full_path);
        }

        $env_vars = "DOCKER_HOST=" . escapeshellarg($host['docker_api_url']);
        if ($host['tls_enabled']) {
            $env_vars .= " DOCKER_TLS_VERIFY=1";
            $cert_path_dir = $deployment_dir . '/certs';
            if (!is_dir($cert_path_dir) && !mkdir($cert_path_dir, 0700, true)) throw new Exception("Could not create cert directory in {$deployment_dir}.");
            
            if (!file_exists($host['ca_cert_path'])) throw new Exception("CA certificate not found at: {$host['ca_cert_path']}");
            if (!file_exists($host['client_cert_path'])) throw new Exception("Client certificate not found at: {$host['client_cert_path']}");
            if (!file_exists($host['client_key_path'])) throw new Exception("Client key not found at: {$host['client_key_path']}");

            // Copy certs to the deployment directory
            copy($host['ca_cert_path'], $cert_path_dir . '/ca.pem');
            copy($host['client_cert_path'], $cert_path_dir . '/cert.pem');
            copy($host['client_key_path'], $cert_path_dir . '/key.pem');

            $env_vars .= " DOCKER_CERT_PATH=" . escapeshellarg($cert_path_dir);
        }

        // Set compose to non-interactive mode to prevent it from hanging on prompts.
        $env_vars .= " COMPOSE_NONINTERACTIVE=1";

        // Prepare docker login command if registry credentials are provided
        $login_command = '';
        if (!empty($host['registry_username']) && !empty($host['registry_password'])) {
            // Use a persistent path from .env if available, otherwise use a temporary one.
            $docker_config_path_from_env = Config::get('DOCKER_CONFIG_PATH');
            if (!empty($docker_config_path_from_env)) {
                if (!is_dir($docker_config_path_from_env) && !mkdir($docker_config_path_from_env, 0755, true)) {
                    throw new Exception("Could not create specified DOCKER_CONFIG_PATH: {$docker_config_path_from_env}. Check permissions.");
                }
                $docker_config_dir = $docker_config_path_from_env;
            } else {
                $docker_config_dir = rtrim(sys_get_temp_dir(), '/') . '/docker_config_' . uniqid();
                if (!mkdir($docker_config_dir, 0700, true)) throw new Exception("Could not create temporary docker config directory.");
            }
            $env_vars .= " DOCKER_CONFIG=" . escapeshellarg($docker_config_dir);

            $registry_url = !empty($host['registry_url']) ? escapeshellarg($host['registry_url']) : '';
            // The login command is chained before the pull command.
            $login_command = "echo " . escapeshellarg($host['registry_password']) . " | docker login {$registry_url} -u " . escapeshellarg($host['registry_username']) . " --password-stdin 2>&1 && ";
        }

        // Change directory to the deployment dir before running docker-compose
        // This ensures relative paths (like `build: .`) in the compose file work correctly.
        $cd_command = "cd " . escapeshellarg($deployment_dir);

        // If a volume is requested, ensure its host directory exists before pulling/upping.
        $mkdir_command = '';
        if (!empty($volume_path)) {
            $host_volume_path = rtrim($host['default_volume_path'] ?? '/opt/stacks', '/') . '/' . $stack_name;
            $base_path = dirname($host_volume_path);
            $dir_to_create = basename($host_volume_path);
            // We use a short-lived container to create the directory on the remote host.
            $mkdir_command = "docker run --rm -v " . escapeshellarg($base_path . ':/data') . " alpine mkdir -p " . escapeshellarg('/data/' . $dir_to_create) . " 2>&1 && ";
        }

        // Separate pull and up commands for better error handling and to avoid interactive prompts.
        // First, try to pull all images. This will fail cleanly if an image is private or doesn't exist.
        $compose_pull_command = "docker-compose -p " . escapeshellarg($stack_name) . " -f " . escapeshellarg($compose_file_name) . " pull 2>&1";
        // Then, bring up the stack. Using the pre-pulled images should prevent interactive prompts. Add --renew-anon-volumes to work around a bug in some docker-compose versions.
        $compose_up_command = "docker-compose -p " . escapeshellarg($stack_name) . " -f " . escapeshellarg($compose_file_name) . " up -d --force-recreate --remove-orphans --renew-anon-volumes 2>&1";
        $script_to_run = $cd_command . ' && ' . $login_command . $mkdir_command . $compose_pull_command . ' && ' . $compose_up_command;
        $full_command = 'env ' . $env_vars . ' sh -c ' . escapeshellarg($script_to_run);

        exec($full_command, $output, $return_var);

        // Cleanup temporary docker config directory immediately after use
        if (empty(Config::get('DOCKER_CONFIG_PATH')) && isset($docker_config_dir) && is_dir($docker_config_dir)) {
             shell_exec("rm -rf " . escapeshellarg($docker_config_dir));
        }

        if ($return_var !== 0) {
            $full_output = implode("\n", $output);
            // Check for a specific, common error to provide a more helpful message.
            if (str_contains(strtolower($full_output), 'pull access denied')) {
                throw new Exception("Image pull failed. The repository may be private or the image name may be incorrect. Please ensure the target host has access to the image and is logged in via 'docker login' if necessary. Full error: " . $full_output);
            }
            throw new Exception("Docker-compose deployment failed. Output: " . $full_output);
        }

        $log_details = "Launched app '{$stack_name}' on standalone host '{$host['name']}'. Source: {$source_type}.";
        $success_message = "Application '{$stack_name}' is being launched on standalone host.";
    }

    // --- Prepare deployment details for saving ---
    $deployment_details_to_save = $_POST;
    // Unset fields we don't want to store or that are large/irrelevant for re-deployment
    unset($deployment_details_to_save['id']); // This is the host ID, not needed
    $deployment_details_json = json_encode($deployment_details_to_save);

    // --- Record deployment in the database ---
    $compose_file_to_save = '';
    if ($source_type === 'git') {
        // $final_compose_path is defined in the git source type block
        $compose_file_to_save = $final_compose_path ?? 'docker-compose.yml';
    } else { // image
        $compose_file_to_save = 'docker-compose.yml';
    }

    $stmt_stack = $conn->prepare(
        "INSERT INTO application_stacks (host_id, stack_name, source_type, compose_file_path, deployment_details) 
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE source_type = VALUES(source_type), compose_file_path = VALUES(compose_file_path), deployment_details = VALUES(deployment_details), updated_at = NOW()"
    );
    $stmt_stack->bind_param("issss", $host_id, $stack_name, $source_type, $compose_file_to_save, $deployment_details_json);
    $stmt_stack->execute();
    $stmt_stack->close();

    // --- Finalize ---
    if (isset($git) && isset($repo_path)) $git->cleanup($repo_path);
    // Only cleanup if it was a temporary directory (i.e., $temp_dir was set)
    if (isset($temp_dir) && is_dir($temp_dir)) {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            shell_exec("rmdir /s /q " . escapeshellarg($temp_dir));
        } else {
            shell_exec("rm -rf " . escapeshellarg($temp_dir));
        }
    }

    log_activity($_SESSION['username'], 'App Launched', $log_details);
    echo json_encode(['status' => 'success', 'message' => $success_message]);

} catch (Exception $e) {
    if (isset($git) && isset($repo_path)) $git->cleanup($repo_path);
    // Only cleanup if it was a temporary directory
    if (isset($temp_dir) && is_dir($temp_dir)) {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            shell_exec("rmdir /s /q " . escapeshellarg($temp_dir));
        } else {
            shell_exec("rm -rf " . escapeshellarg($temp_dir));
        }
    }
    if (empty(Config::get('DOCKER_CONFIG_PATH')) && isset($docker_config_dir) && is_dir($docker_config_dir)) {
         shell_exec("rm -rf " . escapeshellarg($docker_config_dir));
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>