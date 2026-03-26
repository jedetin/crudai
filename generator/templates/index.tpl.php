<?php
/**
 * Template: index.tpl.php
 *
 * Variables injected by Generator:
 *   array<string, TableDef> $tables
 *   array $config   — DB credentials
 */
?>
<?php echo "<?php\n" ?>

declare(strict_types=1);

/**
 * CRUDify API — Router
 * Generated on <?= date('Y-m-d H:i:s') ?>

 * Do not edit by hand — re-run the generator to update.
 */

require_once __DIR__ . '/core/DB.php';
require_once __DIR__ . '/core/Request.php';
require_once __DIR__ . '/core/Response.php';

// Database connection
DB::connect([
    'host' => '<?= $config['host'] ?>',
    'name' => '<?= $config['name'] ?>',
    'user' => '<?= $config['user'] ?>',
    'pass' => '<?= $config['pass'] ?>',
]);

// CORS headers (adjust origins for production)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

$req = new Request();

// Preflight
if ($req->method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Load handler files
$handlers = [
<?php foreach ($tables as $name => $_): ?>
    '<?= $name ?>' => __DIR__ . '/<?= $name ?>.php',
<?php endforeach; ?>
];

$resource = $req->resource();

if (!isset($handlers[$resource])) {
    Response::notFound("Unknown resource: {$resource}");
}

require_once $handlers[$resource];

// Dispatch: every handler file exposes a  {table}_handle(Request)  function
$fn = $resource . '_handle';
if (!function_exists($fn)) {
    Response::error("Handler function {$fn}() not found", 500);
}

$fn($req);