<?php
header('Content-Type: application/json');

echo json_encode([
    "success" => true,
    "message" => "Tier 2 API folder is reachable"
]);