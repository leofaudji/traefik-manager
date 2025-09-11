<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';

header('Content-Type: application/json');

$request_uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = BASE_PATH;
if ($basePath && strpos($request_uri_path, $basePath) === 0) {
    $request_uri_path = substr($request_uri_path, strlen($basePath));
}

$conn = Database::getInstance()->getConnection();

// --- Main Logic ---
try {
    // Extract Host ID
    if (!preg_match('/^\/api\/hosts\/(\d+)\/networks/', $request_uri_path, $matches)) {
        throw new InvalidArgumentException("Invalid API endpoint format.");
    }
    $host_id = $matches[1];

    // Get Host details
    $stmt = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
    $stmt->bind_param("i", $host_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!($host = $result->fetch_assoc())) {
        throw new Exception("Host not found.");
    }
    $stmt->close();

    $dockerClient = new DockerClient($host);

    // --- Handle different request methods ---
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // LIST networks
        $networks = $dockerClient->listNetworks();
        echo json_encode(['status' => 'success', 'data' => $networks]);

    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'create';

        if ($action === 'create') {
            // CREATE network
            $name = trim($_POST['name'] ?? '');
            if (empty($name)) {
                throw new InvalidArgumentException("Network name is required.");
            }
            
            $config = ['Name' => $name];
            if (!empty($_POST['driver'])) {
                $config['Driver'] = $_POST['driver'];
            }
            // Add macvlan parent option if specified
            if ($_POST['driver'] === 'macvlan' && !empty($_POST['macvlan_parent'])) {
                $config['Options'] = ['parent' => $_POST['macvlan_parent']];
            }
            if (isset($_POST['attachable'])) {
                $config['Attachable'] = (bool)$_POST['attachable'];
            }
            if (!empty($_POST['labels']) && is_array($_POST['labels'])) {
                $labels = [];
                foreach ($_POST['labels'] as $label) {
                    if (strpos($label, '=') !== false) {
                        list($key, $value) = explode('=', $label, 2);
                        $labels[trim($key)] = trim($value);
                    }
                }
                if (!empty($labels)) {
                    $config['Labels'] = $labels;
                }
            }

            // IPAM Configuration
            if (!empty($_POST['ipam_subnet'])) {
                $ipamConfig = ['Subnet' => $_POST['ipam_subnet']];
                if (!empty($_POST['ipam_gateway'])) {
                    $ipamConfig['Gateway'] = $_POST['ipam_gateway'];
                }
                if (!empty($_POST['ipam_ip_range'])) {
                    $ipamConfig['IPRange'] = $_POST['ipam_ip_range'];
                }
                $config['IPAM'] = [
                    'Driver' => 'default',
                    'Config' => [$ipamConfig]
                ];
            }

            $response = $dockerClient->createNetwork($config);
            log_activity($_SESSION['username'], 'Network Created', "Created network '{$name}' on host '{$host['name']}'.");
            echo json_encode(['status' => 'success', 'message' => "Network '{$name}' created successfully.", 'data' => $response]);

        } elseif ($action === 'delete') {
            // DELETE network
            $network_id = $_POST['network_id'] ?? '';
            if (empty($network_id)) {
                throw new InvalidArgumentException("Network ID is required for deletion.");
            }
            $dockerClient->removeNetwork($network_id);
            log_activity($_SESSION['username'], 'Network Deleted', "Deleted network ID '{$network_id}' on host '{$host['name']}'.");
            echo json_encode(['status' => 'success', 'message' => "Network successfully deleted."]);
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
?>