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
$active_page = 'images';
require_once __DIR__ . '/../includes/host_nav.php';
?>

<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-box-seam"></i> Image Management</h5>
        <div>
            <button id="refresh-images-btn" class="btn btn-sm btn-outline-primary me-2"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-sm table-hover">
                <thead>
                    <tr>
                        <th>Tag</th>
                        <th>ID</th>
                        <th>Size</th>
                        <th>Created</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="images-container">
                    <!-- Image data will be loaded here by AJAX -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const hostId = <?= $id ?>;
    const imagesContainer = document.getElementById('images-container');
    const refreshImagesBtn = document.getElementById('refresh-images-btn');

    function formatBytes(bytes, precision = 2) {
        if (!+bytes) return '0 B';

        const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        const i = Math.floor(Math.log(bytes) / Math.log(1024));

        return `${parseFloat((bytes / Math.pow(1024, i)).toFixed(precision))} ${units[i]}`;
    }

    function loadImages() {
        const originalBtnContent = refreshImagesBtn.innerHTML;
        refreshImagesBtn.disabled = true;
        refreshImagesBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Refreshing...`;
        imagesContainer.innerHTML = '<tr><td colspan="5" class="text-center"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div></td></tr>';

        fetch(`${basePath}/api/hosts/${hostId}/images?details=true`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'error') throw new Error(result.message);
                
                let html = '';
                if (result.data && result.data.length > 0) {
                    result.data.forEach(img => {
                        const tags = img.RepoTags ? img.RepoTags.join(',<br>') : '<i class="text-muted">&lt;none&gt;</i>';
                        const id = img.Id.replace('sha256:', '').substring(0, 12);
                        const size = formatBytes(img.Size);
                        const created = new Date(img.Created * 1000).toLocaleString();

                        html += `<tr><td>${tags}</td><td><code>${id}</code></td><td>${size}</td><td>${created}</td><td class="text-end"><button class="btn btn-sm btn-outline-danger delete-image-btn" data-image-id="${img.Id}" data-image-name="${img.RepoTags ? img.RepoTags[0] : id}" title="Remove Image"><i class="bi bi-trash"></i></button></td></tr>`;
                    });
                } else {
                    html = '<tr><td colspan="5" class="text-center">No images found on this host.</td></tr>';
                }
                imagesContainer.innerHTML = html;
            })
            .catch(error => imagesContainer.innerHTML = `<tr><td colspan="5" class="text-center text-danger">Failed to load images: ${error.message}</td></tr>`)
            .finally(() => {
                refreshImagesBtn.disabled = false;
                refreshImagesBtn.innerHTML = originalBtnContent;
            });
    }

    refreshImagesBtn.addEventListener('click', loadImages);
    loadImages();
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>