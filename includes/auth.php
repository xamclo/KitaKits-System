<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function requireRole(string $role): void
{
    if (!isset($_SESSION['user_id']) || ($_SESSION['role_view'] ?? '') !== $role) {
        header('Location: ../index.php');
        exit();
    }
}

function currentUserId(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}
