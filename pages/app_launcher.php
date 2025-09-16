<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$conn = Database::getInstance()->getConnection();

// Get all hosts for the dropdown
$hosts_result = $conn->query("SELECT id, name, default_volume_path FROM docker_hosts ORDER BY name ASC");

// Get the global default from settings to use as a fallback
$default_git_compose_path_from_settings = get_setting('default_git_compose_path');

// Get launcher defaults for ports from ENV
$launcher_default_host_port = Config::get('LAUNCHER_DEFAULT_HOST_PORT', '80');
$launcher_default_container_port = Config::get('LAUNCHER_DEFAULT_CONTAINER_PORT', '80');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-rocket-launch-fill"></i> App Launcher</h1>
</div>

<div class="card">
    <div class="card-body">
        <p class="card-text">Deploy a new application from a Git repository or an existing Docker image. This wizard will guide you through the configuration and deploy a Docker Compose file as a stack on the selected host.</p>
        <form id="main-form" action="<?= base_url('/api/app-launcher/deploy') ?>" method="POST" data-redirect="/">
            
            <div class="accordion" id="appLauncherAccordion">
                <!-- Step 1: Host Selection -->
                <div class="accordion-item">
                    <h2 class="accordion-header" id="headingOne">
                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne" aria-expanded="true" aria-controls="collapseOne">
                            <strong>Step 1: Select Target Host</strong>
                        </button>
                    </h2>
                    <div id="collapseOne" class="accordion-collapse collapse show" aria-labelledby="headingOne" data-bs-parent="#appLauncherAccordion">
                        <div class="accordion-body">
                            <select class="form-select" id="host_id" name="host_id" required>
                                <option value="" disabled selected>-- Choose a Docker Host --</option>
                                <?php while ($host = $hosts_result->fetch_assoc()): ?>
                                    <option value="<?= $host['id'] ?>" data-volume-path="<?= htmlspecialchars($host['default_volume_path'] ?? '/opt/stacks') ?>"><?= htmlspecialchars($host['name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Deployment Source -->
                <div class="accordion-item">
                    <h2 class="accordion-header" id="headingTwo">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo" aria-expanded="false" aria-controls="collapseTwo" disabled>
                            <strong>Step 2: Define Deployment Source</strong>
                        </button>
                    </h2>
                    <div id="collapseTwo" class="accordion-collapse collapse" aria-labelledby="headingTwo" data-bs-parent="#appLauncherAccordion">
                        <div class="accordion-body">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="source_type" id="source_type_git" value="git" checked>
                                <label class="form-check-label" for="source_type_git">From Git Repository</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="source_type" id="source_type_image" value="image">
                                <label class="form-check-label" for="source_type_image">From Existing Docker Image</label>
                            </div>
                            <hr>
                            <!-- Git Repository -->
                            <div id="git-source-section">
                                <div class="mb-3">
                                    <label for="git_url" class="form-label">Repository URL (SSH or HTTPS) <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="git_url" name="git_url" placeholder="e.g., git@github.com:user/repo.git or https://github.com/user/repo.git" required>
                                        <button class="btn btn-outline-secondary" type="button" id="test-git-connection-btn">Test Connection</button>
                                    </div>
                                    <small class="form-text text-muted">For private HTTPS repos, credentials must be managed on the server where this app is running (e.g., using a credential helper).</small>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="git_branch" class="form-label">Branch</label>
                                        <input type="text" class="form-control" id="git_branch" name="git_branch" value="main">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="compose_path" class="form-label">Compose File Path (optional)</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="compose_path" name="compose_path" value="<?= htmlspecialchars($default_git_compose_path_from_settings ?? '') ?>" placeholder="<?= htmlspecialchars($default_git_compose_path_from_settings ?: 'docker-compose.yml') ?>">
                                            <button class="btn btn-outline-secondary" type="button" id="test-compose-path-btn">Test Path</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- Image Selection -->
                            <div id="image-source-section" style="display: none;">
                                <div class="mb-3">
                                    <label for="image_name_select" class="form-label">Select Image <span class="text-danger">*</span></label>
                                    <select class="form-select" id="image_name_select" name="image_name" disabled>
                                        <option>-- Select a host first --</option>
                                    </select>
                                    <small class="form-text text-muted">Select an image that already exists on the target host.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Application & Resource Configuration -->
                <div class="accordion-item">
                    <h2 class="accordion-header" id="headingThree">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree" aria-expanded="false" aria-controls="collapseThree" disabled>
                            <strong>Step 3: Configure Application & Resources</strong>
                        </button>
                    </h2>
                    <div id="collapseThree" class="accordion-collapse collapse" aria-labelledby="headingThree" data-bs-parent="#appLauncherAccordion">
                        <div class="accordion-body">
                            <div class="mb-3">
                                <label for="stack_name" class="form-label">Stack Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="stack_name" name="stack_name" required pattern="[a-zA-Z0-9][a-zA-Z0-9_.-]*">
                                <div class="invalid-feedback">Stack name must start with a letter or number and can only contain letters, numbers, underscores, periods, or hyphens.</div>
                                <small class="form-text text-muted">A unique name for this application on the host.</small>
                            </div>
                            <hr>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="deploy_replicas" class="form-label">Replicas</label>
                                    <input type="number" class="form-control" id="deploy_replicas" name="deploy_replicas" value="1" min="1">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="deploy_cpu_slider" class="form-label">CPU Limit: <strong id="cpu-limit-display">1</strong> vCPUs</label>
                                    <input type="range" class="form-range" id="deploy_cpu_slider" min="1" max="8" step="1" value="1">
                                    <input type="hidden" name="deploy_cpu" id="deploy_cpu" value="1">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="deploy_memory_slider" class="form-label">Memory Limit: <strong id="memory-limit-display">1024</strong> MB</label>
                                    <input type="range" class="form-range" id="deploy_memory_slider" min="1024" max="8192" step="1024" value="1024">
                                    <input type="hidden" name="deploy_memory" id="deploy_memory" value="1024M">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="network_name" class="form-label">Attach to Network</label>
                                <select class="form-select" id="network_name" name="network_name" disabled>
                                    <option>-- Select a host first --</option>
                                </select>
                            </div>
                            <div class="mb-3" id="container-ip-group" style="display: none;">
                                <label for="container_ip" class="form-label">Container IP Address (Optional)</label>
                                <input type="text" class="form-control" name="container_ip" id="container_ip" placeholder="e.g., 172.20.0.10">
                                <small class="form-text text-muted">Assign a static IP to the container within the selected network. Use with caution.</small>
                            </div>
                            <hr>
                            <label class="form-label"><strong>Port Mapping (Optional)</strong></label>
                            <div class="row">
                                <div class="col-md-6">
                                    <label for="host_port" class="form-label">Host Port</label>
                                    <input type="number" class="form-control" name="host_port" id="host_port" placeholder="e.g., 80" value="<?= htmlspecialchars($launcher_default_host_port) ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="container_port" class="form-label">Container Port</label>
                                    <input type="number" class="form-control" name="container_port" id="container_port" placeholder="e.g., 80" value="<?= htmlspecialchars($launcher_default_container_port) ?>">
                                </div>
                            </div>
                            <div class="invalid-feedback" id="port-validation-feedback">If you specify a Host Port, you must also specify a Container Port.</div>
                            <small class="form-text text-muted">Expose a port. `Container Port` is required for any mapping. `Host Port` is optional (a random port will be used if empty).</small>
                            <hr>
                            <div class="mb-3">
                                <label class="form-label">Volume Mapping (optional)</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="host_volume_path_display" placeholder="Host Path (auto-generated)" readonly>
                                    <span class="input-group-text">:</span>
                                    <input type="text" class="form-control" name="volume_path" id="container_volume_path" placeholder="Container Path (e.g., /app/data)">
                                </div>
                                <small class="form-text text-muted">A persistent volume will be created on the host and mapped to the specified path inside the container.</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4">
                <a href="<?= base_url('/') ?>" class="btn btn-secondary">Cancel</a>
                <button type="button" class="btn btn-info" id="view-compose-yaml-btn" disabled>View Generated YAML</button>
                <button type="submit" class="btn btn-primary" id="launch-app-btn" disabled>Launch Application</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const hostSelect = document.getElementById('host_id');
    const networkSelect = document.getElementById('network_name');
    const stackNameInput = document.getElementById('stack_name');
    const hostVolumePathDisplay = document.getElementById('host_volume_path_display');
    const cpuSlider = document.getElementById('deploy_cpu_slider');
    const cpuDisplay = document.getElementById('cpu-limit-display');
    const cpuInput = document.getElementById('deploy_cpu');
    const memorySlider = document.getElementById('deploy_memory_slider');
    const memoryDisplay = document.getElementById('memory-limit-display');
    const memoryInput = document.getElementById('deploy_memory');
    const sourceTypeGitRadio = document.getElementById('source_type_git');
    const sourceTypeImageRadio = document.getElementById('source_type_image');
    const gitSourceSection = document.getElementById('git-source-section');
    const imageSourceSection = document.getElementById('image-source-section');
    const gitUrlInput = document.getElementById('git_url');
    const imageNameSelect = document.getElementById('image_name_select');
    const composePathInput = document.getElementById('compose_path');
    const hostPortInput = document.getElementById('host_port');
    const containerPortInput = document.getElementById('container_port');
    const launchBtn = document.getElementById('launch-app-btn');
    const previewBtn = document.getElementById('view-compose-yaml-btn');
    const containerIpGroup = document.getElementById('container-ip-group');
    const containerIpInput = document.getElementById('container_ip');
    let availableNetworks = [];
    let allContainers = [];

    function ipToLong(ip) {
        if (!ip) return 0;
        // Use reduce for a concise conversion
        return ip.split('.').reduce((acc, octet) => (acc << 8) + parseInt(octet, 10), 0) >>> 0;
    }

    function longToIp(long) {
        // Use bitwise shifts to extract octets
        return [(long >>> 24), (long >>> 16) & 255, (long >>> 8) & 255, long & 255].join('.');
    }
    
    function toggleSourceSections() {
        if (sourceTypeImageRadio.checked) {
            gitSourceSection.style.display = 'none';
            imageSourceSection.style.display = 'block';
            gitUrlInput.required = false;
            imageNameSelect.required = true;
        } else { // Git is checked
            gitSourceSection.style.display = 'block';
            imageSourceSection.style.display = 'none';
            gitUrlInput.required = true;
            imageNameSelect.required = false;
        }
        checkFormValidity();
    }

    sourceTypeGitRadio.addEventListener('change', toggleSourceSections);
    sourceTypeImageRadio.addEventListener('change', toggleSourceSections);

    function updateHostVolumePath() {
        const selectedHostOption = hostSelect.options[hostSelect.selectedIndex];
        // If no host is selected, show the initial placeholder
        if (!selectedHostOption || !selectedHostOption.value) {
            hostVolumePathDisplay.value = 'Select a host to see the path';
            return;
        }
        
        const baseVolumePath = selectedHostOption.dataset.volumePath || '/opt/stacks';
        const stackName = stackNameInput.value.trim();
        const cleanBasePath = baseVolumePath.endsWith('/') ? baseVolumePath.slice(0, -1) : baseVolumePath;

        if (stackName) {
            hostVolumePathDisplay.value = `${cleanBasePath}/${stackName}`;
        } else {
            // If a host is selected but no stack name, show the base path and a placeholder for the stack name
            hostVolumePathDisplay.value = `${cleanBasePath}/<stack-name>`;
        }
    }

    function checkFormValidity() {
        const hostId = hostSelect.value;
        const stackName = stackNameInput.value.trim();
        const stackNameValid = /^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/.test(stackName);

        let sourceValid = false;
        if (sourceTypeGitRadio.checked) {
            sourceValid = gitUrlInput.value.trim() !== '';
        } else {
            sourceValid = imageNameSelect.value.trim() !== '';
        }

        const hostPort = hostPortInput.value.trim();
        const containerPort = containerPortInput.value.trim();
        const portsValid = !hostPort || !!containerPort;

        const isFormValid = hostId && stackName && stackNameValid && sourceValid && portsValid;

        launchBtn.disabled = !isFormValid;
        previewBtn.disabled = !isFormValid;

        // Provide visual feedback
        if (stackName && !stackNameValid) {
            stackNameInput.classList.add('is-invalid');
        } else {
            stackNameInput.classList.remove('is-invalid');
        }

        if (!portsValid) {
            document.getElementById('port-validation-feedback').style.display = 'block';
        } else {
            document.getElementById('port-validation-feedback').style.display = 'none';
        }
    }

    hostSelect.addEventListener('change', function() {
        const hostId = this.value;
        const globalDefaultGitComposePath = '<?= htmlspecialchars($default_git_compose_path_from_settings ?? '') ?>';
        updateHostVolumePath(); // Update volume path when host changes

        if (!hostId) {
            networkSelect.innerHTML = '<option>-- Select a host first --</option>';
            networkSelect.disabled = true;
            imageNameSelect.innerHTML = '<option>-- Select a host first --</option>';
            imageNameSelect.disabled = true;
            allContainers = []; // Reset containers
            composePathInput.value = globalDefaultGitComposePath;

            // Reset sliders to default values
            cpuSlider.max = 8;
            cpuSlider.value = 1;
            cpuSlider.dispatchEvent(new Event('input'));

            memorySlider.min = 1024;
            memorySlider.max = 8192;
            memorySlider.value = 1024;
            memorySlider.dispatchEvent(new Event('input'));

            // Disable and collapse other accordion items
            document.querySelectorAll('#appLauncherAccordion .accordion-button:not([aria-controls="collapseOne"])').forEach(btn => btn.disabled = true);
            bootstrap.Collapse.getOrCreateInstance(document.getElementById('collapseTwo')).hide();
            bootstrap.Collapse.getOrCreateInstance(document.getElementById('collapseThree')).hide();
            checkFormValidity();
            return;
        }

        // Enable and open the next step
        const step2Button = document.querySelector('button[aria-controls="collapseTwo"]');
        const step3Button = document.querySelector('button[aria-controls="collapseThree"]');
        step2Button.disabled = false;
        step3Button.disabled = false;
        bootstrap.Collapse.getOrCreateInstance(document.getElementById('collapseTwo')).show();

        // Clear previous host's data and show loading states
        allContainers = [];
        networkSelect.disabled = true;
        networkSelect.innerHTML = '<option>Loading networks...</option>';
        imageNameSelect.disabled = true;
        imageNameSelect.innerHTML = '<option>Loading images...</option>';

        // Fetch all containers for IP suggestion
        fetch(`${basePath}/api/hosts/${hostId}/containers?raw=true`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'success') {
                    allContainers = result.data;
                } else {
                    allContainers = [];
                    console.warn('Could not fetch containers for IP suggestion.');
                }
            });

        fetch(`${basePath}/api/hosts/${hostId}/networks`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'success' && result.data) {
                    availableNetworks = result.data; // Store full network objects
                    let optionsHtml = '<option value="">-- Do not attach to a specific network --</option>';
                    availableNetworks.forEach(net => {
                        optionsHtml += `<option value="${net.Name}">${net.Name}</option>`;
                    });
                    networkSelect.innerHTML = optionsHtml;
                    networkSelect.disabled = false;
                } else {
                    availableNetworks = [];
                    throw new Error(result.message || 'Failed to load networks.');
                }
            })
            .catch(error => {
                console.error("Failed to fetch host networks:", error);
                networkSelect.innerHTML = '<option>-- Error loading networks --</option>';
                showToast("Could not load networks for the selected host.", false);
            });

        // Fetch host images
        fetch(`${basePath}/api/hosts/${hostId}/images`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'success' && result.data) {
                    let optionsHtml = '<option value="" disabled selected>-- Select an image --</option>';
                    result.data.forEach(img => {
                        // Assuming result.data is an array of strings (image tags)
                        optionsHtml += `<option value="${img}">${img}</option>`;
                    });
                    imageNameSelect.innerHTML = optionsHtml;
                    imageNameSelect.disabled = false;
                } else {
                    throw new Error(result.message || 'Failed to load images.');
                }
            })
            .catch(error => {
                console.error("Failed to fetch host images:", error);
                imageNameSelect.innerHTML = '<option>-- Error loading images --</option>';
                showToast("Could not load images for the selected host.", false);
            });

        // Fetch host stats to update resource sliders
        fetch(`${basePath}/api/hosts/${hostId}/stats`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'success' && result.data && result.data.cpus && result.data.memory) {
                    const hostCpus = result.data.cpus;
                    const hostMemoryMb = Math.floor(result.data.memory / (1024 * 1024));

                    // Update CPU slider
                    cpuSlider.max = hostCpus;
                    if (parseInt(cpuSlider.value) > hostCpus) {
                        cpuSlider.value = hostCpus;
                    }
                    cpuSlider.dispatchEvent(new Event('input'));

                    // Update Memory slider
                    const maxMemoryStepped = Math.floor(hostMemoryMb / 1024) * 1024;
                    memorySlider.max = maxMemoryStepped > 0 ? maxMemoryStepped : 1024;
                    if (parseInt(memorySlider.value) > maxMemoryStepped) {
                        memorySlider.value = maxMemoryStepped;
                    }
                    memorySlider.dispatchEvent(new Event('input'));

                } else {
                    throw new Error(result.message || 'Host stats not available.');
                }
            })
            .catch(error => {
                console.warn("Could not fetch host stats, using default slider limits.", error);
                showToast("Could not load host resource limits, using defaults.", false);
            });
    });

    stackNameInput.addEventListener('input', function() {
        // Force stack name to lowercase to match docker-compose project name behavior
        this.value = this.value.toLowerCase();
    });

    stackNameInput.addEventListener('input', updateHostVolumePath);

    networkSelect.addEventListener('change', function() {
        const selectedNetworkName = this.value;
        containerIpInput.value = ''; // Reset IP field on any change

        // Show/hide the static IP input based on network selection
        if (selectedNetworkName) {
            containerIpGroup.style.display = 'block';
        } else {
            containerIpGroup.style.display = 'none';
            return; // Exit if no network is selected
        }

        // --- IP Suggestion Logic ---
        const selectedNetwork = availableNetworks.find(net => net.Name === selectedNetworkName);
        if (selectedNetwork && selectedNetwork.IPAM && selectedNetwork.IPAM.Config && selectedNetwork.IPAM.Config[0] && selectedNetwork.IPAM.Config[0].Subnet) {
            const subnetCIDR = selectedNetwork.IPAM.Config[0].Subnet;
            const gateway = selectedNetwork.IPAM.Config[0].Gateway;

            // Collect all used IPs in this network
            const usedIps = [];
            if (gateway) {
                usedIps.push(gateway);
            }
            allContainers.forEach(container => {
                if (container.NetworkSettings && container.NetworkSettings.Networks && container.NetworkSettings.Networks[selectedNetworkName]) {
                    const ip = container.NetworkSettings.Networks[selectedNetworkName].IPAddress;
                    if (ip) {
                        usedIps.push(ip);
                    }
                }
            });

            if (usedIps.length > 0) {
                const usedIpsLong = usedIps.map(ipToLong);
                const maxIpLong = Math.max(...usedIpsLong);
                const nextIpLong = maxIpLong + 1;

                // Check if the next IP is within the subnet range
                const [subnetIp, mask] = subnetCIDR.split('/');
                const subnetLong = ipToLong(subnetIp);
                const maskBits = parseInt(mask, 10);
                const broadcastLong = (subnetLong & (-1 << (32 - maskBits))) | ~(-1 << (32 - maskBits));
                
                if (nextIpLong < broadcastLong) { // Ensure not broadcast address
                    const nextIp = longToIp(nextIpLong);
                    containerIpInput.value = nextIp;
                    showToast(`Suggested next available IP: ${nextIp}`, true);
                }
            }
        }
        // --- End of IP Suggestion Logic ---
    });

    // Add listeners to all relevant inputs for live validation
    [stackNameInput, gitUrlInput, imageNameSelect, hostPortInput, containerPortInput].forEach(input => {
        input.addEventListener('input', checkFormValidity);
    });

    updateHostVolumePath();
    checkFormValidity(); // Initial check

    // --- Resource Sliders ---
    if (cpuSlider && cpuDisplay && cpuInput) {
        cpuSlider.addEventListener('input', function() {
            const value = this.value;
            cpuDisplay.textContent = value; // It's an integer now
            cpuInput.value = value;
        });
    }
    if (memorySlider && memoryDisplay && memoryInput) {
        memorySlider.addEventListener('input', function() {
            memoryDisplay.textContent = this.value;
            memoryInput.value = `${this.value}M`;
        });
    }

    // --- YAML Preview ---
    const viewYamlBtn = document.getElementById('view-compose-yaml-btn');
    const previewModalEl = document.getElementById('previewConfigModal');
    const previewModal = new bootstrap.Modal(previewModalEl);
    const previewModalLabel = document.getElementById('previewConfigModalLabel');
    const previewCodeContainer = document.getElementById('preview-yaml-content-container');
    const deployFromPreviewBtn = document.getElementById('deploy-from-preview-btn');

    function buildComposeObject() {
        const stackName = stackNameInput.value.trim();
        if (!stackName) return null;

        const compose = { version: '3.8', services: {}, networks: {} };
        const service = {};

        // --- Source ---
        if (sourceTypeImageRadio.checked) {
            const imageName = imageNameSelect.value;
            if (!imageName) return null;
            service.image = imageName;
        } else {
            // Preview for Git source is not supported as it requires fetching the file.
            return 'git'; 
        }

        // --- Resources ---
        const replicas = document.getElementById('deploy_replicas').value;
        const cpu = cpuInput.value;
        const memory = memoryInput.value;
        if (replicas > 1 || cpu > 1 || parseInt(memory) > 1024) {
            service.deploy = {};
            if (replicas > 1) service.deploy.replicas = parseInt(replicas);
            if (cpu > 1 || parseInt(memory) > 1024) {
                service.deploy.resources = { limits: {} };
                if (cpu > 1) service.deploy.resources.limits.cpus = cpu;
                if (parseInt(memory) > 1024) service.deploy.resources.limits.memory = memory;
            }
        }

        // --- Network ---
        const networkName = networkSelect.value;
        const containerIp = containerIpInput.value.trim();
        if (networkName) {
            const networkKey = networkName.replace(/[^\w.-]+/g, '_');

            if (containerIp) {
                service.networks = {
                    [networkKey]: {
                        'ipv4_address': containerIp
                    }
                };
            } else {
                service.networks = [networkKey];
            }
            compose.networks[networkKey] = { name: networkName, external: true };
        }

        // --- Volume ---
        const hostPath = hostVolumePathDisplay.value;
        const containerPath = document.getElementById('container_volume_path').value.trim();
        if (containerPath && !hostPath.includes('<stack-name>')) {
            const volumeName = stackName.replace(/[^\w.-]+/g, '') + '_data';
            if (!service.volumes) service.volumes = [];
            service.volumes.push(`${volumeName}:${containerPath}`);

            if (!compose.volumes) {
                compose.volumes = {};
            }
            compose.volumes[volumeName] = {
                driver: 'local',
                driver_opts: {
                    type: 'none',
                    o: 'bind',
                    device: hostPath
                }
            };
        }

        // --- Ports ---
        const hostPort = document.getElementById('host_port').value.trim();
        const containerPort = document.getElementById('container_port').value.trim();
        if (containerPort) {
            let portMapping = '';
            if (hostPort) {
                portMapping = `${hostPort}:${containerPort}`;
            } else {
                portMapping = containerPort;
            }
            service.ports = [portMapping];
        }

        compose.services[stackName] = service;
        if (Object.keys(compose.networks).length === 0) {
            delete compose.networks;
        }

        return compose;
    }

    viewYamlBtn.addEventListener('click', function() {
        const originalBtnContent = this.innerHTML;
        const mainForm = document.getElementById('main-form');
        const formData = new FormData(mainForm);

        // Basic validation
        if (!formData.get('host_id') || !formData.get('stack_name')) {
            showToast('Please select a Host and provide a Stack Name to generate a preview.', false);
            return;
        }

        this.disabled = true;
        this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Generating...`;

        let previewPromise;

        if (sourceTypeImageRadio.checked) {
            // Client-side generation for 'image' source
            const composeObject = buildComposeObject();
            if (!composeObject) {
                showToast('Please select an Image to generate a preview.', false);
                this.disabled = false;
                this.innerHTML = originalBtnContent;
                return;
            }
            const yamlString = jsyaml.dump(composeObject, { indent: 2 });
            previewPromise = Promise.resolve(yamlString);

        } else { // Git source
            if (!formData.get('git_url')) {
                showToast('Please enter a Git URL to generate a preview.', false);
                this.disabled = false;
                this.innerHTML = originalBtnContent;
                return;
            }
            // Server-side generation for 'git' source
            previewPromise = fetch(`${basePath}/api/app-launcher/preview`, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json().then(data => {
                if (!response.ok) throw new Error(data.message || 'Failed to generate preview from server.');
                return data.yaml;
            }));
        }

        previewPromise
            .then(yamlString => {
                previewModalLabel.textContent = 'Preview: Generated docker-compose.yml';
                previewCodeContainer.textContent = yamlString;
                Prism.highlightElement(previewCodeContainer);
                deployFromPreviewBtn.style.display = 'none';
                previewModal.show();
            })
            .catch(error => {
                showToast(error.message, false);
            })
            .finally(() => {
                this.disabled = false;
                this.innerHTML = originalBtnContent;
            });
    });

    // Reset the deploy button visibility when the modal is hidden
    previewModalEl.addEventListener('hidden.bs.modal', function() {
        deployFromPreviewBtn.style.display = 'block';
    });

    // --- Test Compose Path ---
    const testComposePathBtn = document.getElementById('test-compose-path-btn');
    if (testComposePathBtn) {
        testComposePathBtn.addEventListener('click', function() {
            const gitUrl = gitUrlInput.value.trim();
            const gitBranch = document.getElementById('git_branch').value.trim();
            const composePath = composePathInput.value.trim();

            if (!gitUrl || !composePath) {
                showToast('Please provide a Git URL and a Compose File Path to test.', false);
                return;
            }

            const originalBtnContent = this.innerHTML;
            this.disabled = true;
            this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Testing...`;

            const formData = new FormData();
            formData.append('git_url', gitUrl);
            formData.append('git_branch', gitBranch);
            formData.append('compose_path', composePath);

            fetch(`${basePath}/api/git/test-compose-path`, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json().then(data => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                showToast(data.message, ok);
            })
            .catch(error => showToast('An unknown error occurred while testing the path.', false))
            .finally(() => {
                this.disabled = false;
                this.innerHTML = originalBtnContent;
            });
        });
    }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>