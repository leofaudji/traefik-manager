<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$conn = Database::getInstance()->getConnection();

if (!isset($_GET['id'])) {
    header("Location: " . base_url('/hosts?status=error&message=Host ID not provided.'));
    exit;
}

$id = (int)$_GET['id'];
$stmt = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
if (!($host = $result->fetch_assoc())) {
    header("Location: " . base_url('/hosts?status=error&message=Host not found.'));
    exit;
}
$stmt->close();
$conn->close();

require_once __DIR__ . '/../includes/header.php';
$active_page = 'networks';
require_once __DIR__ . '/../includes/host_nav.php';
?>

<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-diagram-3"></i> Network Management</h5>
        <div>
            <button id="refresh-networks-btn" class="btn btn-sm btn-outline-primary me-2"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
            <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#networkModal">
                <i class="bi bi-plus-circle"></i> Add New Network
            </button>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-sm table-hover">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>ID</th>
                        <th>Driver</th>
                        <th>Scope</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="networks-container">
                    <!-- Network data will be loaded here by AJAX -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const hostId = <?= $id ?>;
    const networksContainer = document.getElementById('networks-container');
    const refreshNetworksBtn = document.getElementById('refresh-networks-btn');

    function loadNetworks() {
        const originalBtnContent = refreshNetworksBtn.innerHTML;
        refreshNetworksBtn.disabled = true;
        refreshNetworksBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Refreshing...`;
        networksContainer.innerHTML = '<tr><td colspan="5" class="text-center"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div></td></tr>';

        fetch(`${basePath}/api/hosts/${hostId}/networks`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'error') throw new Error(result.message);
                
                let html = '';
                if (result.data && result.data.length > 0) {
                    result.data.forEach(net => {
                        const name = net.Name;
                        const id = net.Id.substring(0, 12);
                        const driver = net.Driver;
                        const scope = net.Scope;
                        const isDefaultNetwork = ['bridge', 'host', 'none'].includes(name);

                        html += `<tr>
                                    <td>${name}</td>
                                    <td><code>${id}</code></td>
                                    <td><span class="badge bg-info">${driver}</span></td>
                                    <td>${scope}</td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-danger delete-network-btn" data-network-id="${net.Id}" data-network-name="${name}" ${isDefaultNetwork ? 'disabled title="Default networks cannot be removed."' : ''}><i class="bi bi-trash"></i></button>
                                    </td>
                                 </tr>`;
                    });
                } else {
                    html = '<tr><td colspan="5" class="text-center">No custom networks found on this host.</td></tr>';
                }
                networksContainer.innerHTML = html;
            })
            .catch(error => networksContainer.innerHTML = `<tr><td colspan="5" class="text-center text-danger">Failed to load networks: ${error.message}</td></tr>`)
            .finally(() => {
                refreshNetworksBtn.disabled = false;
                refreshNetworksBtn.innerHTML = originalBtnContent;
            });
    }

    if (networksContainer) {
        refreshNetworksBtn.addEventListener('click', loadNetworks);

        networksContainer.addEventListener('click', function(e) {
            const deleteBtn = e.target.closest('.delete-network-btn');
            if (!deleteBtn) return;

            const networkId = deleteBtn.dataset.networkId;
            const networkName = deleteBtn.dataset.networkName;

            if (!confirm(`Are you sure you want to delete the network "${networkName}"?`)) {
                return;
            }

            const originalIcon = deleteBtn.innerHTML;
            deleteBtn.disabled = true;
            deleteBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`;

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('network_id', networkId);

            const url = `${basePath}/api/hosts/${hostId}/networks`;

            fetch(url, { method: 'POST', body: formData })
                .then(response => response.json().then(data => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    showToast(data.message, ok);
                    if (ok) loadNetworks();
                })
                .catch(error => showToast(error.message || 'An unknown network error occurred.', false))
                .finally(() => {
                    deleteBtn.disabled = false;
                    deleteBtn.innerHTML = originalIcon;
                });
        });
    }

    loadNetworks();
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>