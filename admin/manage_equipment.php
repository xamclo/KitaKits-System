<?php
/**
 * Manage Equipment - table view with search, plus the Add/Edit modals.
 *
 * The table itself is rendered by js/manage-equipment.js from
 * admin/api/equipment.php, so what you see always matches the database.
 * Authentication and the shared sidebar are unchanged.
 */

session_start();
require_once "../config/database.php";

if (!isset($_SESSION["user_id"]) || ($_SESSION["role_view"] ?? "") !== "admin") {
    header("Location: ../index.php");
    exit;
}

$conditions = ["Excellent", "Good", "Fair"];
$statuses   = ["available", "on hold"];

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Equipment | KitaKits</title>

    <link rel="stylesheet" href="../css/style.css">
    <?php include __DIR__ . "/includes/assets.php"; ?>

    <!-- Manage Equipment only. Loaded after the shared assets so it can
         style this module without touching any other page. -->
    <link rel="stylesheet" href="../css/manage-equipment.css?v=<?php echo @filemtime(__DIR__ . "/../css/manage-equipment.css"); ?>">

    <!-- Shared admin table UI (Manage Equipment + Rental Requests).
         Loaded last so it finalises typography, spacing and badges
         consistently across both tables. -->
    <link rel="stylesheet" href="../css/admin-tables.css?v=<?php echo @filemtime(__DIR__ . "/../css/admin-tables.css"); ?>">
</head>

<body class="new-dashboard">

<?php include __DIR__ . "/includes/sidebar.php"; ?>

<div class="dashboard-content" id="content">

    <div class="top-header">
        <div>
            <h1>Manage Equipment</h1>
            <p>Manage the equipment you listed for rental</p>
        </div>
    </div>

    <!-- TOOLBAR: search + add -->
    <div class="eq-toolbar">

        <div class="eq-search">
            <i class="fa-solid fa-magnifying-glass eq-search__icon"></i>
            <input
                type="search"
                id="eqSearch"
                placeholder="Search equipment&hellip;"
                autocomplete="off"
                aria-label="Search equipment by name or category">
        </div>

        <button type="button" class="eq-add-btn" id="eqAddBtn">
            <i class="fa-solid fa-plus"></i>
            Add Equipment
        </button>

    </div>

    <p class="eq-count" id="eqCount">Loading equipment&hellip;</p>

    <!-- TABLE -->
    <div class="eq-table-wrap">
        <table class="eq-table">
            <thead>
                <tr>
                    <th>Photo</th>
                    <th>Equipment</th>
                    <th>Category</th>
                    <th>Price / Day</th>
                    <th>Condition</th>
                    <th>Available Units</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="eqTableBody">
                <tr>
                    <td colspan="7" style="text-align:center;color:#94A3B8;padding:40px;">
                        Loading&hellip;
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

<!-- ================= ADD EQUIPMENT MODAL ================= -->
<div class="eq-modal" id="addModal" role="dialog" aria-modal="true"
     aria-labelledby="addModalTitle">

    <div class="eq-modal__card">

        <div class="eq-modal__head">
            <div>
                <h2 class="eq-modal__title" id="addModalTitle">Add Equipment</h2>
                <p class="eq-modal__sub">Fill in the details to list new equipment.</p>
            </div>
            <button type="button" class="eq-modal__close" data-close aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="eq-alert" id="addAlert"></div>

        <form id="addForm" enctype="multipart/form-data">

            <div class="eq-form-grid">

                <div class="eq-field">
                    <label for="addName">Name</label>
                    <input type="text" id="addName" name="equipment_name"
                           maxlength="150" required>
                </div>

                <div class="eq-field">
                    <label for="addPrice">Price / day</label>
                    <input type="number" id="addPrice" name="rental_price"
                           step="0.01" min="0" required>
                </div>

                <div class="eq-field">
                    <label for="addCategory">Category</label>
                    <input type="text" id="addCategory" name="category"
                           list="eqCategoryList" maxlength="100" required>
                    <datalist id="eqCategoryList">
                        <option value="Camera"></option>
                        <option value="Computer"></option>
                        <option value="Audio"></option>
                        <option value="Lighting"></option>
                        <option value="Projector"></option>
                        <option value="Other"></option>
                    </datalist>
                </div>

                <div class="eq-field">
                    <label for="addCondition">Condition</label>
                    <select id="addCondition" name="equipment_condition" required>
<?php foreach ($conditions as $c): ?>
                        <option value="<?php echo $c; ?>"<?php echo $c === "Good" ? " selected" : ""; ?>>
                            <?php echo $c; ?>
                        </option>
<?php endforeach; ?>
                    </select>
                </div>

                <div class="eq-field">
                    <label for="addUnits">Total Units</label>
                    <input type="number" id="addUnits" name="available_quantity"
                           min="1" step="1" value="1" required>
                </div>

                <div class="eq-field">
                    <label for="addStatus">Status</label>
                    <select id="addStatus" name="status" required>
<?php foreach ($statuses as $s): ?>
                        <option value="<?php echo $s; ?>"><?php echo ucfirst($s); ?></option>
<?php endforeach; ?>
                    </select>
                </div>

                                <div class="eq-field eq-field--full">
                    <div class="eq-check">
                        <span class="eq-check__control">
                            <input type="checkbox" id="addRequiresSchool" name="requires_school_approval" value="1">
                        </span>
                        <span class="eq-check__text">High-value item &mdash; requires School Management approval</span>
                    </div>
                </div>

                <div class="eq-field eq-field--full eq-hv-fields" id="addHvFields" aria-hidden="true">
                    <span class="eq-hv-fields__title">High-value item details</span>

                    <div class="eq-field">
                        <label for="addDeclaredValue">Declared Value (PHP) <span class="eq-field__req">*</span></label>
                        <input type="number" id="addDeclaredValue" name="declared_value"
                               step="0.01" min="0" placeholder="e.g. 25000">
                    </div>

                    <div class="eq-field">
                        <label for="addAccessories">Included Accessories <span class="eq-field__req">*</span></label>
                        <input type="text" id="addAccessories" name="accessories"
                               placeholder="e.g. Charger, Carry case, Mouse">
                    </div>

                    <span class="eq-hv-fields__hint">Asset codes are generated automatically by the system.</span>
                </div>

                <div class="eq-field eq-field--full">
                    <label for="addDescription">Description</label>
                    <textarea id="addDescription" name="description"
                              placeholder="Short notes about this equipment"
                              maxlength="300"></textarea>
                    <span class="eq-field__counter" id="addDescCounter">0 / 300</span>
                </div>

                <div class="eq-upload">
                    <div class="eq-upload__preview" id="addImagePreview">
                        <i class="fa-solid fa-image"></i>
                    </div>
                    <div class="eq-upload__body">
                        <strong>Equipment photo</strong>
                        <small>JPG, PNG, WEBP or GIF. Max 2 MB.</small>
                        <input type="file" id="addImage" name="equipment_image"
                               accept="image/jpeg,image/png,image/webp,image/gif">
                    </div>
                </div>

            </div>

            <div class="eq-modal__actions">
                <button type="button" class="eq-btn eq-btn--ghost" data-close>Cancel</button>
                <button type="submit" class="eq-btn eq-btn--primary">
                    <i class="fa-solid fa-plus"></i> Add Equipment
                </button>
            </div>

        </form>

    </div>
</div>

<!-- ================= EDIT EQUIPMENT MODAL ================= -->
<div class="eq-modal" id="editModal" role="dialog" aria-modal="true"
     aria-labelledby="editModalTitle">

    <div class="eq-modal__card">

        <div class="eq-modal__head">
            <div>
                <h2 class="eq-modal__title" id="editModalTitle">Edit Equipment</h2>
                <p class="eq-modal__sub">Update the details and save your changes.</p>
            </div>
            <button type="button" class="eq-modal__close" data-close aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="eq-alert" id="editAlert"></div>

        <form id="editForm" enctype="multipart/form-data">
            <input type="hidden" name="id" id="editId" value="">

            <div class="eq-form-grid">

                <div class="eq-field">
                    <label for="editName">Name</label>
                    <input type="text" id="editName" name="equipment_name"
                           maxlength="150" required>
                </div>

                <div class="eq-field">
                    <label for="editPrice">Price / day</label>
                    <input type="number" id="editPrice" name="rental_price"
                           step="0.01" min="0" required>
                </div>

                <div class="eq-field">
                    <label for="editCategory">Category</label>
                    <input type="text" id="editCategory" name="category"
                           list="eqCategoryList" maxlength="100" required>
                </div>

                <div class="eq-field">
                    <label for="editCondition">Condition</label>
                    <select id="editCondition" name="equipment_condition" required>
<?php foreach ($conditions as $c): ?>
                        <option value="<?php echo $c; ?>"><?php echo $c; ?></option>
<?php endforeach; ?>
                    </select>
                </div>

                <div class="eq-field">
                    <label for="editUnits">Total Units</label>
                    <input type="number" id="editUnits" name="available_quantity"
                           min="1" step="1" required>
                </div>

                <div class="eq-field">
                    <label for="editStatus">Status</label>
                    <select id="editStatus" name="status" required>
<?php foreach ($statuses as $s): ?>
                        <option value="<?php echo $s; ?>"><?php echo ucfirst($s); ?></option>
<?php endforeach; ?>
                    </select>
                </div>

                                <div class="eq-field eq-field--full">
                    <div class="eq-check">
                        <span class="eq-check__control">
                            <input type="checkbox" id="editRequiresSchool" name="requires_school_approval" value="1">
                        </span>
                        <span class="eq-check__text">High-value item &mdash; requires School Management approval</span>
                    </div>
                </div>

                <div class="eq-field eq-field--full eq-hv-fields" id="editHvFields" aria-hidden="true">
                    <span class="eq-hv-fields__title">High-value item details</span>

                    <div class="eq-field">
                        <label for="editDeclaredValue">Declared Value (PHP) <span class="eq-field__req">*</span></label>
                        <input type="number" id="editDeclaredValue" name="declared_value"
                               step="0.01" min="0" placeholder="e.g. 25000">
                    </div>

                    <div class="eq-field">
                        <label for="editAccessories">Included Accessories <span class="eq-field__req">*</span></label>
                        <input type="text" id="editAccessories" name="accessories"
                               placeholder="e.g. Charger, Carry case, Mouse">
                    </div>

                    <span class="eq-hv-fields__hint">Asset codes are generated automatically by the system.</span>
                </div>

                <div class="eq-field eq-field--full">
                    <label for="editDescription">Description</label>
                    <textarea id="editDescription" name="description"
                              placeholder="Short notes about this equipment"
                              maxlength="300"></textarea>
                    <span class="eq-field__counter" id="editDescCounter">0 / 300</span>
                </div>

                <div class="eq-upload">
                    <div class="eq-upload__preview" id="editImagePreview">
                        <i class="fa-solid fa-image"></i>
                    </div>
                    <div class="eq-upload__body">
                        <strong>Equipment photo</strong>
                        <small>Leave empty to keep the current photo. Max 2 MB.</small>
                        <input type="file" id="editImage" name="equipment_image"
                               accept="image/jpeg,image/png,image/webp,image/gif">
                    </div>
                </div>

            </div>

            <div class="eq-modal__actions">
                <button type="button" class="eq-btn eq-btn--ghost" data-close>Cancel</button>
                <button type="submit" class="eq-btn eq-btn--primary">
                    <i class="fa-solid fa-floppy-disk"></i> Save Changes
                </button>
            </div>

        </form>

    </div>
</div>

</div>

<!-- TOAST -->
<div class="eq-toast" id="eqToast" role="status" aria-live="polite"></div>

<script src="../js/manage-equipment.js?v=<?php echo @filemtime(__DIR__ . "/../js/manage-equipment.js"); ?>"></script>

</body>

</html>
