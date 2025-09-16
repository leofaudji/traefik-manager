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

    if (empty($host_id) || empty($stack_name)) {
        throw new InvalidArgumentException("Host and Stack Name are required for preview.");
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

    // --- Get Host Details ---
    $stmt = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
    $stmt->bind_param("i", $host_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!($host = $result->fetch_assoc())) {
        throw new Exception("Host not found.");
    }
    $stmt->close();

    // --- Check Host Type ---
    $dockerClient = new DockerClient($host);
    $dockerInfo = $dockerClient->getInfo();
    $is_swarm_manager = (isset($dockerInfo['Swarm']['ControlAvailable']) && $dockerInfo['Swarm']['ControlAvailable'] === true);

    $compose_content = '';

    if ($source_type === 'git') {
        if (empty($git_url)) {
            throw new InvalidArgumentException("Git URL is required for Git-based preview.");
        }
        // --- Generate Compose Content ---
        $repo_path = $git->cloneOrPull($git_url, $git_branch);

        $final_compose_path = '';
        $paths_to_try = [];
        if (!empty($compose_path)) $paths_to_try[] = $compose_path;
        // Path from global settings
        if (!empty(get_setting('default_git_compose_path'))) $paths_to_try[] = get_setting('default_git_compose_path');
        $paths_to_try[] = 'docker-compose.yml'; // Final fallback
        $paths_to_try = array_unique($paths_to_try);

        foreach ($paths_to_try as $path) {
            if (file_exists($repo_path . '/' . $path)) {
                $final_compose_path = $path;
                break;
            }
        }

        if (empty($final_compose_path)) {
            throw new Exception("Compose file not found. Tried: " . implode(', ', array_map(fn($p) => "'$p'", $paths_to_try)));
        }
        
        $compose_file_full_path = $repo_path . '/' . $final_compose_path;
        $base_compose_content = file_get_contents($compose_file_full_path);
        if (empty($base_compose_content)) throw new Exception("Compose file '{$final_compose_path}' is empty.");

        $compose_data = DockerComposeParser::YAMLLoad($base_compose_content);
        AppLauncherHelper::applyFormSettings($compose_data, $form_params, $host, $is_swarm_manager);
        $compose_content = Spyc::YAMLDump($compose_data, 2, 0);

    } elseif ($source_type === 'image') {
        $image_name = $_POST['image_name'] ?? '';
        if (empty($image_name)) throw new InvalidArgumentException("Image Name is required for image-based preview.");
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
        throw new InvalidArgumentException("Invalid source type specified for preview.");
    }

    // --- Return YAML content ---
    echo json_encode(['status' => 'success', 'yaml' => $compose_content]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} finally {
    // Always clean up the cloned repo
    if (isset($git) && isset($repo_path)) {
        $git->cleanup($repo_path);
    }
    if (isset($conn)) {
        $conn->close();
    }
}
?>