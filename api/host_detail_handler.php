<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';

header('Content-Type: application/json');

$request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!preg_match('/\/api\/hosts\/(\d+)\/containers/', $request_path, $matches)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid host ID in URL.']);
    exit;
}
$id = $matches[1];

// Get pagination and filter params
$limit_get = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$limit = ($limit_get == -1) ? 1000000 : $limit_get;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$filter = $_GET['filter'] ?? 'all'; // 'all', 'running', 'stopped'
$offset = ($page - 1) * $limit;

$conn = Database::getInstance()->getConnection();

try {
    $stmt = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!($host = $result->fetch_assoc())) {
        throw new Exception("Host not found.");
    }
    $stmt->close();

    $dockerClient = new DockerClient($host);
    $containers = $dockerClient->listContainers();
    
    // Filter containers based on state
    $filteredContainers = [];
    if ($filter === 'running') {
        $filteredContainers = array_filter($containers, fn($c) => $c['State'] === 'running');
    } elseif ($filter === 'stopped') {
        $filteredContainers = array_filter($containers, fn($c) => $c['State'] === 'exited');
    } else {
        $filteredContainers = $containers;
    }
    $filteredContainers = array_values($filteredContainers); // Re-index array

    // Paginate the filtered array
    $total_items = count($filteredContainers);
    $total_pages = ($limit_get == -1) ? 1 : ceil($total_items / $limit);
    $paginatedContainers = array_slice($filteredContainers, $offset, $limit);

    // Generate HTML for the table rows
    $html = '';
    if (empty($paginatedContainers)) {
        $html = '<tr><td colspan="8" class="text-center">No containers found for the current filter.</td></tr>';
    } else {
        foreach ($paginatedContainers as $cont) {
            $name = $cont['Names'] && count($cont['Names']) > 0 ? htmlspecialchars(substr($cont['Names'][0], 1)) : 'N/A';
            $state = htmlspecialchars($cont['State']);
            $status = htmlspecialchars($cont['Status']);
            
            $stateBadgeClass = 'secondary';
            if ($state === 'running') $stateBadgeClass = 'success';
            elseif ($state === 'exited') $stateBadgeClass = 'danger';
            elseif ($state === 'restarting') $stateBadgeClass = 'warning';

            // Build IP Address HTML instead of Ports
            $ipAddressHtml = '';
            if (!empty($cont['NetworkSettings']['Networks'])) {
                $ip_parts = [];
                foreach ($cont['NetworkSettings']['Networks'] as $netName => $netDetails) {
                    if (!empty($netDetails['IPAddress'])) {
                        $ip_parts[] = '<code>' . htmlspecialchars($netDetails['IPAddress']) . '</code> <small class="text-muted">(' . htmlspecialchars($netName) . ')</small>';
                    }
                }
                if (!empty($ip_parts)) {
                    $ipAddressHtml = implode('<br>', $ip_parts);
                }
            }
            if (empty($ipAddressHtml)) $ipAddressHtml = '<span class="text-muted small">N/A</span>';

            // Build Volumes/Mounts HTML
            $volumesHtml = '';
            if (!empty($cont['Mounts'])) {
                foreach ($cont['Mounts'] as $mount) {
                    $source = htmlspecialchars($mount['Source']);
                    $destination = htmlspecialchars($mount['Destination']);
                    $type = htmlspecialchars($mount['Type']);
                    // Shorten long source paths for display
                    $short_source = strlen($source) > 30 ? '...' . substr($source, -27) : $source;
                    $volumesHtml .= "<div class='d-block small' data-bs-toggle='tooltip' title='{$type}: {$source} -> {$destination}'><i class='bi bi-hdd-fill me-1'></i> {$short_source}</div>";
                }
            } else {
                $volumesHtml = '<span class="text-muted small">None</span>';
            }

            // Build Networks HTML
            $networksHtml = '';
            if (!empty($cont['NetworkSettings']['Networks'])) {
                foreach ($cont['NetworkSettings']['Networks'] as $netName => $netDetails) {
                    $ip = !empty($netDetails['IPAddress']) ? " ({$netDetails['IPAddress']})" : '';
                    $networksHtml .= "<span class='badge bg-secondary me-1' data-bs-toggle='tooltip' title='" . htmlspecialchars($netName) . $ip . "'>" . htmlspecialchars($netName) . "</span>";
                }
            } else {
                $networksHtml = '<span class="text-muted small">None</span>';
            }

            $actionButtons = '<div class="btn-group" role="group">';
            if ($state === 'running') {
                $actionButtons .= "<button class=\"btn btn-sm btn-outline-warning container-action-btn\" data-container-id=\"{$cont['Id']}\" data-action=\"restart\" title=\"Restart\"><i class=\"bi bi-arrow-repeat\"></i></button>";
                $actionButtons .= "<button class=\"btn btn-sm btn-outline-danger container-action-btn\" data-container-id=\"{$cont['Id']}\" data-action=\"stop\" title=\"Stop\"><i class=\"bi bi-stop-fill\"></i></button>";
            } else {
                $actionButtons .= "<button class=\"btn btn-sm btn-outline-success container-action-btn\" data-container-id=\"{$cont['Id']}\" data-action=\"start\" title=\"Start\"><i class=\"bi bi-play-fill\"></i></button>";
            }
            $actionButtons .= "<button class=\"btn btn-sm btn-outline-primary view-logs-btn\" data-bs-toggle=\"modal\" data-bs-target=\"#viewLogsModal\" data-container-id=\"{$cont['Id']}\" data-container-name=\"{$name}\" title=\"View Logs\"><i class=\"bi bi-card-text\"></i></button>";
            $actionButtons .= '</div>';

            $html .= "<tr><td>{$name}</td><td><small>" . htmlspecialchars($cont['Image']) . "</small></td><td><span class=\"badge bg-{$stateBadgeClass}\">{$state}</span></td><td>{$status}</td><td>{$ipAddressHtml}</td><td>{$volumesHtml}</td><td>{$networksHtml}</td><td class=\"text-end\">{$actionButtons}</td></tr>";
        }
    }

    echo json_encode([
        'status' => 'success',
        'html' => $html,
        'total_pages' => $total_pages,
        'current_page' => $page,
        'limit' => $limit_get,
        'info' => "Showing <strong>" . count($paginatedContainers) . "</strong> of <strong>{$total_items}</strong> containers."
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>