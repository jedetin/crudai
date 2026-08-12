<?php 
include 'bootstrap.php';
include 'api/Employee.php';

// $e = new Company();
// $data = ["id"=> 3, "name"=>"Contoso Corp."];
// $res = $e->update($data["id"], $data);
// $res  = $e->create($data);

$e = new Employee();
$data = ["id"=> 4,"company_id"=> 3, "name"=>"Raj Rajesh", "email"=>"test@res.com"];
// $res = $e->update($data["id"], $data);
$res  = $e->create($data);
print_r($res);