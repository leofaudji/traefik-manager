<?php
// This script is intended to be run from the command line via a cron job.
// Example cron job (runs every 5 minutes):
// */5 * * * * /usr/bin/php /path/to/your/project/scripts/collect_stats.php > /dev/null 2>&1

// Set a long execution time
set_time_limit(300); // 5 minutes

// Define PROJECT_ROOT if it's not already defined (when running from CLI)
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', dirname(__DIR__));
}

require_once PROJECT_ROOT . '/includes/bootstrap.php';
require_once PROJECT_ROOT . '/includes/DockerClient.php';

echo "Starting host stats collection at " . date('Y-m-d H:i:s') . "\n";

$conn = Database::getInstance()->getConnection();

try {
    // Get all active hosts
    $hosts_result = $conn->query("SELECT * FROM docker_hosts");
    if ($hosts_result->num_rows === 0) {
        echo "No hosts configured. Exiting.\n";
        exit;
    }

    $stmt_insert = $conn->prepare("INSERT INTO host_stats_history (host_id, cpu_usage_percent, memory_usage_bytes, memory_limit_bytes) VALUES (?, ?, ?, ?)");

    while ($host = $hosts_result->fetch_assoc()) {
        echo "Processing host: {$host['name']}...\n";
        try {
            $dockerClient = new DockerClient($host);
            $containers = $dockerClient->listContainers();

            $total_cpu_usage = 0.0;
            $total_mem_usage = 0;
            $total_mem_limit = 0;
            $running_container_count = 0;

            foreach ($containers as $container) {
                if ($container['State'] !== 'running') continue;
                $running_container_count++;
                $stats = $dockerClient->getContainerStats($container['Id']);

                $total_mem_usage += $stats['memory_stats']['usage'] ?? 0;
                $total_mem_limit += $stats['memory_stats']['limit'] ?? 0;

                $cpu_delta = ($stats['cpu_stats']['cpu_usage']['total_usage'] ?? 0) - ($stats['precpu_stats']['cpu_usage']['total_usage'] ?? 0);
                $system_cpu_delta = ($stats['cpu_stats']['system_cpu_usage'] ?? 0) - ($stats['precpu_stats']['system_cpu_usage'] ?? 0);
                $number_cpus = $stats['cpu_stats']['online_cpus'] ?? count($stats['cpu_stats']['cpu_usage']['percpu_usage'] ?? []);
                
                if ($system_cpu_delta > 0 && $cpu_delta > 0 && $number_cpus > 0) {
                    $total_cpu_usage += ($cpu_delta / $system_cpu_delta) * $number_cpus * 100.0;
                }
            }

            if ($running_container_count > 0) {
                $stmt_insert->bind_param("iddd", $host['id'], $total_cpu_usage, $total_mem_usage, $total_mem_limit);
                $stmt_insert->execute();
                echo "  -> Stats saved for host {$host['name']}. CPU: {$total_cpu_usage}%, Mem: {$total_mem_usage}\n";
            }
        } catch (Exception $e) {
            echo "  -> ERROR processing host {$host['name']}: " . $e->getMessage() . "\n";
        }
    }
    $stmt_insert->close();
} catch (Exception $e) {
    echo "A critical error occurred: " . $e->getMessage() . "\n";
}

$conn->close();
echo "Host stats collection finished at " . date('Y-m-d H:i:s') . "\n";
?>