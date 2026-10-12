<?php
/**
 * Shared page shell for every School Management page.
 *
 * Opens <head>, the sidebar and the content wrapper. The page then writes
 * only its own markup, then calls smPageFoot().
 *
 * @param string $title      page <title>
 * @param string $heading    <h1>
 * @param string $subtitle   <p> under the heading
 * @param string $active     sidebar key
 */
function smPageHead(string $title, string $heading, string $subtitle, string $active): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?> | KitaKits</title>
    <link rel="stylesheet" href="../css/school-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="sm-body">

<?php $__smActive = $active; include __DIR__ . "/sidebar_school.php"; ?>

<div class="sm-content">

    <div class="sm-head">
        <div>
            <h1><?php echo htmlspecialchars($heading); ?></h1>
            <p><?php echo htmlspecialchars($subtitle); ?></p>
        </div>
        <?php include __DIR__ . "/school_header_actions.php"; ?>
    </div>
<?php
}

/** Closes the page and loads the shared script. */
function smPageFoot(): void
{
    ?>
</div><!-- /.sm-content -->

<script src="../js/school-dashboard.js"></script>
<script src="../js/school-modals.js"></script>
</body>
</html>
    <?php
}

/** Formatted date, or "-" when nothing was recorded. */
function sm_d($s): string
{
    if ($s === null || $s === "") return "-";
    $ts = strtotime((string)$s);
    return $ts ? date("M d, Y", $ts) : (string)$s;
}

/** Formatted date+time, or "-". */
function sm_dt($s): string
{
    if ($s === null || $s === "") return "-";
    $ts = strtotime((string)$s);
    return $ts ? date("M d, Y g:i A", $ts) : (string)$s;
}

/** Two-decimal money string. */
function smMoney2($n): string
{
    return number_format((float)$n, 2);
}

/** Compact equipment thumbnail markup (image when one really exists). */
function smThumb(?string $image, string $alt = ""): string
{
    $alt = htmlspecialchars($alt);
    if ($image !== null && trim($image) !== "") {
        $file = basename($image);
        $path = "../uploads/" . rawurlencode($file);
        if (is_file(__DIR__ . "/../uploads/" . $file)) {
            return '<img class="sm-thumb" src="' . htmlspecialchars($path) . '" alt="' . $alt . '">';
        }
    }
    return '<span class="sm-thumb"><i class="fa-solid fa-box"></i></span>';
}

/** Larger hero image for the modals. */
function smHeroImage(?string $image, string $alt = ""): string
{
    $alt = htmlspecialchars($alt);
    if ($image !== null && trim($image) !== "") {
        $file = basename($image);
        if (is_file(__DIR__ . "/../uploads/" . $file)) {
            return '<img src="../uploads/' . rawurlencode($file) . '" alt="' . $alt . '">';
        }
    }
    return '<span class="sm-thumb-lg"><i class="fa-solid fa-box"></i></span>';
}