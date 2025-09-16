<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$conn = Database::getInstance()->getConnection();

// --- Get IDs from URL ---
$host_id = $_GET['id'] ?? null;
$stack_db_id = $_GET['stack_db_id'] ?? null;

if (!$host_id || !$stack_db_id) {
    header("Location: " . base_url('/hosts?status=error&message=Invalid update URL.'));
    exit;
}

// --- Fetch Host Details ---
$stmt_host = $conn->prepare("SELECT id, name, default_volume_path FROM docker_hosts WHERE id = ?");
$stmt_host->bind_param("i", $host_id);
$stmt_host->execute();
$host_result = $stmt_host->get_result();
if (!($host = $host_result->fetch_assoc())) {
    header("Location: " . base_url('/hosts?status=error&message=Host not found.'));
    exit;
}
$stmt_host->close();

// --- Fetch Stack Deployment Details ---
$stmt_stack = $conn->prepare("SELECT * FROM application_stacks WHERE id = ? AND host_id = ?");
$stmt_stack->bind_param("ii", $stack_db_id, $host_id);
$stmt_stack->execute();
$stack_result = $stmt_stack->get_result();
if (!($stack_data = $stack_result->fetch_assoc())) {
    header("Location: " . base_url('/hosts/' . $host_id . '/stacks?status=error&message=Stack not found in database.'));
    exit;
}
$stmt_stack->close();

$details = json_decode($stack_data['deployment_details'], true);
if (json_last_error() !== JSON_ERROR_NONE) {
    // Fallback to empty array if JSON is invalid
    $details = [];
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-arrow-repeat"></i> Update Application: <?= htmlspecialchars($details['stack_name'] ?? 'N/A') ?></h1>
</div>

<div class="card">
    <div class="card-body">
        <p class="card-text">Update the configuration for an existing application. The application will be redeployed with the new settings.</p>
        <form id="main-form" action="<?= base_url('/api/app-launcher/deploy') ?>" method="POST" data-redirect="<?= base_url('/hosts/' . $host_id . '/stacks') ?>">
            <input type="hidden" name="update_stack" value="true">
            
            <div class="accordion" id="appLauncherAccordion">
                <!-- Step 1: Host Selection -->
                <div class="accordion-item">
                    <h2 class="accordion-header" id="headingOne">
                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne" aria-expanded="true" aria-controls="collapseOne">
                            <strong>Step 1: Target Host (Locked)</strong>
                        </button>
                    </h2>
                    <div id="collapseOne" class="accordion-collapse collapse show" aria-labelledby="headingOne" data-bs-parent="#appLauncherAccordion">
                        <div class="accordion-body">
                            <input type="text" class="form-control" value="<?= htmlspecialchars($host['name']) ?>" readonly>
                            <input type="hidden" id="host_id" name="host_id" value="<?= htmlspecialchars($host_id) ?>">
                        </div>
                    </div>
                </div>

                <!-- Step 2: Deployment Source -->
                <div class="accordion-item">
                    <h2 class="accordion-header" id="headingTwo">
                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo" aria-expanded="true" aria-controls="collapseTwo">
                            <strong>Step 2: Define Deployment Source</strong>
                        </button>
                    </h2>
                    <div id="collapseTwo" class="accordion-collapse collapse show" aria-labelledby="headingTwo" data-bs-parent="#appLauncherAccordion">
                        <div class="accordion-body">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="source_type" id="source_type_git" value="git" <?= ($details['source_type'] ?? 'git') === 'git' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="source_type_git">From Git Repository</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="source_type" id="source_type_image" value="image" <?= ($details['source_type'] ?? '') === 'image' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="source_type_image">From Existing Docker Image</label>
                            </div>
                            <hr>
                            <!-- Git Repository -->
                            <div id="git-source-section">
                                <div class="mb-3">
                                    <label for="git_url" class="form-label">Repository URL (SSH or HTTPS) <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="git_url" name="git_url" placeholder="e.g., git@github.com:user/repo.git" value="<?= htmlspecialchars($details['git_url'] ?? '') ?>" required>
                                        <button class="btn btn-outline-secondary" type="button" id="test-git-connection-btn">Test Connection</button>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="git_branch" class="form-label">Branch</label>
                                        <input type="text" class="form-control" id="git_branch" name="git_branch" value="<?= htmlspecialchars($details['git_branch'] ?? 'main') ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="compose_path" class="form-label">Compose File Path (optional)</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="compose_path" name="compose_path" placeholder="e.g., deploy/docker-compose.yml" value="<?= htmlspecialchars($details['compose_path'] ?? '') ?>">
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
                                        <option>Loading images...</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Application & Resource Configuration -->
                <div class="accordion-item">
                    <h2 class="accordion-header" id="headingThree">
                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree" aria-expanded="true" aria-controls="collapseThree">
                            <strong>Step 3: Configure Application & Resources</strong>
                        </button>
                    </h2>
                    <div id="collapseThree" class="accordion-collapse collapse show" aria-labelledby="headingThree" data-bs-parent="#appLauncherAccordion">
                        <div class="accordion-body">
                            <div class="mb-3">
                                <label for="stack_name" class="form-label">Stack Name</label>
                                <input type="text" class="form-control" id="stack_name" name="stack_name" value="<?= htmlspecialchars($details['stack_name'] ?? '') ?>" readonly>
                            </div>
                            <hr>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="deploy_replicas" class="form-label">Replicas</label>
                                    <input type="number" class="form-control" id="deploy_replicas" name="deploy_replicas" value="<?= htmlspecialchars($details['deploy_replicas'] ?? '1') ?>" min="1">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <?php
                                        $cpu_val = $details['deploy_cpu'] ?? '1';
                                    ?>
                                    <label for="deploy_cpu_slider" class="form-label">CPU Limit: <strong id="cpu-limit-display"><?= $cpu_val ?></strong> vCPUs</label>
                                    <input type="range" class="form-range" id="deploy_cpu_slider" min="1" max="8" step="1" value="<?= $cpu_val ?>">
                                    <input type="hidden" name="deploy_cpu" id="deploy_cpu" value="<?= $cpu_val ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <?php
                                        $mem_val_str = $details['deploy_memory'] ?? '1024M';
                                        $mem_val_int = (int)filter_var($mem_val_str, FILTER_SANITIZE_NUMBER_INT);
                                    ?>
                                    <label for="deploy_memory_slider" class="form-label">Memory Limit: <strong id="memory-limit-display"><?= $mem_val_int ?></strong> MB</label>
                                    <input type="range" class="form-range" id="deploy_memory_slider" min="1024" max="8192" step="1024" value="<?= $mem_val_int ?>">
                                    <input type="hidden" name="deploy_memory" id="deploy_memory" value="<?= $mem_val_str ?>">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="network_name" class="form-label">Attach to Network</label>
                                <select class="form-select" id="network_name" name="network_name" disabled>
                                    <option>Loading networks...</option>
                                </select>
                            </div>
                            <div class="mb-3" id="container-ip-group" style="display: <?= !empty($details['network_name']) ? 'block' : 'none' ?>;">
                                <label for="container_ip" class="form-label">Container IP Address (Optional)</label>
                                <input type="text" class="form-control" name="container_ip" id="container_ip" placeholder="e.g., 172.20.0.10" value="<?= htmlspecialchars($details['container_ip'] ?? '') ?>">
                                <small class="form-text text-muted">Assign a static IP to the container within the selected network. Use with caution.</small>
                            </div>
                            <hr>
                            <label class="form-label"><strong>Port Mapping (Optional)</strong></label>
                            <div class="row">
                                <div class="col-md-6">
                                    <label for="host_port" class="form-label">Host Port</label>
                                    <input type="number" class="form-control" name="host_port" id="host_port" placeholder="e.g., 8080" value="<?= htmlspecialchars($details['host_port'] ?? '') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="container_port" class="form-label">Container Port</label>
                                    <input type="number" class="form-control" name="container_port" id="container_port" placeholder="e.g., 80" value="<?= htmlspecialchars($details['container_port'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="invalid-feedback" id="port-validation-feedback">If you specify a Host Port, you must also specify a Container Port.</div>
                            <hr>
                            <div class="mb-3">
                                <label class="form-label">Volume Mapping (optional)</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="host_volume_path_display" placeholder="Host Path (auto-generated)" readonly>
                                    <span class="input-group-text">:</span>
                                    <input type="text" class="form-control" name="volume_path" id="container_volume_path" placeholder="Container Path (e.g., /app/data)" value="<?= htmlspecialchars($details['volume_path'] ?? '') ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4">
                <a href="<?= base_url('/hosts/' . $host_id . '/stacks') ?>" class="btn btn-secondary">Cancel</a>
                <button type="button" class="btn btn-info" id="view-compose-yaml-btn">View Generated YAML</button>
                <button type="submit" class="btn btn-primary" id="launch-app-btn">Update Application</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const hostId = <?= $host_id ?>;
    const deploymentDetails = <?= json_encode($details) ?>;

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
    const hostPortInput = document.getElementById('host_port');
    const containerPortInput = document.getElementById('container_port');
    const launchBtn = document.getElementById('launch-app-btn');
    const previewBtn = document.getElementById('view-compose-yaml-btn');
    const containerIpGroup = document.getElementById('container-ip-group');
    const containerIpInput = document.getElementById('container_ip');
    let availableNetworks = [];
    const previewModalEl = document.getElementById('previewConfigModal');
    const previewModal = new bootstrap.Modal(previewModalEl);
    const previewModalLabel = document.getElementById('previewConfigModalLabel');
    const previewCodeContainer = document.getElementById('preview-yaml-content-container');
    const deployFromPreviewBtn = document.getElementById('deploy-from-preview-btn');

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
        const baseVolumePath = '<?= htmlspecialchars($host['default_volume_path'] ?? '/opt/stacks') ?>';
        const stackName = stackNameInput.value.trim();
        const cleanBasePath = baseVolumePath.endsWith('/') ? baseVolumePath.slice(0, -1) : baseVolumePath;

        if (stackName) {
            hostVolumePathDisplay.value = `${cleanBasePath}/${stackName}`;
        } else {
            hostVolumePathDisplay.value = `${cleanBasePath}/<stack-name>`;
        }
    }

    function checkFormValidity() {
        const stackName = stackNameInput.value.trim();
        let sourceValid = false;
        if (sourceTypeGitRadio.checked) {
            sourceValid = gitUrlInput.value.trim() !== '';
        } else {
            sourceValid = imageNameSelect.value.trim() !== '';
        }

        const hostPort = hostPortInput.value.trim();
        const containerPort = containerPortInput.value.trim();
        const portsValid = !hostPort || !!containerPort;

        const isFormValid = stackName && sourceValid && portsValid;

        launchBtn.disabled = !isFormValid;
        previewBtn.disabled = !isFormValid;

        if (!portsValid) {
            document.getElementById('port-validation-feedback').style.display = 'block';
        } else {
            document.getElementById('port-validation-feedback').style.display = 'none';
        }
    }

    function loadHostDataAndSetValues() {
        updateHostVolumePath();

        const networkPromise = fetch(`${basePath}/api/hosts/${hostId}/networks`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'success' && result.data) {
                    availableNetworks = result.data;
                    let optionsHtml = '<option value="">-- Do not attach to a specific network --</option>';
                    availableNetworks.forEach(net => {
                        optionsHtml += `<option value="${net.Name}">${net.Name}</option>`;
                    });
                    networkSelect.innerHTML = optionsHtml;
                    networkSelect.disabled = false;
                    // Set selected value
                    if (deploymentDetails.network_name) {
                        networkSelect.value = deploymentDetails.network_name;
                    }
                } else {
                    throw new Error(result.message || 'Failed to load networks.');
                }
            });

        const imagePromise = fetch(`${basePath}/api/hosts/${hostId}/images`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'success' && result.data) {
                    let optionsHtml = '<option value="" disabled selected>-- Select an image --</option>';
                    result.data.forEach(img => {
                        optionsHtml += `<option value="${img}">${img}</option>`;
                    });
                    imageNameSelect.innerHTML = optionsHtml;
                    imageNameSelect.disabled = false;
                    // Set selected value
                    if (deploymentDetails.image_name) {
                        imageNameSelect.value = deploymentDetails.image_name;
                    }
                } else {
                    throw new Error(result.message || 'Failed to load images.');
                }
            });

        const statsPromise = fetch(`${basePath}/api/hosts/${hostId}/stats`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'success' && result.data && result.data.cpus && result.data.memory) {
                    const hostCpus = result.data.cpus;
                    const hostMemoryMb = Math.floor(result.data.memory / (1024 * 1024));

                    cpuSlider.max = hostCpus;
                    cpuSlider.dispatchEvent(new Event('input'));

                    const maxMemoryStepped = Math.floor(hostMemoryMb / 1024) * 1024;
                    memorySlider.max = maxMemoryStepped > 0 ? maxMemoryStepped : 1024;
                    memorySlider.dispatchEvent(new Event('input'));
                }
            });

        Promise.all([networkPromise, imagePromise, statsPromise])
            .catch(error => {
                console.error("Error loading host data:", error);
                showToast("Could not load all data for the host. Some options may be unavailable.", false);
            })
            .finally(() => {
                checkFormValidity();
            });
    }

    stackNameInput.addEventListener('input', updateHostVolumePath);

    networkSelect.addEventListener('change', function() {
        const selectedNetworkName = this.value;
        if (selectedNetworkName) {
            containerIpGroup.style.display = 'block';
        } else {
            containerIpGroup.style.display = 'none';
            containerIpInput.value = '';
        }
    });

    // Add listeners to all relevant inputs for live validation
    [gitUrlInput, imageNameSelect, hostPortInput, containerPortInput].forEach(input => {
        input.addEventListener('input', checkFormValidity);
    });

    // --- Resource Sliders ---
    if (cpuSlider && cpuDisplay && cpuInput) {
        cpuSlider.addEventListener('input', function() {
            const value = this.value;
            cpuDisplay.textContent = value;
            cpuInput.value = value;
        });
    }
    if (memorySlider && memoryDisplay && memoryInput) {
        memorySlider.addEventListener('input', function() {
            memoryDisplay.textContent = this.value;
            memoryInput.value = `${this.value}M`;
        });
    }

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

    previewBtn.addEventListener('click', function() {
        const originalBtnContent = this.innerHTML;
        const mainForm = document.getElementById('main-form');
        const formData = new FormData(mainForm);

        this.disabled = true;
        this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Generating...`;

        let previewPromise;

        if (sourceTypeImageRadio.checked) {
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

    // --- Initial Load ---
    toggleSourceSections();
    loadHostDataAndSetValues();
});
</script>

<?php
$conn->close();
require_once __DIR__ . '/../includes/footer.php';
?>