<?php
declare(strict_types=1);
use Pagos\{Database, PaymentRepository};
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405); header('Allow: GET');
    echo json_encode(['error' => true]); exit;
}
try {
    $config = require dirname(__DIR__) . '/bootstrap.php';
    echo json_encode((new PaymentRepository(Database::connect($config)))->awaitingDecision(), JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(503); echo json_encode(['error' => true]);
}
