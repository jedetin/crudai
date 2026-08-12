
<?php
require_once __DIR__ . '/api/core/DB.php';

DB::connect([
    'host' => 'localhost',
    'name' => 'crudai_test',
    'user' => 'root',
    'pass' => '',
]);