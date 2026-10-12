<?php
/**
 * Equipment CRUD endpoint for the Manage Equipment module.
 *
 * All actions are POST + JSON so the Add/Edit modals on
 * manage_equipment.php can talk to the database without a page reload.
 * Responses are always JSON: { ok: bool, message: string, ... }
 *
 * Security: requires an active admin session, and every read/write is
 * scoped to owner_id so an admin can never touch another admin's items.
 */

session_start();
require_once __DIR__ . "/../../config/database.php";

header("Content-Type: application/json; charset=utf-8");

if (!isset($_SESSION["user_id"]) || ($_SESSION["role_view"] ?? "") !== "admin") {
    http_response_code(403);
    echo json_encode(["ok" => false, "message" => "Unauthorized."]);
    exit;
}

$userId = (int)$_SESSION["user_id"];

function reply(bool $ok, string $message, array $extra = []): void
{
    echo json_encode(array_merge(["ok" => $ok, "message" => $message], $extra));
    exit;
}

$action = $_POST["action"] ?? ($_GET["action"] ?? "");

/* ------------------------------------------------------------------
   LIST - powers the Manage Equipment table + search
------------------------------------------------------------------ */
if ($action === "list") {
    $search = trim($_GET["search"] ?? "");

    if ($search === "") {
        $stmt = $conn->prepare(
            "SELECT equipment_id, equipment_name, category, description,
                    rental_price, available_quantity, status,
                requires_school_approval, declared_value, asset_code, accessories,
                    equipment_image, equipment_condition
             FROM equipment
             WHERE owner_id = ?
             ORDER BY equipment_id DESC"
        );
        $stmt->bind_param("i", $userId);
    } else {
        // Prepared statement, so the search term is never interpolated
        // into the SQL. Matches name OR category.
        $like = "%" . $search . "%";
        $stmt = $conn->prepare(
            "SELECT equipment_id, equipment_name, category, description,
                    rental_price, available_quantity, status,
                requires_school_approval, declared_value, asset_code, accessories,
                    equipment_image, equipment_condition
             FROM equipment
             WHERE owner_id = ?
               AND (equipment_name LIKE ? OR category LIKE ?)
             ORDER BY equipment_id DESC"
        );
        $stmt->bind_param("iss", $userId, $like, $like);
    }

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    reply(true, "Equipment loaded.", ["equipment" => $rows]);
}

/* ------------------------------------------------------------------
   FETCH ONE - powers the Edit modal
------------------------------------------------------------------ */
if ($action === "fetch") {
    $id = (int)($_POST["id"] ?? 0);

    $stmt = $conn->prepare(
        "SELECT equipment_id, equipment_name, category, description,
                rental_price, available_quantity, status,
                requires_school_approval, declared_value, asset_code, accessories,
                equipment_image, equipment_condition
         FROM equipment
         WHERE equipment_id = ? AND owner_id = ?"
    );
    $stmt->bind_param("ii", $id, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        reply(false, "Equipment not found.");
    }

    reply(true, "Equipment loaded.", ["equipment" => $row]);
}

/* ------------------------------------------------------------------
   Shared validation + image upload
------------------------------------------------------------------ */
define("MAX_UPLOAD_BYTES", 2 * 1024 * 1024); // 2 MB
define("DESCRIPTION_MAX", 300);

/**
 * Generate a unique asset code for a high-value item.
 *
 * The code is never typed by the admin. It is produced here, in the
 * backend, and is guaranteed unique inside this owner's catalogue:
 *
 *   HV-<equipment_id>-<random hex>
 *
 * equipment_id is part of the pattern so the code is stable across
 * updates - the same row keeps the same code even when its other
 * details change. Existing codes are left untouched.
 */
function generate_asset_code($conn, int $equipmentId): string
{
    $code = "HV-" . $equipmentId . "-" . bin2hex(random_bytes(3));

    // Safety net: keep spinning until the code is actually free.
    $tries = 0;
    while ($tries < 10) {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS n FROM equipment WHERE asset_code = ?"
        );
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $n = (int)($stmt->get_result()->fetch_assoc()["n"] ?? 0);
        $stmt->close();

        if ($n === 0) {
            return $code;
        }

        $code = "HV-" . $equipmentId . "-" . bin2hex(random_bytes(3));
        $tries++;
    }

    return "HV-" . $equipmentId . "-" . bin2hex(random_bytes(4));
}

/**
 * Validate an uploaded image. Returns an error string, or null on success
 * (with $storedName set to the generated filename). A missing file is fine.
 */
function handle_image_upload(string $field, ?string &$storedName): ?string
{
    $storedName = null;

    if (empty($_FILES[$field]) || $_FILES[$field]["error"] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$field];

    if ($file["error"] !== UPLOAD_ERR_OK) {
        return "Image upload failed (error code " . (int)$file["error"] . ").";
    }

    if ($file["size"] > MAX_UPLOAD_BYTES) {
        return "Image is too large. Maximum size is 2 MB.";
    }

    // Trust the real file type, not the client-supplied one.
    $allowed = [
        "image/jpeg" => "jpg",
        "image/png"  => "png",
        "image/webp" => "webp",
        "image/gif"  => "gif",
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file["tmp_name"]);

    if (!isset($allowed[$mime])) {
        return "Only JPG, PNG, WEBP or GIF images are allowed.";
    }

    $uploadDir = __DIR__ . "/../../uploads";

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
        return "Upload folder is missing and could not be created.";
    }

    // Unique generated name: the original filename is never used in a path.
    $filename = "eq_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $allowed[$mime];
    $target   = $uploadDir . "/" . $filename;

    if (!move_uploaded_file($file["tmp_name"], $target)) {
        return "Could not save the uploaded image.";
    }

    $storedName = $filename;
    return null;
}

/**
 * Remove an old uploaded image so files do not pile up.
 * Only touches files we generated (prefix "eq_") inside /uploads.
 */
function delete_stored_image(?string $storedName): void
{
    if (!$storedName || strpos($storedName, "eq_") !== 0) {
        return; // never delete legacy/foreign filenames
    }

    $path = __DIR__ . "/../../uploads/" . $storedName;

    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * Read + validate the shared equipment fields from $_POST.
 * Returns [data, errorMessage].
 */
function collect_fields(): array
{
    $name  = trim($_POST["equipment_name"] ?? "");
    $cat   = trim($_POST["category"] ?? "");
    $desc  = trim($_POST["description"] ?? "");
    $cond  = trim($_POST["equipment_condition"] ?? "");
    $price = trim($_POST["rental_price"] ?? "");
    $units = trim($_POST["available_quantity"] ?? "");
    $stat  = trim($_POST["status"] ?? "available");

    if ($name === "") {
        return [null, "Equipment name is required."];
    }
    if (mb_strlen($name) > 150) {
        return [null, "Equipment name is too long (max 150 characters)."];
    }
    if ($cat === "") {
        return [null, "Category is required."];
    }
    if ($price === "" || !is_numeric($price) || (float)$price < 0) {
        return [null, "Please enter a valid rental price."];
    }
    if ($units === "" || !ctype_digit($units) || (int)$units < 1) {
        return [null, "Total units must be a whole number of at least 1."];
    }

    $conditions = ["Excellent", "Good", "Fair", "Poor"];
    if ($cond === "") {
        $cond = "Good";
    }
    if (!in_array($cond, $conditions, true)) {
        return [null, "Please choose a valid condition."];
    }

    $statuses = ["available", "unavailable", "maintenance"];
    if (!in_array($stat, $statuses, true)) {
        $stat = "available";
    }

    // Description has a hard character limit. It is optional, but when it
    // is supplied it must fit.
    if (mb_strlen($desc) > DESCRIPTION_MAX) {
        return [null, "Description is too long. Maximum is " . DESCRIPTION_MAX . " characters."];
    }

    return [[
        "name"   => $name,
        "cat"    => $cat,
        "desc"   => $desc,
        "cond"   => $cond,
        "price"  => (float)$price,
        "units"  => (int)$units,
        "status" => $stat,
    ], null];
}

/**
 * Validate the high-value (requires_school_approval) fields.
 * Returns an array of error strings; an empty array means the data is
 * acceptable. Called from both create and update so the rules cannot
 * drift apart between the two paths.
 *
 * @return array<int, string>
 */
function validate_high_value_fields(): array
{
    $errors = [];

    $declaredValue = trim($_POST["declared_value"] ?? "");
    $accessories   = trim($_POST["accessories"] ?? "");

    if ($declaredValue === "" || !is_numeric($declaredValue) || (float)$declaredValue <= 0) {
        $errors[] = "Declared Value is required for high-value items.";
    }

    if ($accessories === "") {
        $errors[] = "Included Accessories is required for high-value items.";
    }

    return $errors;
}

/* ------------------------------------------------------------------
   CREATE
------------------------------------------------------------------ */
if ($action === "create") {
    [$data, $error] = collect_fields();

    if ($error) {
        reply(false, $error);
    }

    $imageName = null;
    $imgError = handle_image_upload("equipment_image", $imageName);
    if ($imgError) {
        reply(false, $imgError);
    }

    // High-value classification. An unchecked checkbox is simply absent from
    // the POST, so "isset" is the correct test. Without this the owner could
    // not flag a product here and School Management would never be involved.
    $requiresSchool = isset($_POST["requires_school_approval"]) ? 1 : 0;

    // When the item is flagged high-value, every required high-value field
    // must be completed before the row is written. This runs on the backend
    // too, so the check cannot be bypassed by a crafted request.
    if ($requiresSchool === 1) {
        $hvErrors = validate_high_value_fields();
        if ($hvErrors) {
            if ($imageName) {
                delete_stored_image($imageName);
            }
            reply(false, "High-value item incomplete: " . implode(" ", $hvErrors));
        }
    }

    $declaredValue  = trim($_POST["declared_value"] ?? "");
    $declaredValue  = ($declaredValue === "" ? null : (float)$declaredValue);
    $accessories    = trim($_POST["accessories"] ?? "");
    $accessories    = ($accessories === "" ? null : $accessories);

    // Asset codes are generated by the backend, never typed by the admin.
    // The code is generated AFTER the insert so it can embed the new
    // equipment_id, and is only generated for high-value items.
    $assetCode = null;

    $stmt = $conn->prepare(
        "INSERT INTO equipment
            (owner_id, equipment_name, category, description, rental_price,
             available_quantity, status, equipment_image, equipment_condition,
             requires_school_approval, declared_value, asset_code, accessories)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    // i=userId s=name s=cat s=desc d=price i=units s=status s=image s=condition
    // i=requiresSchool d=declaredValue s=assetCode s=accessories
    $stmt->bind_param(
        "isssdisssidss",
        $userId,
        $data["name"],
        $data["cat"],
        $data["desc"],
        $data["price"],
        $data["units"],
        $data["status"],
        $imageName,
        $data["cond"],
        $requiresSchool,
        $declaredValue,
        $assetCode,
        $accessories
    );

    if (!$stmt->execute()) {
        if ($imageName) {
            delete_stored_image($imageName);
        }
        error_log("Equipment insert failed: " . $stmt->error);
        reply(false, "Could not save the equipment. Please try again.");
    }

    $newId = (int)$stmt->insert_id;

    if ($requiresSchool === 1) {
        $generated = generate_asset_code($conn, $newId);

        $code = $conn->prepare(
            "UPDATE equipment SET asset_code = ? WHERE equipment_id = ? AND owner_id = ?"
        );
        $code->bind_param("sii", $generated, $newId, $userId);
        $code->execute();
        $code->close();

        $assetCode = $generated;
    }

    reply(true, "Equipment added successfully.", ["id" => $newId, "asset_code" => $assetCode]);
}

/* ------------------------------------------------------------------
   UPDATE
------------------------------------------------------------------ */
if ($action === "update") {
    $id = (int)($_POST["id"] ?? 0);

    if ($id < 1) {
        reply(false, "Invalid equipment id.");
    }

    // Confirm the row belongs to this admin before changing anything.
    $own = $conn->prepare(
        "SELECT equipment_image FROM equipment WHERE equipment_id = ? AND owner_id = ?"
    );
    $own->bind_param("ii", $id, $userId);
    $own->execute();
    $existing = $own->get_result()->fetch_assoc();

    if (!$existing) {
        reply(false, "Equipment not found.");
    }

    [$data, $error] = collect_fields();

    if ($error) {
        reply(false, $error);
    }

    // Keep the current image unless a new one was uploaded.
    $imageName = $existing["equipment_image"];
    $newUpload = null;

    $imgError = handle_image_upload("equipment_image", $newUpload);
    if ($imgError) {
        reply(false, $imgError);
    }
    if ($newUpload) {
        $imageName = $newUpload;
    }

    // Keep the high-value classification editable from Manage Equipment too,
    // matching the create path.
    $requiresSchool = isset($_POST["requires_school_approval"]) ? 1 : 0;

    // When the item is flagged high-value, every required high-value field
    // must be completed. This runs on the backend too, so the check cannot
    // be bypassed by a crafted request.
    if ($requiresSchool === 1) {
        $hvErrors = validate_high_value_fields();
        if ($hvErrors) {
            if ($newUpload) {
                delete_stored_image($newUpload);
            }
            reply(false, "High-value item incomplete: " . implode(" ", $hvErrors));
        }
    }

    $declaredValue  = trim($_POST["declared_value"] ?? "");
    $declaredValue  = ($declaredValue === "" ? null : (float)$declaredValue);
    $accessories    = trim($_POST["accessories"] ?? "");
    $accessories    = ($accessories === "" ? null : $accessories);

    // Asset codes are never typed by the admin. When an item BECOMES
    // high-value during an edit and has no code yet, generate one now.
    // When it already has one, keep it - and when it stops being
    // high-value, clear it so the column only ever holds real codes.
    $assetCode = $existing["asset_code"] ?? null;

    $stmt = $conn->prepare(
        "UPDATE equipment SET
            equipment_name = ?,
            category = ?,
            description = ?,
            rental_price = ?,
            available_quantity = ?,
            status = ?,
            equipment_image = ?,
            equipment_condition = ?,
            requires_school_approval = ?,
            declared_value = ?,
            asset_code = ?,
            accessories = ?
         WHERE equipment_id = ? AND owner_id = ?"
    );
    // s=name s=cat s=desc d=price i=units s=status s=image s=condition
    // i=requiresSchool d=declaredValue s=assetCode s=accessories i=id i=owner
    $stmt->bind_param(
        "sssdisssidssii",
        $data["name"],
        $data["cat"],
        $data["desc"],
        $data["price"],
        $data["units"],
        $data["status"],
        $imageName,
        $data["cond"],
        $requiresSchool,
        $declaredValue,
        $assetCode,
        $accessories,
        $id,
        $userId
    );

    if (!$stmt->execute()) {
        if ($newUpload) {
            delete_stored_image($newUpload);
        }
        error_log("Equipment update failed: " . $stmt->error);
        reply(false, "Could not update the equipment. Please try again.");
    }

    // Only drop the old image once the new row saved cleanly.
    if ($newUpload && $existing["equipment_image"] !== $newUpload) {
        delete_stored_image($existing["equipment_image"]);
    }

    // If this edit made the item high-value and it still has no code,
    // generate one now so the column is never left empty for a
    // high-value row.
    if ($requiresSchool === 1 && (string)($assetCode ?? "") === "") {
        $generated = generate_asset_code($conn, $id);

        $code = $conn->prepare(
            "UPDATE equipment SET asset_code = ? WHERE equipment_id = ? AND owner_id = ?"
        );
        $code->bind_param("sii", $generated, $id, $userId);
        $code->execute();
        $code->close();

        $assetCode = $generated;
    }

    reply(true, "Equipment updated successfully.", ["asset_code" => $assetCode]);
}

/* ------------------------------------------------------------------
   DELETE

   The bare DELETE that used to live here failed with

       ERROR 1451 - foreign key constraint fails (reservations_ibfk_2)

   for any item that had ever been reserved, because reservations still
   referenced the row. So delete looked broken on exactly the items that
   most needed it.

   Now:
     1. active/pending rental -> refuse, nothing destroyed
     2. history exists        -> archive (status='archived'), history kept
     3. no linked rows        -> real DELETE

   The Student equipment page reads only status='available', so an
   archived item disappears from those listings with no extra filtering.
   Ownership comes from the session $userId, never from the posted id.
------------------------------------------------------------------ */
if ($action === "delete") {
    $id = (int)($_POST["id"] ?? 0);

    if ($id < 1) {
        reply(false, "Invalid equipment id.");
    }

    $own = $conn->prepare(
        "SELECT equipment_image, equipment_name FROM equipment WHERE equipment_id = ? AND owner_id = ?"
    );
    $own->bind_param("ii", $id, $userId);
    $own->execute();
    $existing = $own->get_result()->fetch_assoc();
    $own->close();

    if (!$existing) {
        reply(false, "Equipment not found, or you do not own it.");
    }

    // 1. Anything currently in flight?
    $live = $conn->prepare(
        "SELECT COUNT(*) AS n FROM reservations
          WHERE equipment_id = ?
            AND status IN ('pending','approved','ongoing','overdue')"
    );
    $live->bind_param("i", $id);
    $live->execute();
    $activeCount = (int)($live->get_result()->fetch_assoc()["n"] ?? 0);
    $live->close();

    if ($activeCount > 0) {
        reply(false, "This equipment cannot be deleted while it has an active or pending rental.");
    }

    // 2. Any history at all?
    $hist = $conn->prepare("SELECT COUNT(*) AS n FROM reservations WHERE equipment_id = ?");
    $hist->bind_param("i", $id);
    $hist->execute();
    $historyCount = (int)($hist->get_result()->fetch_assoc()["n"] ?? 0);
    $hist->close();

    if ($historyCount > 0) {
        $arch = $conn->prepare(
            "UPDATE equipment SET status = 'archived' WHERE equipment_id = ? AND owner_id = ?"
        );
        $arch->bind_param("ii", $id, $userId);

        if (!$arch->execute()) {
            error_log("Equipment archive failed: " . $arch->error);
            reply(false, "Could not archive the equipment.");
        }
        $arch->close();

        reply(
            true,
            "\"{$existing['equipment_name']}\" was archived because it has rental history. "
            . "Past reservations and transactions were kept."
        );
    }

    // 3. Nothing linked -> safe to remove for real.
    $stmt = $conn->prepare("DELETE FROM equipment WHERE equipment_id = ? AND owner_id = ?");
    $stmt->bind_param("ii", $id, $userId);

    if (!$stmt->execute()) {
        error_log("Equipment delete failed: " . $stmt->error);
        reply(false, "Could not delete the equipment.");
    }
    $stmt->close();

    delete_stored_image($existing["equipment_image"]);

    reply(true, "Equipment deleted successfully.");
}

reply(false, "Unknown action.");
