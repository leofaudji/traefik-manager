<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/GitHelper.php';
require_once __DIR__ . '/../includes/DockerClient.php';
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
$git = new GitHelper();

try {
    // --- Input Validation ---
    $host_id = $_POST['host_id'] ?? null;
    $stack_name = trim($_POST['stack_name'] ?? '');
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
    $host_ip = !empty($_POST['host_ip']) ? trim($_POST['host_ip']) : null;
    $host_port = !empty($_POST['host_port']) ? (int)$_POST['host_port'] : null;
    $container_port = !empty($_POST['container_port']) ? (int)$_POST['container_port'] : null;

    if (empty($host_id) || empty($stack_name)) {
        throw new InvalidArgumentException("Host and Stack Name are required.");
    }

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
        // 2. Path from host's default setting in DB
        if (!empty($host['default_git_compose_path'])) $paths_to_try[] = $host['default_git_compose_path'];
        // 3. Default fallback
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

        $compose_data = Spyc::YAMLLoad($base_compose_content);

        if (isset($compose_data['services']) && is_array($compose_data['services'])) {
            $first_service_key = array_key_first($compose_data['services']);
            if ($first_service_key) {
                if ($replicas || $cpu || $memory) {
                    if (!isset($compose_data['services'][$first_service_key]['deploy'])) $compose_data['services'][$first_service_key]['deploy'] = [];
                    if ($replicas) $compose_data['services'][$first_service_key]['deploy']['replicas'] = $replicas;
                    if ($cpu || $memory) {
                        if (!isset($compose_data['services'][$first_service_key]['deploy']['resources'])) $compose_data['services'][$first_service_key]['deploy']['resources'] = ['limits' => []];
                        if ($cpu) $compose_data['services'][$first_service_key]['deploy']['resources']['limits']['cpus'] = $cpu;
                        if ($memory) $compose_data['services'][$first_service_key]['deploy']['resources']['limits']['memory'] = $memory;
                    }
                }
                if ($network) {
                    if (!isset($compose_data['services'][$first_service_key]['networks'])) $compose_data['services'][$first_service_key]['networks'] = [];
                    if (!in_array($network, $compose_data['services'][$first_service_key]['networks'])) $compose_data['services'][$first_service_key]['networks'][] = $network;
                    if (!isset($compose_data['networks'])) $compose_data['networks'] = [];
                    $compose_data['networks'][$network]['external'] = true;
                }
                if ($volume_path) {
                    $host_volume_path = rtrim($host['default_volume_path'] ?? '/opt/stacks', '/') . '/' . $stack_name . '/data';
                    if (!isset($compose_data['services'][$first_service_key]['volumes'])) $compose_data['services'][$first_service_key]['volumes'] = [];
                    $compose_data['services'][$first_service_key]['volumes'][] = $host_volume_path . ':' . $volume_path;
                }
                if ($host_port && $container_port) {
                    if (!isset($compose_data['services'][$first_service_key]['ports'])) $compose_data['services'][$first_service_key]['ports'] = [];
                    $port_mapping = ($host_ip ? $host_ip . ':' : '') . $host_port . ':' . $container_port;
                    $compose_data['services'][$first_service_key]['ports'][] = $port_mapping;
                }
            }
        }
        $compose_content = Spyc::YAMLDump($compose_data, 2, 0);

    } elseif ($source_type === 'image') {
        $image_name = $_POST['image_name'] ?? '';
        if (empty($image_name)) throw new InvalidArgumentException("Image Name is required for image-based deployment.");

        $compose_data = ['version' => '3.8', 'services' => [], 'networks' => []];
        $service = ['image' => $image_name];

        if ($replicas || $cpu || $memory) {
            $service['deploy'] = [];
            if ($replicas) $service['deploy']['replicas'] = $replicas;
            if ($cpu || $memory) {
                $service['deploy']['resources'] = ['limits' => []];
                if ($cpu) $service['deploy']['resources']['limits']['cpus'] = $cpu;
                if ($memory) $service['deploy']['resources']['limits']['memory'] = $memory;
            }
        }
        if ($network) {
            $service['networks'] = [$network];
            $compose_data['networks'][$network] = ['external' => true];
        }
        if ($volume_path) {
            $host_volume_path = rtrim($host['default_volume_path'] ?? '/opt/stacks', '/') . '/' . $stack_name . '/data';
            $service['volumes'] = [$host_volume_path . ':' . $volume_path];
        }
        if ($host_port && $container_port) {
            $port_mapping = ($host_ip ? $host_ip . ':' : '') . $host_port . ':' . $container_port;
            $service['ports'] = [$port_mapping];
        }

        $compose_data['services'][$stack_name] = $service;
        if (empty($compose_data['networks'])) unset($compose_data['networks']);

        $compose_content = Spyc::YAMLDump($compose_data, 2, 0);
    } else {
        throw new InvalidArgumentException("Invalid source type specified.");
    }

    // --- Deployment ---
    $dockerClient = new DockerClient($host);
    $dockerInfo = $dockerClient->getInfo();
    $is_swarm_manager = (isset($dockerInfo['Swarm']['ControlAvailable']) && $dockerInfo['Swarm']['ControlAvailable'] === true);

    if ($is_swarm_manager) {
        $dockerClient->createStack($stack_name, $compose_content);
        $log_details = "Launched app '{$stack_name}' on Swarm host '{$host['name']}'. Source: {$source_type}.";
        $success_message = "Application '{$stack_name}' is being launched on Swarm host.";
    } else {
        // --- Standalone Host Deployment ---
        $deployment_dir = '';
        $compose_file_name = ''; // Relative path to the compose file within the deployment_dir
        $base_compose_path = $host['default_compose_path'] ?? '';
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
                // Move the cloned repo to the persistent deployment directory.
                if (!rename($repo_path, $deployment_dir)) throw new Exception("Failed to move repository to deployment directory.");
                $repo_path = null; // Prevent the GitHelper from cleaning up our new persistent directory.
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

        // Change directory to the deployment dir before running docker-compose
        // This ensures relative paths (like `build: .`) in the compose file work correctly.
        $cd_command = "cd " . escapeshellarg($deployment_dir);
        $compose_command = "docker-compose -p " . escapeshellarg($stack_name) . " -f " . escapeshellarg($compose_file_name) . " up -d --remove-orphans 2>&1";
        $full_command = $env_vars . ' ' . $cd_command . ' && ' . $compose_command;

        exec($full_command, $output, $return_var);

        if ($return_var !== 0) throw new Exception("Docker-compose deployment failed. Output: " . implode("\n", $output));

        $log_details = "Launched app '{$stack_name}' on standalone host '{$host['name']}'. Source: {$source_type}.";
        $success_message = "Application '{$stack_name}' is being launched on standalone host.";
    }

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
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>