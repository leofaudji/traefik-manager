<?php

class DockerClient
{
    private string $apiUrl;
    private bool $tlsEnabled;
    private ?string $caCertPath;
    private ?string $clientCertPath;
    private ?string $clientKeyPath;

    /**
     * @param array $host An associative array of host details from the database.
     */
    public function __construct(array $host)
    {
        if (empty($host['docker_api_url'])) {
            throw new InvalidArgumentException("Docker API URL is required.");
        }

        $this->apiUrl = ($host['tls_enabled'] ? 'https://' : 'http://') . str_replace('tcp://', '', $host['docker_api_url']);
        $this->tlsEnabled = (bool)$host['tls_enabled'];

        if ($this->tlsEnabled) {
            if (empty($host['ca_cert_path']) || empty($host['client_cert_path']) || empty($host['client_key_path'])) {
                throw new InvalidArgumentException("All TLS certificate paths are required when TLS is enabled.");
            }
            if (!file_exists($host['ca_cert_path']) || !file_exists($host['client_cert_path']) || !file_exists($host['client_key_path'])) {
                throw new RuntimeException("One or more TLS certificate files not found on the application server.");
            }
            $this->caCertPath = $host['ca_cert_path'];
            $this->clientCertPath = $host['client_cert_path'];
            $this->clientKeyPath = $host['client_key_path'];
        }
    }

    /**
     * Lists all containers.
     * @return array The list of containers.
     * @throws Exception
     */
    public function listContainers(): array
    {
        return $this->request('/containers/json?all=1');
    }

    /**
     * Starts a container.
     * @param string $containerId The ID of the container.
     * @return bool True on success.
     * @throws Exception
     */
    public function startContainer(string $containerId): bool
    {
        return $this->request("/containers/{$containerId}/start", 'POST');
    }

    /**
     * Stops a container.
     * @param string $containerId The ID of the container.
     * @return bool True on success.
     * @throws Exception
     */
    public function stopContainer(string $containerId): bool
    {
        return $this->request("/containers/{$containerId}/stop", 'POST');
    }

    /**
     * Restarts a container.
     * @param string $containerId The ID of the container.
     * @return bool True on success.
     * @throws Exception
     */
    public function restartContainer(string $containerId): bool
    {
        return $this->request("/containers/{$containerId}/restart", 'POST');
    }

    /**
     * Lists all networks.
     * @return array The list of networks.
     * @throws Exception
     */
    public function listNetworks(): array
    {
        return $this->request('/networks');
    }

    /**
     * Creates a new network.
     * @param array $config The network configuration.
     * @return array The response from the API.
     * @throws Exception
     */
    public function createNetwork(array $config): array
    {
        return $this->request('/networks/create', 'POST', $config);
    }

    /**
     * Removes a network.
     * @param string $networkIdOrName The ID or name of the network.
     * @return bool True on success.
     * @throws Exception
     */
    public function removeNetwork(string $networkIdOrName): bool
    {
        return $this->request("/networks/{$networkIdOrName}", 'DELETE');
    }

    /**
     * Lists all stacks (Swarm).
     * @return array The list of stacks.
     * @throws Exception
     */
    public function listStacks(): array
    {
        return $this->request('/stacks');
    }

    /**
     * Lists all services (Swarm).
     * @return array The list of services.
     * @throws Exception
     */
    public function listServices(): array
    {
        return $this->request('/services');
    }

    /**
     * Creates a new stack (Swarm).
     * @param string $name The name of the stack.
     * @param string $composeContent The content of the docker-compose file.
     * @return array The response from the API.
     * @throws Exception
     */
    public function createStack(string $name, string $composeContent): array
    {
        $data = [
            'Name' => $name,
            'StackFileContent' => $composeContent
        ];
        return $this->request('/stacks', 'POST', $data);
    }

    /**
     * Removes a stack (Swarm).
     * @param string $stackId The ID of the stack.
     * @return bool True on success.
     * @throws Exception
     */
    public function removeStack(string $stackId): bool
    {
        return $this->request("/stacks/{$stackId}", 'DELETE');
    }

    /**
     * Updates a stack (Swarm).
     * @param string $stackId The ID of the stack.
     * @param string $composeContent The new content of the docker-compose file.
     * @param int $version The current version of the stack object.
     * @return bool True on success.
     * @throws Exception
     */
    public function updateStack(string $stackId, string $composeContent, int $version): bool
    {
        // The Docker API for stack update expects the raw compose content directly in the body.
        return $this->request("/stacks/{$stackId}/update?version={$version}", 'POST', $composeContent, 'application/x-yaml');
    }

    /**
     * Inspects a stack to get its version for updates.
     * @param string $stackId The ID of the stack.
     * @return array The stack details.
     * @throws Exception
     */
    public function inspectStack(string $stackId): array
    {
        return $this->request("/stacks/{$stackId}");
    }

    /**
     * Gets system-wide information from the Docker daemon.
     * @return array The Docker system info.
     * @throws Exception
     */
    public function getInfo(): array
    {
        return $this->request('/info');
    }

    /**
     * Gets a one-time snapshot of a container's stats.
     * @param string $containerId The ID of the container.
     * @return array The container stats.
     * @throws Exception
     */
    public function getContainerStats(string $containerId): array
    {
        return $this->request("/containers/{$containerId}/stats?stream=false");
    }

    /**
     * Gets logs from a container.
     * @param string $containerId The ID of the container.
     * @param int $tail The number of lines to show from the end of the logs.
     * @return string The container logs.
     * @throws Exception
     */
    public function getContainerLogs(string $containerId, int $tail = 200): string
    {
        $path = "/containers/{$containerId}/logs?stdout=true&stderr=true&timestamps=true&tail={$tail}";
        
        // This is a raw request, not expecting JSON
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        if ($this->tlsEnabled) {
            curl_setopt($ch, CURLOPT_SSLCERT, $this->clientCertPath);
            curl_setopt($ch, CURLOPT_SSLKEY, $this->clientKeyPath);
            curl_setopt($ch, CURLOPT_CAINFO, $this->caCertPath);
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) throw new RuntimeException("cURL Error: " . $curl_error);
        if ($http_code >= 400) throw new RuntimeException("Docker API Error (HTTP {$http_code}): " . $response);

        // Clean non-printable characters from the raw log stream header, but keep line breaks.
        return preg_replace('/[^\x20-\x7E\n\r\t]/', '', $response);
    }

    /**
     * Makes a request to the Docker API.
     * @param string $path The API endpoint path.
     * @param string $method The HTTP method (GET, POST, etc.).
     * @param mixed|null $data The data to send with POST requests (can be an array for JSON or a string for raw content).
     * @param string $contentType The Content-Type header for the request.
     * @return mixed The response from the API.
     * @throws Exception
     */
    private function request(string $path, string $method = 'GET', $data = null, string $contentType = 'application/json')
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15); // 15 second timeout for actions

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data !== null) {
                $body = ($contentType === 'application/json') ? json_encode($data) : $data;
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: ' . $contentType]);
            } else {
                // Docker API often uses empty POST bodies for actions
                curl_setopt($ch, CURLOPT_POSTFIELDS, '');
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        if ($this->tlsEnabled) {
            curl_setopt($ch, CURLOPT_SSLCERT, $this->clientCertPath);
            curl_setopt($ch, CURLOPT_SSLKEY, $this->clientKeyPath);
            curl_setopt($ch, CURLOPT_CAINFO, $this->caCertPath);
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            throw new RuntimeException("cURL Error: " . $curl_error);
        }

        // Actions like start/stop/restart return 204 No Content on success
        if ($http_code === 204) {
            return true;
        }

        if ($http_code >= 400) {
            $errorBody = json_decode($response, true);
            $errorMessage = $errorBody['message'] ?? $response;
            throw new RuntimeException("Docker API Error (HTTP {$http_code}): " . $errorMessage);
        }

        return json_decode($response, true);
    }
}