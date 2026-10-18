<?php
declare(strict_types=1);
use Pagos\{ClientRpc, Database, PaymentRepository};
require_once __DIR__ . '/_layout.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405); header('Allow: GET');
    echo json_encode(['status' => 'unavailable']); exit;
}
try {
    $orderId = inputId($_GET['pedidoId'] ?? null);
    $config = require dirname(__DIR__) . '/bootstrap.php';
    $row = (new PaymentRepository(Database::connect($config)))->history($orderId)[0] ?? null;
    if ($row === null) {
        http_response_code(404);
        echo json_encode(['status' => 'not_found']); exit;
    }
    $name = (new ClientRpc($config))->lookup((int) $row['cliente_id']);
    echo json_encode(['status' => $name === null ? 'not_found' : 'ok', 'name' => $name], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException) {
    http_response_code(400); echo json_encode(['status' => 'unavailable']);
} catch (Throwable) {
    http_response_code(503); echo json_encode(['status' => 'unavailable']);
}
