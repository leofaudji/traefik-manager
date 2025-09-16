<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once '../includes/Spyc.php';

// Ultimate safety net for fatal errors to ensure a JSON response
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING])) {
        // If headers have not been sent, we can send a proper JSON error response.
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => "Fatal Server Error. Please check server logs. Type: {$error['type']}"
            ]);
        }
    }
});

// Custom error handler to ensure all errors are returned as JSON
set_error_handler(function ($severity, $message, $file, $line) {
    // We will only handle warnings and notices. Fatal errors will be caught by the try-catch block.
    if (!(error_reporting() & $severity)) {
        // This error code is not included in error_reporting
        return;
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => "Internal Server Error: {$message} in {$file} on line {$line}"]);
    exit();
});

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Metode permintaan tidak diizinkan.']);
    exit();
}

if (!isset($_FILES['yamlFile']) || $_FILES['yamlFile']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Gagal mengunggah file atau tidak ada file yang dipilih.']);
    exit();
}

$conn = Database::getInstance()->getConnection();
$yamlContent = file_get_contents($_FILES['yamlFile']['tmp_name']);
$data = Spyc::YAMLLoad($yamlContent);

if (empty($data) || !is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'File YAML kosong atau formatnya tidak valid.']);
    exit();
}

$conn->begin_transaction();

try {
    // 1. Import/Update Services and Servers (Safely)
    $http_data = (isset($data['http']) && is_array($data['http'])) ? $data['http'] : [];
    $services = (isset($http_data['services']) && is_array($http_data['services'])) ? $http_data['services'] : [];

    $stmtServiceUpsert = $conn->prepare("INSERT INTO services (name, pass_host_header) VALUES (?, ?) ON DUPLICATE KEY UPDATE pass_host_header = VALUES(pass_host_header), updated_at = NOW()");
    $stmtServiceSelect = $conn->prepare("SELECT id FROM services WHERE name = ?");
    $stmtServer = $conn->prepare("INSERT INTO servers (service_id, url) VALUES (?, ?)");
    $stmtServerDelete = $conn->prepare("DELETE FROM servers WHERE service_id = ?");

    foreach ($services as $serviceName => $serviceData) {
        // If serviceData is not an array (e.g., 'service:'), skip it.
        if (!is_array($serviceData)) continue;

        // --- More robust validation ---
        if (!isset($serviceData['loadBalancer']) || !is_array($serviceData['loadBalancer'])) {
            throw new Exception("Service '{$serviceName}' tidak memiliki 'loadBalancer' atau formatnya salah.");
        }
        $loadBalancer = $serviceData['loadBalancer'];

        $passHostHeader = $loadBalancer['passHostHeader'] ?? true;
        $stmtServiceUpsert->bind_param("si", $serviceName, $passHostHeader);
        if (!$stmtServiceUpsert->execute()) throw new Exception("Gagal menyimpan service '{$serviceName}': " . $stmtServiceUpsert->error);
        
        // Get the ID of the service we just inserted or updated
        $stmtServiceSelect->bind_param("s", $serviceName);
        $stmtServiceSelect->execute();
        $serviceId = $stmtServiceSelect->get_result()->fetch_assoc()['id'];

        // Delete existing servers for this service to replace them
        $stmtServerDelete->bind_param("i", $serviceId);
        $stmtServerDelete->execute();
        
        $servers = $loadBalancer['servers'] ?? [];
        if (!is_array($servers)) {
            throw new Exception("Format YAML tidak valid: 'loadBalancer.servers' untuk service '{$serviceName}' harus berupa sebuah list/array.");
        }
        foreach ($servers as $server) {
            if (isset($server['url'])) {
                $stmtServer->bind_param("is", $serviceId, $server['url']);
                if (!$stmtServer->execute()) {
                    $stmtServer->close(); // Close statement before throwing
                    throw new Exception("Gagal menyimpan server untuk service '{$serviceName}': " . $stmtServer->error);
                }
            }
        }
    }
    $stmtServiceUpsert->close();
    $stmtServiceSelect->close();
    $stmtServer->close();
    $stmtServerDelete->close();

    // 2. Import/Update Routers (Safely)
    $routers = (isset($http_data['routers']) && is_array($http_data['routers'])) ? $http_data['routers'] : [];
    $stmtRouterUpsert = $conn->prepare("INSERT INTO routers (name, rule, entry_points, service_name, tls, cert_resolver) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE rule = VALUES(rule), entry_points = VALUES(entry_points), service_name = VALUES(service_name), tls = VALUES(tls), cert_resolver = VALUES(cert_resolver), updated_at = NOW()");
    foreach ($routers as $routerName => $routerData) {
        // If routerData is not an array, skip it.
        if (!is_array($routerData)) continue;

        $rule = $routerData['rule'] ?? '';
        $entryPointsRaw = $routerData['entryPoints'] ?? ['web'];
        if (!is_array($entryPointsRaw)) {
            throw new Exception("Format YAML tidak valid: 'entryPoints' untuk router '{$routerName}' harus berupa sebuah list/array.");
        }
        $entryPoints = implode(',', $entryPointsRaw);
        $serviceName = $routerData['service'] ?? '';
        
        // Handle TLS import
        $tls_enabled = 0;
        $cert_resolver = null;
        if (isset($routerData['tls']) && is_array($routerData['tls']) && !empty($routerData['tls']['certResolver'])) {
            $tls_enabled = 1;
            $cert_resolver = $routerData['tls']['certResolver'];
        }

        $stmtRouterUpsert->bind_param("ssssis", $routerName, $rule, $entryPoints, $serviceName, $tls_enabled, $cert_resolver);
        if (!$stmtRouterUpsert->execute()) throw new Exception("Gagal menyimpan router '{$routerName}': " . $stmtRouterUpsert->error);
    }
    $stmtRouterUpsert->close();

    // 3. Import/Update Transports (Safely)
    $transports = (isset($data['serversTransports']) && is_array($data['serversTransports'])) ? $data['serversTransports'] : [];
    $stmtTransport = $conn->prepare("INSERT INTO transports (name, insecure_skip_verify) VALUES (?, ?) ON DUPLICATE KEY UPDATE insecure_skip_verify = VALUES(insecure_skip_verify)");
    foreach ($transports as $transportName => $transportData) {
        // If transportData is not an array, skip it.
        if (!is_array($transportData)) continue;

        $insecureSkipVerify = $transportData['insecureSkipVerify'] ?? false;
        $stmtTransport->bind_param("si", $transportName, $insecureSkipVerify);
        if (!$stmtTransport->execute()) throw new Exception("Gagal menyimpan transport '{$transportName}': " . $stmtTransport->error);
    }
    $stmtTransport->close();

    // 4. Save the imported content to history as a new draft
    $stmtHistory = $conn->prepare("INSERT INTO config_history (yaml_content, generated_by, status) VALUES (?, ?, 'draft')");
    $import_user = ($_SESSION['username'] ?? 'system') . ' (Import)';
    $stmtHistory->bind_param("ss", $yamlContent, $import_user);
    if (!$stmtHistory->execute()) {
        throw new Exception("Gagal menyimpan riwayat import: " . $stmtHistory->error);
    }
    $stmtHistory->close();

    $conn->commit();
    log_activity($_SESSION['username'], 'Configuration Imported', "Imported new configuration from file '{$_FILES['yamlFile']['name']}'.");
    echo json_encode(['status' => 'success', 'message' => 'Konfigurasi berhasil diimpor sebagai draft baru. Data yang ada telah diperbarui.']);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Terjadi kesalahan saat memproses file: ' . $e->getMessage()]);
}

$conn->close();
?>