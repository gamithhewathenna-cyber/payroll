<?php
require_once __DIR__ . '/../config.php';
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['full_name'] = 'Test Admin';
$_SESSION['settings'] = ['currency_symbol' => 'Rs.', 'rates_updated' => date('Y-m-d')];

$tab = 'ads';
$filterMonth = date('Y-m');
$filterClient = '';
$filterCat = '';
$filterStatus = '';
$filterProject = '';
$filterApproval = '';
$expenses = [
    ['id'=>1,'expense_date'=>date('Y-m-d'),'expense_category'=>'Facebook Ads','project_name'=>'Test Project','description'=>'Test desc','billing_type'=>'client','client_name'=>'Acme Corp','cost_amount'=>100,'currency'=>'USD','exchange_rate'=>300,'markup_percentage'=>10,'total_billable'=>33000,'approval_status'=>'approved','approved_by'=>'Admin','status'=>'pending','receipt_path'=>null],
];
$clients = [['id'=>1,'company_name'=>"Acme Corp"]];
$categories = ['Facebook Ads','Instagram Ads','Google Ads'];
$currencies = ['LKR','USD','EUR','GBP','AUD','SGD'];
$rates = ['LKR'=>1,'USD'=>300,'EUR'=>330,'GBP'=>400,'AUD'=>210,'SGD'=>230];
$ratesJson = json_encode($rates);
$ratesUpdated = date('Y-m-d');
$editRow = null;
$db = null; // not touched on the 'ads' branch

include __DIR__ . '/ads_snippet.php';
