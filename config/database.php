<?php
$host = "localhost";
$user = "root";
$pass = "";
$db = "journal_db";

$conn =mysqli_connect($host, $user, $db);

if (!$conn) (
    die("Koneksi database gagal");
)