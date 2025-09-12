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
    if (!preg_match('/^\/api\/hosts\/(\d+)\/(networks|images)/', $request_uri_path, $matches)) {
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
        if (str_ends_with($request_uri_path, '/networks')) {
            // LIST networks
            $networks = $dockerClient->listNetworks();
            echo json_encode(['status' => 'success', 'data' => $networks]);
        } elseif (str_ends_with($request_uri_path, '/images')) {
            // LIST images
            $url = $host['docker_api_url'];
            $is_socket = strpos($url, 'unix://') === 0;

            $ch = curl_init();
            
            if ($is_socket) {
                $socket_path = substr($url, 7);
                curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, $socket_path);
                curl_setopt($ch, CURLOPT_URL, 'http://localhost/images/json'); // URL is arbitrary for socket
            } else {
                // Convert tcp:// to http(s):// for cURL
                $curl_url = ($host['tls_enabled'] ? 'https://' : 'http://') . str_replace('tcp://', '', $url);
                curl_setopt($ch, CURLOPT_URL, rtrim($curl_url, '/') . '/images/json');
            }

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            if (!empty($host['tls_enabled'])) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                if (!empty($host['ca_cert_path']) && file_exists($host['ca_cert_path'])) curl_setopt($ch, CURLOPT_CAINFO, $host['ca_cert_path']);
                if (!empty($host['client_cert_path']) && file_exists($host['client_cert_path'])) curl_setopt($ch, CURLOPT_SSLCERT, $host['client_cert_path']);
                if (!empty($host['client_key_path']) && file_exists($host['client_key_path'])) curl_setopt($ch, CURLOPT_SSLKEY, $host['client_key_path']);
            } else {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            }

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) throw new Exception("cURL Error: " . $error);
            if ($http_code !== 200) throw new Exception("Docker API returned HTTP " . $http_code . ". Response: " . $response);

            $images_data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) throw new Exception("Failed to decode JSON response from Docker API.");

            if (isset($_GET['details']) && $_GET['details'] === 'true') {
                // Filter out dangling images (<none>:<none>) for the detailed view
                $detailed_images = array_filter($images_data, function($image) {
                    return !(empty($image['RepoTags']) || $image['RepoTags'][0] === '<none>:<none>');
                });
                echo json_encode(['status' => 'success', 'data' => array_values($detailed_images)]);
            } else {
                // Original behavior for App Launcher
                $all_tags = [];
                if (is_array($images_data)) {
                    foreach ($images_data as $image) {
                        if (!empty($image['RepoTags']) && is_array($image['RepoTags'])) {
                            $all_tags = array_merge($all_tags, array_filter($image['RepoTags'], fn($tag) => $tag !== '<none>:<none>'));
                        }
                    }
                }
                echo json_encode(['status' => 'success', 'data' => array_values(array_unique($all_tags))]);
            }
        }

    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'create';

        if ($action === 'delete_image') {
            // DELETE image
            $image_id = $_POST['image_id'] ?? '';
            if (empty($image_id)) {
                throw new InvalidArgumentException("Image ID is required for deletion.");
            }
            $dockerClient->removeImage($image_id);
            log_activity($_SESSION['username'], 'Image Deleted', "Deleted image ID '{$image_id}' on host '{$host['name']}'.");
            echo json_encode(['status' => 'success', 'message' => "Image successfully deleted."]);
        } elseif ($action === 'create') {
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