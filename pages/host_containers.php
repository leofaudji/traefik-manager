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
$active_page = 'containers';
require_once __DIR__ . '/../includes/host_nav.php';
?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Containers</h5>
        <div>
            <div class="btn-group btn-group-sm me-2" role="group" id="container-filter-group">
                <button type="button" class="btn btn-outline-secondary active" data-filter="all">All</button>
                <button type="button" class="btn btn-outline-secondary" data-filter="running">Running</button>
                <button type="button" class="btn btn-outline-secondary" data-filter="stopped">Stopped</button>
            </div>
            <button id="refresh-containers-btn" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-sm table-hover">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Image</th>
                        <th>State</th>
                        <th>Status</th>
                        <th>IP Address</th>
                        <th>Volumes</th>
                        <th>Networks</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="containers-container">
                    <!-- Container data will be loaded here by AJAX -->
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small" id="containers-info"></div>
        <div class="d-flex align-items-center">
            <nav id="containers-pagination"></nav>
            <div class="ms-3">
                <select name="limit_containers" class="form-select form-select-sm" id="containers-limit-selector" style="width: auto;">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="-1">All</option>
                </select>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const hostId = <?= $id ?>;
    const containerBody = document.getElementById('containers-container');
    const refreshBtn = document.getElementById('refresh-containers-btn');
    const filterGroup = document.getElementById('container-filter-group');
    const paginationContainer = document.getElementById('containers-pagination');
    const infoContainer = document.getElementById('containers-info');
    const limitSelector = document.getElementById('containers-limit-selector');

    let currentFilter = localStorage.getItem(`host_${hostId}_containers_filter`) || 'all';
    let currentPage = 1;
    let currentLimit = 10;

    function reloadCurrentView() {
        loadContainers(parseInt(currentPage), parseInt(currentLimit));
    }

    function loadContainers(page = 1, limit = 10) {
        currentPage = parseInt(page) || 1;
        currentLimit = parseInt(limit) || 10;
        const originalBtnContent = refreshBtn.innerHTML;
        refreshBtn.disabled = true;
        refreshBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Refreshing...`;
        containerBody.innerHTML = '<tr><td colspan="8" class="text-center"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div></td></tr>';

        const fetchUrl = `${basePath}/api/hosts/${hostId}/containers?page=${page}&limit=${limit}&filter=${currentFilter}`;

        fetch(fetchUrl)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'error') throw new Error(result.message);
                
                containerBody.innerHTML = result.html;
                infoContainer.innerHTML = result.info;

                // Build pagination
                let paginationHtml = '';
                if (result.total_pages > 1) {
                    paginationHtml += '<ul class="pagination pagination-sm mb-0">';
                    paginationHtml += `<li class="page-item ${result.current_page <= 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${result.current_page - 1}">«</a></li>`;
                    for (let i = 1; i <= result.total_pages; i++) {
                        paginationHtml += `<li class="page-item ${result.current_page == i ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
                    }
                    paginationHtml += `<li class="page-item ${result.current_page >= result.total_pages ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${parseInt(result.current_page) + 1}">»</a></li>`;
                    paginationHtml += '</ul>';
                }
                paginationContainer.innerHTML = paginationHtml;

                // Save state
                localStorage.setItem(`host_${hostId}_containers_page`, result.current_page);
                localStorage.setItem(`host_${hostId}_containers_limit`, result.limit);
                localStorage.setItem(`host_${hostId}_containers_filter`, currentFilter);
            })
            .catch(error => containerBody.innerHTML = `<tr><td colspan="8" class="text-center text-danger">Failed to load containers: ${error.message}</td></tr>`)
            .finally(() => {
                refreshBtn.disabled = false;
                refreshBtn.innerHTML = originalBtnContent;
            });
    }

    // --- Event Listeners ---

    refreshBtn.addEventListener('click', reloadCurrentView);

    filterGroup.addEventListener('click', function(e) {
        if (e.target.tagName === 'BUTTON') {
            currentFilter = e.target.dataset.filter;
            filterGroup.querySelector('.active').classList.remove('active');
            e.target.classList.add('active');
            loadContainers(1, limitSelector.value);
        }
    });

    paginationContainer.addEventListener('click', function(e) {
        const pageLink = e.target.closest('.page-link');
        if (pageLink) {
            e.preventDefault();
            loadContainers(parseInt(pageLink.dataset.page), limitSelector.value);
        }
    });

    limitSelector.addEventListener('change', function() {
        loadContainers(1, this.value);
    });

    containerBody.addEventListener('click', function(e) {
        const actionBtn = e.target.closest('.container-action-btn');
        if (!actionBtn) return;

        e.preventDefault();
        const containerId = actionBtn.dataset.containerId;
        const action = actionBtn.dataset.action;

        if (!confirm(`Are you sure you want to ${action} this container?`)) {
            return;
        }

        const originalIcon = actionBtn.innerHTML;
        actionBtn.disabled = true;
        actionBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`;

        const url = `${basePath}/api/hosts/${hostId}/containers/${containerId}/${action}`;

        fetch(url, { method: 'POST' })
            .then(response => response.json().then(data => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                showToast(data.message, ok);
                if (ok) {
                    // Refresh the view after a short delay to allow Docker to update the container's state.
                    setTimeout(reloadCurrentView, 2000);
                } else {
                    actionBtn.disabled = false;
                    actionBtn.innerHTML = originalIcon;
                }
            })
            .catch(error => {
                showToast(error.message || 'An unknown network error occurred.', false);
                actionBtn.disabled = false;
                actionBtn.innerHTML = originalIcon;
            });
    });

    const viewLogsModal = document.getElementById('viewLogsModal');
    if (viewLogsModal) {
        const logContentContainer = document.getElementById('log-content-container');
        const logModalLabel = document.getElementById('viewLogsModalLabel');

        viewLogsModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const containerId = button.dataset.containerId;
            const containerName = button.dataset.containerName;

            logModalLabel.textContent = `Logs for: ${containerName}`;
            logContentContainer.textContent = 'Loading logs...';

            const url = `${basePath}/api/hosts/${hostId}/containers/${containerId}/logs`;

            fetch(url)
                .then(response => response.json().then(data => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    if (ok) {
                        logContentContainer.textContent = data.logs || 'No logs found or logs are empty.';
                    } else {
                        throw new Error(data.message || 'Failed to fetch logs.');
                    }
                })
                .catch(error => {
                    logContentContainer.textContent = `Error: ${error.message}`;
                });
        });
    }

    // --- Initial Load ---
    function initialize() {
        const initialPage = parseInt(localStorage.getItem(`host_${hostId}_containers_page`)) || 1;
        const initialLimit = parseInt(localStorage.getItem(`host_${hostId}_containers_limit`)) || 10;
        
        filterGroup.querySelector('.active')?.classList.remove('active');
        filterGroup.querySelector(`button[data-filter="${currentFilter}"]`)?.classList.add('active');
        limitSelector.value = initialLimit;

        loadContainers(initialPage, initialLimit);
    }

    initialize();
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>