<?php
require_once __DIR__ . '/../config.php';

$tab = 'invoices';
$filter = '';
$filterClient = 0;
$clients = [['id'=>1,'company_name'=>'Acme Corp']];
$dateRange = 'month';
$monthSel = '';
$df = date('Y-m-01');
$dt = date('Y-m-t');
$invoices = [1,2,3];

include __DIR__ . '/invoices_snippet.php';
