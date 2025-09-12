<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';
require_once __DIR__ . '/../includes/Spyc.php';

header('Content-Type: application/json');

$request_uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = BASE_PATH;
if ($basePath && strpos($request_uri_path, $basePath) === 0) {
    $request_uri_path = substr($request_uri_path, strlen($basePath));
}

$conn = Database::getInstance()->getConnection();

try {
    if (!preg_match('/^\/api\/hosts\/(\d+)\//', $request_uri_path, $matches)) {
        throw new InvalidArgumentException("Invalid API endpoint format.");
    }
    $host_id = $matches[1];

    $stmt = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
    $stmt->bind_param("i", $host_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!($host = $result->fetch_assoc())) {
        throw new Exception("Host not found.");
    }
    $stmt->close();

    $dockerClient = new DockerClient($host);

    // --- GET Stack Spec Logic ---
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('/^\/api\/hosts\/\d+\/stacks\/([a-zA-Z0-9_-]+)\/spec$/', $request_uri_path, $spec_matches)) {
        $stack_name = $spec_matches[1];
        $all_services = $dockerClient->listServices();
        
        $stack_services_spec = [];
        foreach ($all_services as $service) {
            $stack_namespace = $service['Spec']['Labels']['com.docker.stack.namespace'] ?? null;
            if ($stack_namespace === $stack_name) {
                // We only care about the spec for generating the YAML
                $service_name = str_replace($stack_name . '_', '', $service['Spec']['Name']);
                $stack_services_spec[$service_name] = $service['Spec'];
            }
        }

        if (empty($stack_services_spec)) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'No services found for this stack.']);
            exit;
        }

        $yaml_output = Spyc::YAMLDump(['services' => $stack_services_spec], 2, 0);

        echo json_encode(['status' => 'success', 'content' => $yaml_output]);
        $conn->close();
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $dockerInfo = $dockerClient->getInfo();
        $discovered_stacks = [];

        // Check if the node is a Swarm manager
        if (isset($dockerInfo['Swarm']['ControlAvailable']) && $dockerInfo['Swarm']['ControlAvailable'] === true) {
            $remote_services = $dockerClient->listServices();
            foreach ($remote_services as $service) {
                $stack_namespace = $service['Spec']['Labels']['com.docker.stack.namespace'] ?? null;
                if ($stack_namespace) {
                    if (!isset($discovered_stacks[$stack_namespace])) {
                        $discovered_stacks[$stack_namespace] = ['Name' => $stack_namespace, 'Services' => 0, 'CreatedAt' => $service['CreatedAt']];
                    }
                    $discovered_stacks[$stack_namespace]['Services']++;
                    if (strtotime($service['CreatedAt']) < strtotime($discovered_stacks[$stack_namespace]['CreatedAt'])) {
                        $discovered_stacks[$stack_namespace]['CreatedAt'] = $service['CreatedAt'];
                    }
                }
            }
        } else {
            // Not a swarm manager, so we look for docker-compose projects from container labels
            $containers = $dockerClient->listContainers();
            foreach ($containers as $container) {
                $compose_project = $container['Labels']['com.docker.compose.project'] ?? null;
                if ($compose_project) {
                    if (!isset($discovered_stacks[$compose_project])) {
                        $discovered_stacks[$compose_project] = ['Name' => $compose_project, 'Services' => 0, 'CreatedAt' => date('c', $container['Created'])];
                    }
                    $discovered_stacks[$compose_project]['Services']++;
                    if ($container['Created'] < strtotime($discovered_stacks[$compose_project]['CreatedAt'])) {
                        $discovered_stacks[$compose_project]['CreatedAt'] = date('c', $container['Created']);
                    }
                }
            }
        }

        $stacks = [];
        foreach ($discovered_stacks as $stack_name => $stack_data) {
            
            $stacks[] = [
                'ID' => $stack_name, // Use the name as the ID. For Swarm, this is the stack name, not the ID from the /stacks endpoint.
                'Name' => $stack_name,
                'Services' => $stack_data['Services'],
                'CreatedAt' => $stack_data['CreatedAt'],
                'Managed' => false, // No longer managed by DB
                'DbId' => null,
                'Description' => 'Stack deployed directly on the host.'
            ];
        }

        echo json_encode(['status' => 'success', 'data' => $stacks]);

    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'create';

        // All POST actions (create, update, delete) for stacks are Swarm-specific.
        // Add a guard to prevent these operations on non-swarm nodes.
        $dockerInfo = $dockerClient->getInfo();
        $is_swarm_manager = (isset($dockerInfo['Swarm']['ControlAvailable']) && $dockerInfo['Swarm']['ControlAvailable'] === true);
        if (!$is_swarm_manager) {
            throw new Exception('Stack operations are only supported on Docker Swarm managers. This host is a standalone node.');
        }

        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            $compose_array = buildComposeArrayFromPost($_POST);
            $compose_content = Spyc::YAMLDump($compose_array, 2, 0);

            if (empty($name) || empty($compose_content)) {
                throw new InvalidArgumentException("Stack name and compose content are required.");
            }

            // Create on remote host
            $dockerClient->createStack($name, $compose_content);

            log_activity($_SESSION['username'], 'Stack Created', "Created stack '{$name}' on host '{$host['name']}'.");
            echo json_encode(['status' => 'success', 'message' => "Stack '{$name}' created successfully on host '{$host['name']}'."]);

        } elseif ($action === 'update') {
            // This action is deprecated as we no longer store compose files for editing.
            throw new InvalidArgumentException("Update action is no longer supported. Please create a new stack or redeploy.");

        } elseif ($action === 'delete') {
            $stack_id = $_POST['stack_id'] ?? ''; // Docker Stack Name/ID

            if (empty($stack_id)) {
                throw new InvalidArgumentException("Stack ID is required for deletion.");
            }

            // Delete from remote host
            $dockerClient->removeStack($stack_id);

            log_activity($_SESSION['username'], 'Stack Deleted', "Deleted stack ID '{$stack_id}' on host '{$host['name']}'.");
            echo json_encode(['status' => 'success', 'message' => "Stack successfully deleted."]);
        } else {
            throw new InvalidArgumentException("Invalid action specified.");
        }
    } else {
        throw new InvalidArgumentException("Unsupported request method.");
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();

// This function is duplicated from api/stack_handler.php.
// In a larger refactor, this should be moved to a shared helper/class.
function buildComposeArrayFromPost(array $postData): array {
    $compose = ['version' => '3.8', 'services' => [], 'networks' => []];
    if (isset($postData['services']) && is_array($postData['services'])) {
        foreach ($postData['services'] as $serviceData) {
            $serviceName = $serviceData['name'];
            if (empty($serviceName)) continue;
            $compose['services'][$serviceName] = ['image' => $serviceData['image'] ?? 'alpine:latest'];
            if (!empty($serviceData['deploy']['replicas']) || !empty($serviceData['deploy']['resources']['limits']['cpus']) || !empty($serviceData['deploy']['resources']['limits']['memory'])) {
                $compose['services'][$serviceName]['deploy'] = [];
                if (!empty($serviceData['deploy']['replicas'])) $compose['services'][$serviceName]['deploy']['replicas'] = (int)$serviceData['deploy']['replicas'];
                if (!empty($serviceData['deploy']['resources']['limits']['cpus']) || !empty($serviceData['deploy']['resources']['limits']['memory'])) {
                    $compose['services'][$serviceName]['deploy']['resources']['limits'] = [];
                    if (!empty($serviceData['deploy']['resources']['limits']['cpus'])) $compose['services'][$serviceName]['deploy']['resources']['limits']['cpus'] = $serviceData['deploy']['resources']['limits']['cpus'];
                    if (!empty($serviceData['deploy']['resources']['limits']['memory'])) $compose['services'][$serviceName]['deploy']['resources']['limits']['memory'] = $serviceData['deploy']['resources']['limits']['memory'];
                }
            }
            foreach (['ports', 'environment', 'volumes', 'networks', 'depends_on'] as $key) {
                if (!empty($serviceData[$key]) && is_array($serviceData[$key])) $compose['services'][$serviceName][$key] = array_values(array_filter($serviceData[$key]));
            }
        }
    }
    if (isset($postData['networks']) && is_array($postData['networks'])) {
        foreach ($postData['networks'] as $networkData) {
            $networkName = $networkData['name'];
            if (empty($networkName)) continue;
            $compose['networks'][$networkName] = null;
        }
    }
    return $compose;
}
?>