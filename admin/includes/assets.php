<?php
/**
 * Shared asset loader for every admin page.
 *
 * Keeping the sidebar CSS/JS <link>/<script> tags here means a change to
 * how they are loaded happens in ONE place, and no page can accidentally
 * load a different set of assets.
 *
 * Usage in <head>:
 *   <?php include __DIR__ . "/includes/assets.php"; ?>
 *
 * Each URL gets a ?v=<mtime> cache-buster taken from the file itself.
 * Because the version changes whenever the file changes, editing the CSS
 * or JS shows up on a normal reload instead of being hidden by the
 * browser cache. That removes the need to hard-refresh (Ctrl+F5) while
 * working on the UI.
 */

$adminAssetRoot = __DIR__ . "/../../";

/**
 * Build a cache-busted URL for a project asset.
 */
function admin_asset_url(string $relativePath, string $root): string
{
    $webPath  = "../" . ltrim($relativePath, "/");
    $diskPath = $root . ltrim($relativePath, "/");

    if (is_file($diskPath)) {
        $webPath .= "?v=" . filemtime($diskPath);
    }

    return $webPath;
}
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="<?php echo admin_asset_url("css/admin-sidebar.css", $adminAssetRoot); ?>">

<script defer src="<?php echo admin_asset_url("js/admin-sidebar.js", $adminAssetRoot); ?>"></script>
