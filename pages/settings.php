<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$conn = Database::getInstance()->getConnection();

// Ambil pengaturan saat ini
$settings = [];
$settings_result = $conn->query("SELECT * FROM settings");
while ($row = $settings_result->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Ambil daftar grup untuk dropdown
$groups_result = $conn->query("SELECT id, name FROM `groups` ORDER BY name ASC");
// Ambil daftar middleware untuk dropdown
$middlewares_result = $conn->query("SELECT id, name FROM middlewares ORDER BY name ASC");

require_once __DIR__ . '/../includes/header.php';
?>

<h3>System Settings</h3>
<hr>

<div class="card">
    <div class="card-body">
        <form id="main-form" action="<?= base_url('/settings') ?>" method="POST" data-redirect="/settings">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="default_group_id" class="form-label">Default Group for New Items</label>
                        <select class="form-select" id="default_group_id" name="default_group_id" required>
                            <?php mysqli_data_seek($groups_result, 0); ?>
                            <?php while($group = $groups_result->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($group['id']) ?>" <?= ($settings['default_group_id'] ?? 1) == $group['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($group['name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <small class="form-text text-muted">Grup ini akan dipilih secara otomatis saat membuat Router atau Service baru.</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="default_router_middleware" class="form-label">Default Middleware for New Routers</label>
                        <select class="form-select" id="default_router_middleware" name="default_router_middleware" required>
                            <option value="0">-- None --</option>
                            <?php while($mw = $middlewares_result->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($mw['id']) ?>" <?= ($settings['default_router_middleware'] ?? 0) == $mw['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($mw['name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <small class="form-text text-muted">Middleware ini akan otomatis terpasang saat membuat Router baru.</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="history_cleanup_days" class="form-label">Deployment History Cleanup Threshold (days)</label>
                        <input type="number" class="form-control" id="history_cleanup_days" name="history_cleanup_days" value="<?= htmlspecialchars($settings['history_cleanup_days'] ?? 30) ?>" min="1" required>
                        <small class="form-text text-muted">Archived deployment history older than this will be deleted during cleanup.</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="default_router_prefix" class="form-label">Default Router Name Prefix</label>
                        <input type="text" class="form-control" id="default_router_prefix" name="default_router_prefix" value="<?= htmlspecialchars($settings['default_router_prefix'] ?? 'router-') ?>">
                        <small class="form-text text-muted">This text will be pre-filled when creating a new router.</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="default_service_prefix" class="form-label">Default Service Name Prefix</label>
                        <input type="text" class="form-control" id="default_service_prefix" name="default_service_prefix" value="<?= htmlspecialchars($settings['default_service_prefix'] ?? 'service-') ?>">
                        <small class="form-text text-muted">This text will be pre-filled when creating a new service.</small>
                    </div>
                </div>
            </div>

            <hr>
            <h5 class="mb-3">Git Integration</h5>
            <div class="form-check form-switch mb-3">
                <input type="hidden" name="git_integration_enabled" value="0">
                <input class="form-check-input" type="checkbox" role="switch" id="git_integration_enabled" name="git_integration_enabled" value="1" <?= ($settings['git_integration_enabled'] ?? 0) == 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="git_integration_enabled">Enable Git Integration</label>
                <small class="form-text text-muted d-block">When enabled, "Generate & Deploy" will commit and push `dynamic.yml` to a Git repository instead of writing to a local file.</small>
            </div>

            <div id="git-settings-container" style="<?= ($settings['git_integration_enabled'] ?? 0) == 1 ? '' : 'display: none;' ?>">
                <div class="row">
                    <div class="col-md-12">
                        <div class="mb-3">
                            <label for="git_repository_url" class="form-label">Repository URL (SSH)</label>
                            <input type="text" class="form-control" id="git_repository_url" name="git_repository_url" value="<?= htmlspecialchars($settings['git_repository_url'] ?? '') ?>" placeholder="e.g., git@github.com:user/repo.git">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="git_branch" class="form-label">Branch Name</label>
                            <input type="text" class="form-control" id="git_branch" name="git_branch" value="<?= htmlspecialchars($settings['git_branch'] ?? 'main') ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="git_ssh_key_path" class="form-label">Absolute Path to SSH Private Key</label>
                            <input type="text" class="form-control" id="git_ssh_key_path" name="git_ssh_key_path" value="<?= htmlspecialchars($settings['git_ssh_key_path'] ?? '/var/www/.ssh/id_rsa') ?>">
                            <small class="form-text text-muted">Required for cloning repositories using SSH URLs (e.g., `git@...`).</small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="git_user_name" class="form-label">Git Commit User Name</label>
                            <input type="text" class="form-control" id="git_user_name" name="git_user_name" value="<?= htmlspecialchars($settings['git_user_name'] ?? 'Config Manager') ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="git_user_email" class="form-label">Git Commit User Email</label>
                            <input type="text" class="form-control" id="git_user_email" name="git_user_email" value="<?= htmlspecialchars($settings['git_user_email'] ?? 'bot@config-manager.local') ?>">
                        </div>
                    </div>
                </div>
            </div>
            
            <hr>
            <button type="submit" class="btn btn-primary">Save Settings</button>
        </form>
    </div>
</div>

<?php
$conn->close();
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const gitToggle = document.getElementById('git_integration_enabled');
    const gitContainer = document.getElementById('git-settings-container');

    gitToggle.addEventListener('change', function() {
        gitContainer.style.display = this.checked ? 'block' : 'none';
    });
});
</script>
<?php
require_once __DIR__ . '/../includes/footer.php';
?>