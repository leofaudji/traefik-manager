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
$active_page = 'stacks';
require_once __DIR__ . '/../includes/host_nav.php';
?>

<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-stack"></i> Application Stacks</h5>
        <div id="stack-actions-container">
            <a href="<?= base_url('/hosts/' . $id . '/stacks/new') ?>" class="btn btn-sm btn-outline-primary" style="display: none;" id="add-stack-btn">
                <i class="bi bi-plus-circle"></i> Add New Stack
            </a>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-sm table-hover">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Services</th>
                        <th>Created At</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="stacks-container">
                    <!-- Stacks data will be loaded here by AJAX -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- View Stack Spec Modal -->
<div class="modal fade" id="viewStackSpecModal" tabindex="-1" aria-labelledby="viewStackSpecModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="viewStackSpecModalLabel">Stack Specification</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <pre><code id="stack-spec-content-container" class="language-yaml">Loading...</code></pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const hostId = <?= $id ?>;
    const stacksContainer = document.getElementById('stacks-container');
    let isSwarmManager = false; // To be determined by API call

    function loadStacks() {
        stacksContainer.innerHTML = '<tr><td colspan="5" class="text-center"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div></td></tr>';

        fetch(`${basePath}/api/hosts/${hostId}/stacks`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'error') throw new Error(result.message);
                
                let html = '';
                if (result.data && result.data.length > 0) {
                    result.data.forEach(stack => {
                        
                        const deleteButton = isSwarmManager 
                            ? `<button class="btn btn-sm btn-outline-danger delete-stack-btn" data-stack-id="${stack.ID}" data-stack-name="${stack.Name}" title="Delete Stack"><i class="bi bi-trash"></i></button>`
                            : `<button class="btn btn-sm btn-outline-danger" disabled title="Deletion only available on Swarm managers"><i class="bi bi-trash"></i></button>`;

                        html += `<tr>
                                    <td>${stack.Name}</td>
                                    <td>${stack.Description || ''}</td>
                                    <td>${stack.Services}</td>
                                    <td>${new Date(stack.CreatedAt).toLocaleString()}</td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-info view-stack-spec-btn" data-bs-toggle="modal" data-bs-target="#viewStackSpecModal" data-stack-name="${stack.Name}" title="View Spec"><i class="bi bi-eye"></i></button>
                                        ${deleteButton}
                                    </td>
                                 </tr>`;
                    });
                } else {
                    html = '<tr><td colspan="5" class="text-center">No stacks found on this host.</td></tr>';
                }
                stacksContainer.innerHTML = html;
            })
            .catch(error => stacksContainer.innerHTML = `<tr><td colspan="5" class="text-center text-danger">Failed to load stacks: ${error.message}</td></tr>`);
    }

    stacksContainer.addEventListener('click', function(e) {
        const deleteBtn = e.target.closest('.delete-stack-btn');
        if (!deleteBtn) return;

        const stackId = deleteBtn.dataset.stackId;
        const stackName = deleteBtn.dataset.stackName;

        if (!confirm(`Are you sure you want to delete the stack "${stackName}" from the host? This action cannot be undone.`)) return;

        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('stack_id', stackId);

        fetch(`${basePath}/api/hosts/${hostId}/stacks`, { method: 'POST', body: formData })
            .then(response => response.json().then(data => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                showToast(data.message, ok);
                if (ok) loadStacks();
            });
    });

    function checkSwarmStatusAndLoad() {
        const addStackBtn = document.getElementById('add-stack-btn');
        const stackActionsContainer = document.getElementById('stack-actions-container');

        fetch(`${basePath}/api/hosts/${hostId}/stats`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'success' && result.data.is_swarm_manager) {
                    isSwarmManager = true;
                    addStackBtn.style.display = 'inline-block';
                } else {
                    isSwarmManager = false;
                    addStackBtn.style.display = 'none';
                    if (!document.getElementById('non-swarm-msg')) {
                        const msg = document.createElement('small');
                        msg.id = 'non-swarm-msg';
                        msg.className = 'text-muted';
                        msg.textContent = 'Stack deployment is only available on Swarm managers.';
                        stackActionsContainer.appendChild(msg);
                    }
                }
            })
            .catch(err => console.error("Could not get swarm status", err))
            .finally(() => {
                loadStacks(); // Load stacks after checking swarm status
            });
    }

    const viewStackSpecModal = document.getElementById('viewStackSpecModal');
    if (viewStackSpecModal) {
        const contentContainer = document.getElementById('stack-spec-content-container');
        const modalLabel = document.getElementById('viewStackSpecModalLabel');

        viewStackSpecModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const stackName = button.dataset.stackName;

            modalLabel.textContent = `Specification for Stack: ${stackName}`;
            contentContainer.textContent = 'Loading...';

            fetch(`${basePath}/api/hosts/${hostId}/stacks/${stackName}/spec`)
                .then(response => response.json())
                .then(result => {
                    if (result.status === 'success') {
                        contentContainer.textContent = result.content;
                        Prism.highlightElement(contentContainer);
                    } else {
                        throw new Error(result.message);
                    }
                })
                .catch(error => contentContainer.textContent = `Error: ${error.message}`);
        });
    }

    checkSwarmStatusAndLoad();
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>