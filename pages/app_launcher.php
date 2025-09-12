<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$conn = Database::getInstance()->getConnection();

// Get all hosts for the dropdown
$hosts_result = $conn->query("SELECT id, name, default_volume_path, default_git_compose_path FROM docker_hosts ORDER BY name ASC");

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-rocket-launch-fill"></i> App Launcher</h1>
</div>

<div class="card">
    <div class="card-body">
        <p class="card-text">Deploy a new application from a Git repository by defining its configuration below. This will generate and deploy a Docker Compose file as a stack on the selected host.</p>
        <hr>
        <form id="main-form" action="<?= base_url('/api/app-launcher/deploy') ?>" method="POST" data-redirect="/">
            
            <!-- Host Selection -->
            <div class="mb-4">
                <label for="host_id" class="form-label"><strong>1. Select Target Host</strong> <span class="text-danger">*</span></label>
                <select class="form-select" id="host_id" name="host_id" required>
                    <option value="" disabled selected>-- Choose a Docker Host --</option>
                    <?php while ($host = $hosts_result->fetch_assoc()): ?>
                        <option value="<?= $host['id'] ?>" data-volume-path="<?= htmlspecialchars($host['default_volume_path'] ?? '/opt/stacks') ?>" data-git-compose-path="<?= htmlspecialchars($host['default_git_compose_path'] ?? '') ?>"><?= htmlspecialchars($host['name']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>

            <!-- Deployment Source -->
            <div class="mb-4">
                <label class="form-label"><strong>2. Deployment Source</strong> <span class="text-danger">*</span></label>
                <div class="p-3 border rounded">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="source_type" id="source_type_git" value="git" checked>
                        <label class="form-check-label" for="source_type_git">From Git Repository</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="source_type" id="source_type_image" value="image">
                        <label class="form-check-label" for="source_type_image">From Existing Docker Image</label>
                    </div>
                </div>
            </div>

            <!-- Git Repository -->
            <div class="mb-4" id="git-source-section">
                <label class="form-label"><strong>3. Git Repository Details</strong></label>
                <div class="p-3 border rounded">
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
                                <input type="text" class="form-control" id="compose_path" name="compose_path" placeholder="e.g., deploy/docker-compose.yml">
                                <button class="btn btn-outline-secondary" type="button" id="test-compose-path-btn">Test Path</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Image Selection -->
            <div class="mb-4" id="image-source-section" style="display: none;">
                <label class="form-label"><strong>3. Image Selection</strong></label>
                <div class="p-3 border rounded">
                    <div class="mb-3">
                        <label for="image_name_select" class="form-label">Select Image <span class="text-danger">*</span></label>
                        <select class="form-select" id="image_name_select" name="image_name" disabled>
                            <option>-- Select a host first --</option>
                        </select>
                        <small class="form-text text-muted">Select an image that already exists on the target host.</small>
                    </div>
                </div>
            </div>

            <!-- Application Configuration -->
            <div class="mb-4">
                <label class="form-label"><strong>4. Application Configuration</strong></label>
                <div class="p-3 border rounded">
                    <div class="mb-3">
                        <label for="stack_name" class="form-label">Stack Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="stack_name" name="stack_name" required>
                        <small class="form-text text-muted">A unique name for this application on the host.</small>
                    </div>
                </div>
            </div>

            <!-- Resource & Network Configuration -->
            <div class="mb-4">
                <label class="form-label"><strong>5. Resource & Network Configuration</strong></label>
                <div class="p-3 border rounded">
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
                    <div class="mb-3">
                        <label class="form-label">Volume Mapping (optional)</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="host_volume_path_display" placeholder="Host Path (auto-generated)" readonly>
                            <span class="input-group-text">:</span>
                            <input type="text" class="form-control" name="volume_path" id="container_volume_path" placeholder="Container Path (e.g., /data)">
                        </div>
                        <small class="form-text text-muted">A persistent volume will be created on the host and mapped to the specified path inside the container.</small>
                    </div>
                    <hr>
                    <label class="form-label"><strong>Port Mapping (Optional)</strong></label>
                    <div class="row">
                        <div class="col-md-4">
                            <label for="host_ip" class="form-label">Host IP</label>
                            <input type="text" class="form-control" name="host_ip" id="host_ip" placeholder="e.g., 192.168.1.50">
                        </div>
                        <div class="col-md-4">
                            <label for="host_port" class="form-label">Host Port</label>
                            <input type="number" class="form-control" name="host_port" id="host_port" placeholder="e.g., 8080">
                        </div>
                        <div class="col-md-4">
                            <label for="container_port" class="form-label">Container Port</label>
                            <input type="number" class="form-control" name="container_port" id="container_port" placeholder="e.g., 80">
                        </div>
                    </div>
                    <small class="form-text text-muted">Expose the application on `HOST_IP:HOST_PORT` which maps to `CONTAINER_PORT` inside the container. Leave blank if not needed.</small>
                </div>
            </div>

            <div class="mt-4">
                <a href="<?= base_url('/') ?>" class="btn btn-secondary">Cancel</a>
                <button type="button" class="btn btn-info" id="view-compose-yaml-btn">View Generated YAML</button>
                <button type="submit" class="btn btn-primary">Launch Application</button>
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
            hostVolumePathDisplay.value = `${cleanBasePath}/${stackName}/data`;
        } else {
            // If a host is selected but no stack name, show the base path and a placeholder for the stack name
            hostVolumePathDisplay.value = `${cleanBasePath}/<stack-name>/data`;
        }
    }

    hostSelect.addEventListener('change', function() {
        const hostId = this.value;
        const selectedOption = this.options[this.selectedIndex];
        updateHostVolumePath(); // Update volume path when host changes

        if (!hostId) {
            networkSelect.innerHTML = '<option>-- Select a host first --</option>';
            networkSelect.disabled = true;
            imageNameSelect.innerHTML = '<option>-- Select a host first --</option>';
            imageNameSelect.disabled = true;
            composePathInput.value = '';

            // Reset sliders to default values
            cpuSlider.max = 8;
            cpuSlider.value = 1;
            cpuSlider.dispatchEvent(new Event('input'));

            memorySlider.min = 1024;
            memorySlider.max = 8192;
            memorySlider.value = 1024;
            memorySlider.dispatchEvent(new Event('input'));
            return;
        }

        // Set the default compose path from the selected host
        if (selectedOption) {
            const gitComposePath = selectedOption.dataset.gitComposePath;
            composePathInput.value = gitComposePath || '';
        }

        networkSelect.disabled = true;
        networkSelect.innerHTML = '<option>Loading networks...</option>';

        fetch(`${basePath}/api/hosts/${hostId}/networks`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'success' && result.data) {
                    let optionsHtml = '<option value="">-- Do not attach to a specific network --</option>';
                    result.data.forEach(net => {
                        optionsHtml += `<option value="${net.Name}">${net.Name}</option>`;
                    });
                    networkSelect.innerHTML = optionsHtml;
                    networkSelect.disabled = false;
                } else {
                    throw new Error(result.message || 'Failed to load networks.');
                }
            })
            .catch(error => {
                console.error("Failed to fetch host networks:", error);
                networkSelect.innerHTML = '<option>-- Error loading networks --</option>';
                showToast("Could not load networks for the selected host.", false);
            });

        // Fetch host images
        imageNameSelect.disabled = true;
        imageNameSelect.innerHTML = '<option>Loading images...</option>';
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

    stackNameInput.addEventListener('input', updateHostVolumePath);

    // Initial call to set placeholder text
    updateHostVolumePath();

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
        if (networkName) {
            service.networks = [networkName];
            compose.networks[networkName] = { external: true };
        }

        // --- Volume ---
        const hostPath = hostVolumePathDisplay.value;
        const containerPath = document.getElementById('container_volume_path').value.trim();
        if (hostPath && containerPath && !hostPath.includes('<stack-name>')) {
            service.volumes = [`${hostPath}:${containerPath}`];
        }

        // --- Ports ---
        const hostIp = document.getElementById('host_ip').value.trim();
        const hostPort = document.getElementById('host_port').value.trim();
        const containerPort = document.getElementById('container_port').value.trim();
        if (hostPort && containerPort) {
            let portMapping = '';
            if (hostIp) portMapping += `${hostIp}:`;
            portMapping += `${hostPort}:${containerPort}`;
            service.ports = [portMapping];
        }

        compose.services[stackName] = service;
        if (Object.keys(compose.networks).length === 0) {
            delete compose.networks;
        }

        return compose;
    }

    viewYamlBtn.addEventListener('click', function() {
        const composeObject = buildComposeObject();

        if (composeObject === 'git') {
            showToast('YAML preview is only available for "From Existing Docker Image" source type.', false);
            return;
        }

        if (!composeObject) {
            showToast('Please fill in Stack Name and select an Image to generate a preview.', false);
            return;
        }

        const yamlString = jsyaml.dump(composeObject, { indent: 2 });

        previewModalLabel.textContent = 'Preview: Generated docker-compose.yml';
        previewCodeContainer.textContent = yamlString;
        Prism.highlightElement(previewCodeContainer);
        deployFromPreviewBtn.style.display = 'none'; // This modal is for viewing only in this context
        previewModal.show();
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