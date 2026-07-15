<?php
require_once 'config.php';
require_once 'includes/layout.php';
require_once 'includes/vendor_approval.php';
requireAdmin();
$db = getDB();

// Read-only report/lookup actions — these run immediately (no confirm-card round trip),
// since viewing a report can't change any data. Returns null if $type isn't one of these.
function handleReadOnlyAction($db, $type, $d, $sym) {
    if ($type === 'get_report') {
        $m2 = $d['month'] ?? date('Y-m');
        $r  = $db->prepare("SELECT COALESCE(SUM(total),0) FROM invoices WHERE DATE_FORMAT(issue_date,'%Y-%m')=? AND status!='cancelled' AND invoice_type='invoice'"); $r->execute([$m2]); $r=$r->fetchColumn();
        $e  = $db->prepare("SELECT COALESCE(SUM(cost_amount),0) FROM expenses WHERE billing_month=? AND billing_type IN ('internal','client','shared')"); $e->execute([$m2]); $e=$e->fetchColumn();
        $s  = $db->prepare("SELECT COALESCE(SUM(final_salary),0) FROM payroll WHERE month=?"); $s->execute([$m2]); $s=$s->fetchColumn();
        $msg = "📊 **".date('F Y',strtotime($m2.'-01'))." Report**\n💰 Revenue: {$sym} ".number_format($r,2)."\n📊 Expenses: {$sym} ".number_format($e,2)."\n👥 Salaries: {$sym} ".number_format($s,2)."\n📈 Net Profit: {$sym} ".number_format($r-$e-$s,2);
        return ['message' => $msg, 'link' => SITE_URL.'/reports.php'];
    }

    if ($type === 'get_expenses_by_client') {
        $isAll = ($d['month'] ?? '') === 'all';
        $m2    = $d['month'] ?? date('Y-m');
        $sql   = "SELECT COALESCE(NULLIF(client_name,''),'Internal') as client, COUNT(*) as cnt,
                         SUM(cost_amount*exchange_rate) as cost, SUM(total_billable) as billable
                  FROM expenses" . ($isAll ? "" : " WHERE billing_month=?") . " GROUP BY client ORDER BY billable DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($isAll ? [] : [$m2]);
        $rows = $stmt->fetchAll();
        $period = $isAll ? 'All Time' : date('F Y', strtotime($m2.'-01'));
        if (!$rows) return ['message' => "No expenses found for {$period}.", 'link' => SITE_URL.'/expenses.php'];
        $msg = "🧾 **Expenses by Client — {$period}**\n\n";
        foreach ($rows as $row) {
            $msg .= "**{$row['client']}** — {$sym} ".number_format($row['billable'],2)." billable ({$row['cnt']} expense".($row['cnt']==1?'':'s').", {$sym} ".number_format($row['cost'],2)." cost)\n";
        }
        return ['message' => trim($msg), 'link' => SITE_URL.'/expenses.php' . ($isAll ? '' : '?month='.$m2)];
    }

    if ($type === 'get_monthly_expenses') {
        $m2     = $d['month'] ?? date('Y-m');
        $client = trim($d['client_name'] ?? '');
        $period = date('F Y', strtotime($m2.'-01'));

        if ($client) {
            $rows = $db->prepare("SELECT expense_date, expense_category, description, cost_amount, exchange_rate, total_billable
                                   FROM expenses WHERE billing_month=? AND client_name LIKE ? ORDER BY expense_date");
            $rows->execute([$m2, "%{$client}%"]);
            $rows = $rows->fetchAll();
            if (!$rows) return ['message' => "No expenses found for \"{$client}\" in {$period}.", 'link' => SITE_URL.'/expenses.php?month='.$m2.'&client='.urlencode($client)];
            $total = array_sum(array_column($rows, 'total_billable'));
            $msg = "💰 **{$client} — Expenses for {$period}**\n\n";
            foreach ($rows as $r) {
                $date = date('d M', strtotime($r['expense_date']));
                $msg .= "- {$date}: **{$r['expense_category']}** — {$sym} ".number_format($r['total_billable'],2).($r['description'] ? " ({$r['description']})" : '')."\n";
            }
            $msg .= "\n**Total: {$sym} ".number_format($total,2)."** (".count($rows)." record".(count($rows)===1?'':'s').")";
            return ['message' => $msg, 'link' => SITE_URL.'/expenses.php?month='.$m2.'&client='.urlencode($client)];
        }

        $t = $db->prepare("SELECT
                COALESCE(SUM(cost_amount*exchange_rate),0) as total_cost,
                COALESCE(SUM(CASE WHEN billing_type IN ('client','shared') THEN total_billable ELSE 0 END),0) as total_billable,
                COALESCE(SUM(CASE WHEN billing_type='internal' THEN cost_amount*exchange_rate ELSE 0 END),0) as internal_cost,
                COUNT(*) as cnt
              FROM expenses WHERE billing_month=?");
        $t->execute([$m2]);
        $t = $t->fetch();
        $msg = "💰 **Total Expenses — {$period}**\n\nTotal Cost: {$sym} ".number_format($t['total_cost'],2)."\nClient-Billable: {$sym} ".number_format($t['total_billable'],2)."\nInternal Costs: {$sym} ".number_format($t['internal_cost'],2)."\nRecords: {$t['cnt']}";
        return ['message' => $msg, 'link' => SITE_URL.'/expenses.php?month='.$m2];
    }

    if ($type === 'get_pending_invoices') {
        $client = trim($d['client_name'] ?? '');
        $sql = "SELECT i.invoice_number, i.total, i.due_date, i.status, c.id as client_id, c.company_name
                FROM invoices i JOIN clients c ON c.id=i.client_id
                WHERE i.invoice_type='invoice' AND i.status IN ('sent','overdue')";
        $params = [];
        if ($client) { $sql .= " AND c.company_name LIKE ?"; $params[] = "%{$client}%"; }
        $sql .= " ORDER BY i.due_date ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $label = $client ? " for \"{$client}\"" : '';
        if (!$rows) return ['message' => "✅ No outstanding invoices{$label} — everything is paid up!", 'link' => SITE_URL.'/invoices.php'];
        $total = array_sum(array_column($rows, 'total'));
        $msg = "📋 **Outstanding Invoices{$label}** (".count($rows).", total {$sym} ".number_format($total,2).")\n\n";
        foreach ($rows as $r) {
            $due   = $r['due_date'] ? date('d M Y', strtotime($r['due_date'])) : '—';
            $badge = $r['status'] === 'overdue' ? '⚠️ Overdue' : '📤 Sent';
            $msg  .= "**{$r['invoice_number']}** — {$r['company_name']} — {$sym} ".number_format($r['total'],2)." — Due {$due} ({$badge})\n";
        }
        $link = $client && count(array_unique(array_column($rows,'client_id'))) === 1
            ? SITE_URL.'/invoices.php?client='.$rows[0]['client_id']
            : SITE_URL.'/invoices.php';
        return ['message' => trim($msg), 'link' => $link];
    }

    if ($type === 'get_bank_reference') {
        $q = trim($d['query'] ?? '');
        if (!$q) return ['message' => "Please tell me the invoice number, project, or freelancer name to look up.", 'link' => null];
        $like = "%{$q}%";
        $rows = $db->prepare("SELECT fp.invoice_number, fp.project_name, fp.payment_amount, fp.payment_date, fp.bank_reference, f.freelancer_name
                               FROM freelance_payments fp JOIN freelancers f ON f.id=fp.freelancer_id
                               WHERE fp.invoice_number LIKE ? OR fp.project_name LIKE ? OR f.freelancer_name LIKE ?
                               ORDER BY fp.invoice_date DESC LIMIT 5");
        $rows->execute([$like, $like, $like]);
        $rows = $rows->fetchAll();
        if (!$rows) return ['message' => "No payment found matching \"{$q}\".", 'link' => SITE_URL.'/freelance.php?tab=payments'];
        $msg = "🏦 **Bank Reference Lookup — \"{$q}\"**\n\n";
        foreach ($rows as $r) {
            $ref = $r['bank_reference'] ?: 'Not set';
            $paidDate = $r['payment_date'] ? date('d M Y', strtotime($r['payment_date'])) : '—';
            $msg .= "**{$r['freelancer_name']}** — {$r['project_name']} ({$r['invoice_number']})\nAmount: {$sym} ".number_format($r['payment_amount'],2)." · Paid: {$paidDate} · Bank Ref: **{$ref}**\n\n";
        }
        return ['message' => trim($msg), 'link' => SITE_URL.'/freelance.php?tab=payments'];
    }

    return null;
}

// ── AJAX: handle chat message ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'chat') {
    header('Content-Type: application/json');

    $messages = json_decode($_POST['messages'] ?? '[]', true);
    $apiKey   = getSetting('anthropic_api_key', '');

    if (!$apiKey) {
        echo json_encode(['error' => 'No API key configured. Go to Settings → AI Assistant to add your Anthropic API key.']);
        exit;
    }

    // Build system context with live data
    $month       = date('Y-m');
    $sym         = getSetting('currency_symbol', 'Rs.');
    $companyName = getSetting('company_name', 'Creative Elements');
    $empCount    = $db->query("SELECT COUNT(*) FROM employees WHERE status='active'")->fetchColumn();
    $clientList  = $db->query("SELECT id, company_name, default_currency FROM clients WHERE status='active' ORDER BY company_name")->fetchAll();
    $clientNames = implode(', ', array_column($clientList, 'company_name'));
    $empList     = $db->query("SELECT id, full_name, monthly_salary, position FROM employees WHERE status='active' ORDER BY full_name")->fetchAll();
    $empNames    = implode(', ', array_map(fn($e) => "{$e['full_name']} ({$sym} ".number_format($e['monthly_salary'],2).")", $empList));
    $rev         = $db->prepare("SELECT COALESCE(SUM(total),0) FROM invoices WHERE DATE_FORMAT(issue_date,'%Y-%m')=? AND invoice_type='invoice' AND status!='cancelled'");
    $rev->execute([$month]); $rev = $rev->fetchColumn();
    $exp         = $db->prepare("SELECT COALESCE(SUM(cost_amount),0) FROM expenses WHERE billing_month=? AND billing_type IN ('internal','client','shared')");
    $exp->execute([$month]); $exp = $exp->fetchColumn();
    $sal         = $db->prepare("SELECT COALESCE(SUM(final_salary),0) FROM payroll WHERE month=?");
    $sal->execute([$month]); $sal = $sal->fetchColumn();
    $rateUSD     = getSetting('rate_usd_lkr', '325');
    $rateAUD     = getSetting('rate_aud_lkr', '215');

    $systemPrompt = "You are the AI assistant for {$companyName}'s internal payroll and business management system. You help the admin manage invoices, expenses, clients, payroll, and freelancers through natural language.\n\n## Current System State ({$month})\n- Currency: {$sym} (LKR). USD rate: {$rateUSD} LKR. AUD rate: {$rateAUD} LKR.\n- Active employees ({$empCount}): {$empNames}\n- Active clients: {$clientNames}\n- This month: Revenue {$sym} {$rev}, Expenses {$sym} {$exp}, Salaries {$sym} {$sal}\n\n## Your Role\nYou can perform these ACTIONS by returning a JSON block in your response.\n\n**WRITE ACTIONS** (create/change something — always shown to the admin as a card with Confirm/Cancel buttons first; nothing is saved until they click Confirm):\n1. create_invoice — Create a new invoice\n2. create_expense — Add a new expense\n3. create_client — Add a new client to the portal, with all their details\n4. create_payroll — Process payroll for an employee\n5. mark_invoice_paid — Mark an existing invoice as paid, found by client name, invoice number, and/or month (any combination the admin gives you)\n\n**READ-ONLY REPORT/LOOKUP ACTIONS** (these run immediately and show results right away — no confirmation needed, since viewing data can't change anything):\n6. get_report — Overall monthly summary (revenue, expenses, salaries, profit)\n7. get_expenses_by_client — Expenses grouped by client, with totals and a link to details\n8. get_monthly_expenses — Total expenses for a given month (cost, client-billable, internal)\n9. get_pending_invoices — List every outstanding (sent/overdue) invoice\n10. get_bank_reference — Look up the bank reference for a freelance payment by invoice number, project, or freelancer name\n\n11. none — Just answer/explain, no action\n\n## Response Format\nAlways respond in plain friendly English FIRST, then if an action is needed include EXACTLY ONE JSON block:\n```json\n{\"action\":\"create_invoice\",\"data\":{...}}\n```\n\n## Action Schemas\n**create_invoice:** {\"action\":\"create_invoice\",\"data\":{\"client_name\":\"Ford Mustang\",\"invoice_type\":\"invoice\",\"issue_date\":\"2026-06-11\",\"due_date\":\"2026-07-11\",\"billing_month\":\"2026-06\",\"currency\":\"USD\",\"status\":\"draft\",\"items\":[{\"desc\":\"Content Management\",\"subdesc\":\"June 2026\",\"qty\":1,\"price\":500}],\"discount_pct\":0,\"tax_pct\":0,\"notes\":\"Thank you\",\"terms\":\"Payment due 30 days\"}}\n\n**create_expense:** {\"action\":\"create_expense\",\"data\":{\"expense_date\":\"2026-06-11\",\"billing_month\":\"2026-06\",\"client_name\":\"Ford Mustang\",\"billing_type\":\"client\",\"expense_category\":\"Facebook Ads\",\"vendor\":\"Meta\",\"description\":\"June campaign\",\"cost_amount\":35,\"currency\":\"USD\",\"markup_percentage\":15,\"additional_fee\":0}}\n\n**create_client:** {\"action\":\"create_client\",\"data\":{\"company_name\":\"Ford Mustang\",\"contact_name\":\"John Smith\",\"email\":\"john@example.com\",\"phone\":\"+94 77 123 4567\",\"address\":\"123 Main St\",\"address_line2\":\"\",\"city\":\"Colombo\",\"country\":\"Sri Lanka\",\"vat_number\":\"\",\"industry\":\"Automotive\",\"notes\":\"\",\"default_currency\":\"USD\"}} — ask for whichever of these the admin hasn't given you before proposing it, but company_name is the only truly required field\n\n**create_payroll:** {\"action\":\"create_payroll\",\"data\":{\"employee_name\":\"Kasun Perera\",\"month\":\"2026-06\",\"bonus\":0,\"deductions\":0,\"advance\":0,\"payment_method\":\"bank_transfer\",\"notes\":\"\"}}\n\n**mark_invoice_paid:** {\"action\":\"mark_invoice_paid\",\"data\":{\"client_name\":\"Ford Mustang\",\"invoice_number\":\"\",\"month\":\"2026-06\",\"paid_date\":\"\"}} — fill in whichever of client_name/invoice_number/month the admin actually mentioned and leave the rest empty (at least one is required so the system can find the right invoice); paid_date defaults to today if left empty. If the system finds more than one matching invoice it will ask you to narrow it down — pass that back to the admin.\n\n**get_report:** {\"action\":\"get_report\",\"data\":{\"month\":\"2026-06\"}}\n\n**get_expenses_by_client:** {\"action\":\"get_expenses_by_client\",\"data\":{\"month\":\"2026-06\"}} — use \\\"month\\\":\\\"all\\\" if the admin wants all-time instead of one month\n\n**get_monthly_expenses:** {\"action\":\"get_monthly_expenses\",\"data\":{\"month\":\"2026-06\",\"client_name\":\"\"}} — omit or leave client_name empty for the overall monthly total; set it to get an itemized expense list + total for just that one client\n\n**get_pending_invoices:** {\"action\":\"get_pending_invoices\",\"data\":{\"client_name\":\"\"}} — omit or leave client_name empty to list all outstanding invoices; set it to filter to just that one client\n\n**get_bank_reference:** {\"action\":\"get_bank_reference\",\"data\":{\"query\":\"INV-2026-0004\"}} — query can be an invoice number, project name, or freelancer name\n\n## Important Rules\n- For WRITE actions: always confirm details before acting — show a summary and ask the admin to confirm. Never claim you've already created something; say you're proposing it for approval.\n- For READ-ONLY report/lookup actions: just include the JSON block, the system fills in and displays the actual data automatically — you don't need to fetch or state the numbers yourself\n- If client name is ambiguous, ask which one\n- The manual system still works — you are an ADDITIONAL way to do things, not a replacement\n- Separately from anything you propose, the system automatically surfaces pending vendor invoice submissions and staff expense requests as approval cards whenever the admin opens or uses this chat — you don't need to check for these yourself, just be aware they may appear and can explain them if asked\n- Be concise and friendly";

    // ── cURL API call (works on cPanel shared hosting) ──
    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 1024,
        'system'     => $systemPrompt,
        'messages'   => $messages,
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'anthropic-version: 2023-06-01',
            'x-api-key: ' . $apiKey,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$response || $curlErr) {
        echo json_encode(['error' => 'Connection failed: ' . ($curlErr ?: 'No response from API')]);
        exit;
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200 || isset($data['error'])) {
        $errMsg = $data['error']['message'] ?? "API error (HTTP {$httpCode})";
        // Common error hints
        if ($httpCode === 401) $errMsg .= ' — Check your API key in Settings.';
        if ($httpCode === 429) $errMsg .= ' — Rate limit hit. Wait a moment and try again.';
        if ($httpCode === 400) $errMsg .= ' — Invalid request format.';
        echo json_encode(['error' => $errMsg]);
        exit;
    }

    $text = $data['content'][0]['text'] ?? '';

    // Extract JSON action if present
    $actionData = null;
    if (preg_match('/```json\s*(\{.*?\})\s*```/s', $text, $m)) {
        $actionData = json_decode($m[1], true);
        $text = trim(preg_replace('/```json\s*\{.*?\}\s*```/s', '', $text));
    }

    // Read-only report/lookup actions run immediately — no confirm card needed
    $reportLink = null;
    if ($actionData) {
        $readOnly = handleReadOnlyAction($db, $actionData['action'] ?? '', $actionData['data'] ?? [], getSetting('currency_symbol','Rs.'));
        if ($readOnly !== null) {
            $text = trim($text . "\n\n" . $readOnly['message']);
            $reportLink = $readOnly['link'];
            $actionData = null;
        }
    }

    echo json_encode(['reply' => $text, 'action' => $actionData, 'link' => $reportLink, 'pendingApprovals' => getPendingApprovals($db)]);
    exit;
}

// ── AJAX: execute confirmed action ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'execute') {
    header('Content-Type: application/json');
    $payload = json_decode($_POST['payload'] ?? '{}', true);
    $type    = $payload['action'] ?? '';
    $d       = $payload['data'] ?? [];
    $sym     = getSetting('currency_symbol', 'Rs.');

    try {
        if ($type === 'create_invoice') {
            $cStmt = $db->prepare("SELECT id, default_currency FROM clients WHERE company_name LIKE ? AND status='active'");
            $cStmt->execute(['%'.$d['client_name'].'%']);
            $client = $cStmt->fetch();
            if (!$client) throw new Exception("Client '{$d['client_name']}' not found. Please add them to the Clients page first.");

            $currency = $d['currency'] ?? $client['default_currency'] ?? 'LKR';
            $rate     = $currency === 'LKR' ? 1.0 : (float)(getSetting('rate_'.strtolower($currency).'_lkr', '1') ?: 1);
            $type2    = $d['invoice_type'] ?? 'invoice';
            $prefix   = getSetting($type2==='invoice'?'invoice_prefix':'quote_prefix', $type2==='invoice'?'INV':'QUO');
            $year     = date('Y');
            $count    = $db->query("SELECT COUNT(*) FROM invoices WHERE invoice_type='{$type2}' AND YEAR(issue_date)={$year}")->fetchColumn();
            $invNo    = $prefix.'-'.$year.'-'.str_pad($count+1,4,'0',STR_PAD_LEFT);

            $subtotal = 0; $items = [];
            foreach (($d['items'] ?? []) as $item) {
                $qty    = (float)($item['qty'] ?? 1);
                $price  = (float)($item['price'] ?? 0);
                $amtLKR = round($qty * $price * $rate, 2);
                $subtotal += $amtLKR;
                $items[] = [$item['desc'], $item['subdesc'] ?? '', $qty, $price, $amtLKR];
            }
            $discAmt = round($subtotal * (float)($d['discount_pct']??0) / 100, 2);
            $taxAmt  = round(($subtotal - $discAmt) * (float)($d['tax_pct']??0) / 100, 2);
            $total   = round($subtotal - $discAmt + $taxAmt, 2);

            $db->prepare("INSERT INTO invoices (invoice_number,invoice_type,client_id,issue_date,due_date,billing_month,subtotal,discount_pct,discount_amt,tax_pct,tax_amt,total,inv_currency,inv_rate,status,notes,terms,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$invNo,$type2,$client['id'],$d['issue_date'],$d['due_date']??null,$d['billing_month']??null,$subtotal,0,$discAmt,0,$taxAmt,$total,$currency,$rate,$d['status']??'draft',trim($d['notes']??''),trim($d['terms']??''),'AI Assistant']);
            $invId = $db->lastInsertId();
            foreach ($items as [$desc,$sub,$qty,$price,$amt]) {
                $db->prepare("INSERT INTO invoice_items (invoice_id,item_type,description,quantity,unit_price,amount,sort_order) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$invId,'service',$desc.($sub?'|||'.$sub:''),$qty,$price,$amt,0]);
            }
            echo json_encode(['success'=>true,'message'=>"✅ Invoice **{$invNo}** created for {$d['client_name']} — {$sym} ".number_format($total,2),'link'=>SITE_URL.'/invoice_form.php?id='.$invId]);

        } elseif ($type === 'create_expense') {
            $cost    = (float)($d['cost_amount']??0);
            $markup  = (float)($d['markup_percentage']??0);
            $fee     = (float)($d['additional_fee']??0);
            $cur     = $d['currency']??'LKR';
            $rate    = $cur==='LKR' ? 1.0 : (float)(getSetting('rate_'.strtolower($cur).'_lkr','1')?:1);
            $costLKR = $cost * $rate;
            $total   = round($costLKR + ($costLKR * $markup / 100) + $fee, 2);
            $db->prepare("INSERT INTO expenses (expense_date,billing_month,client_name,billing_type,expense_category,description,cost_amount,currency,exchange_rate,markup_percentage,additional_fee,total_billable,approval_status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'approved','AI Assistant')")
               ->execute([$d['expense_date'],$d['billing_month'],$d['client_name']??null,$d['billing_type']??'internal',$d['expense_category'],$d['description']??'',$cost,$cur,$rate,$markup,$fee,$total]);
            echo json_encode(['success'=>true,'message'=>"✅ Expense added — **{$d['expense_category']}** {$cur} ".number_format($cost,2)." → Billable {$sym} ".number_format($total,2),'link'=>SITE_URL.'/expenses.php']);

        } elseif ($type === 'create_client') {
            $name = trim($d['company_name'] ?? '');
            if (!$name) throw new Exception('Company name is required.');
            $db->prepare("INSERT INTO clients (company_name,contact_name,email,phone,address,address_line2,city,country,vat_number,industry,status,notes,default_currency) VALUES (?,?,?,?,?,?,?,?,?,?,'active',?,?)")
               ->execute([
                   $name, trim($d['contact_name']??''), trim($d['email']??''), trim($d['phone']??''),
                   trim($d['address']??''), trim($d['address_line2']??''), trim($d['city']??''), trim($d['country']??'Sri Lanka'),
                   trim($d['vat_number']??''), trim($d['industry']??''), trim($d['notes']??''), $d['default_currency']??'LKR',
               ]);
            $cid = $db->lastInsertId();
            echo json_encode(['success'=>true,'message'=>"✅ Client **{$name}** added to the portal.",'link'=>SITE_URL.'/clients.php']);

        } elseif ($type === 'mark_invoice_paid') {
            $client   = trim($d['client_name'] ?? '');
            $invNo    = trim($d['invoice_number'] ?? '');
            $month    = trim($d['month'] ?? '');
            $paidDate = trim($d['paid_date'] ?? '') ?: date('Y-m-d');

            if (!$client && !$invNo && !$month) {
                throw new Exception('Please tell me the client name, invoice number, or month so I can find the right invoice.');
            }

            $sql = "SELECT i.id, i.invoice_number, i.total, c.company_name
                    FROM invoices i JOIN clients c ON c.id=i.client_id
                    WHERE i.invoice_type='invoice' AND i.status NOT IN ('paid','cancelled')";
            $params = [];
            if ($client) { $sql .= " AND c.company_name LIKE ?"; $params[] = "%{$client}%"; }
            if ($invNo)  { $sql .= " AND i.invoice_number LIKE ?"; $params[] = "%{$invNo}%"; }
            if ($month)  { $sql .= " AND (DATE_FORMAT(i.issue_date,'%Y-%m')=? OR i.billing_month=?)"; $params[] = $month; $params[] = $month; }

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $matches = $stmt->fetchAll();

            if (!$matches) {
                throw new Exception('No unpaid invoice found matching that description. Double-check the client name, month, or invoice number.');
            }
            if (count($matches) > 1) {
                $list = implode(', ', array_map(fn($m) => $m['invoice_number'].' ('.$m['company_name'].')', $matches));
                throw new Exception("Found more than one matching invoice: {$list}. Please give the exact invoice number.");
            }

            $inv = $matches[0];
            $db->prepare("UPDATE invoices SET status='paid', paid_date=? WHERE id=?")->execute([$paidDate, $inv['id']]);
            echo json_encode(['success'=>true,'message'=>"✅ Invoice **{$inv['invoice_number']}** for {$inv['company_name']} marked as paid — {$sym} ".number_format($inv['total'],2),'link'=>SITE_URL.'/invoice_form.php?id='.$inv['id']]);

        } elseif ($type === 'approve_vendor_submission') {
            $result = approveVendorSubmission($db, (int)($d['id']??0), 'AI Assistant');
            echo json_encode(['success'=>$result['success'],'message'=>($result['success']?'✅ ':'❌ ').$result['message'],'link'=>$result['success']?SITE_URL.'/expenses.php':null]);

        } elseif ($type === 'reject_vendor_submission') {
            $result = rejectVendorSubmission($db, (int)($d['id']??0), $d['reason']??'', 'AI Assistant');
            echo json_encode(['success'=>$result['success'],'message'=>($result['success']?'✅ ':'❌ ').$result['message']]);

        } elseif ($type === 'approve_expense_request') {
            $reqId = (int)($d['id']??0);
            $req = $db->prepare("SELECT * FROM expense_change_requests WHERE id=? AND status='pending'");
            $req->execute([$reqId]);
            $req = $req->fetch();
            if (!$req) throw new Exception('Request not found or already reviewed.');
            $p = json_decode($req['payload'], true) ?: [];
            if ($req['change_type'] === 'add') {
                $db->prepare("INSERT INTO expenses (expense_date,billing_month,client_name,billing_type,expense_category,project_name,description,cost_amount,currency,exchange_rate,markup_percentage,additional_fee,total_billable,status,notes,receipt_path,created_by,approval_status,approved_by,approved_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'approved',?,NOW())")
                   ->execute([$p['expense_date'],$p['billing_month'],$p['client_name'],$p['billing_type'],$p['expense_category'],$p['project_name']??'',$p['description'],$p['cost_amount'],$p['currency'],$p['exchange_rate'],$p['markup_percentage'],$p['additional_fee'],$p['total_billable'],$p['status'],$p['notes'],$p['receipt_path']??null,$req['requested_by'],'AI Assistant']);
            } elseif ($req['change_type'] === 'edit') {
                $db->prepare("UPDATE expenses SET expense_date=?,billing_month=?,client_name=?,billing_type=?,expense_category=?,project_name=?,description=?,cost_amount=?,currency=?,exchange_rate=?,markup_percentage=?,additional_fee=?,total_billable=?,status=?,notes=?,receipt_path=?,approval_status='approved',approved_by=?,approved_at=NOW() WHERE id=?")
                   ->execute([$p['expense_date'],$p['billing_month'],$p['client_name'],$p['billing_type'],$p['expense_category'],$p['project_name']??'',$p['description'],$p['cost_amount'],$p['currency'],$p['exchange_rate'],$p['markup_percentage'],$p['additional_fee'],$p['total_billable'],$p['status'],$p['notes'],$p['receipt_path']??null,'AI Assistant',$req['expense_id']]);
            } elseif ($req['change_type'] === 'delete') {
                $db->prepare("DELETE FROM expenses WHERE id=?")->execute([$req['expense_id']]);
            }
            $db->prepare("UPDATE expense_change_requests SET status='approved', reviewed_at=NOW() WHERE id=?")->execute([$reqId]);
            echo json_encode(['success'=>true,'message'=>'✅ Expense request approved and applied.','link'=>SITE_URL.'/expenses.php']);

        } elseif ($type === 'reject_expense_request') {
            $reqId = (int)($d['id']??0);
            $db->prepare("UPDATE expense_change_requests SET status='rejected', reviewed_at=NOW() WHERE id=? AND status='pending'")->execute([$reqId]);
            echo json_encode(['success'=>true,'message'=>'✅ Expense request rejected.']);

        } elseif ($type === 'create_payroll') {
            $empStmt = $db->prepare("SELECT * FROM employees WHERE full_name LIKE ? AND status='active'");
            $empStmt->execute(['%'.$d['employee_name'].'%']);
            $emp = $empStmt->fetch();
            if (!$emp) throw new Exception("Employee '{$d['employee_name']}' not found.");
            $base    = (float)$emp['monthly_salary'];
            $month2  = $d['month'] ?? date('Y-m');
            $comm    = $db->prepare("SELECT COALESCE(SUM(commission_amount),0) FROM commissions WHERE employee_id=? AND month=?"); $comm->execute([$emp['id'],$month2]); $comm=(float)$comm->fetchColumn();
            $allow   = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM allowances WHERE employee_id=? AND month=?"); $allow->execute([$emp['id'],$month2]); $allow=(float)$allow->fetchColumn();
            $bonus   = (float)($d['bonus']??0);
            $deduct  = (float)($d['deductions']??0);
            $advance = (float)($d['advance']??0);
            $final   = $base + $bonus + $allow + $comm - $deduct - $advance;
            $db->prepare("INSERT INTO payroll (employee_id,month,base_salary,bonus,deductions,advance_payment,total_allowances,total_commissions,final_salary,payment_status,payment_method,notes) VALUES (?,?,?,?,?,?,?,?,?,'pending',?,?) ON DUPLICATE KEY UPDATE bonus=VALUES(bonus),deductions=VALUES(deductions),total_allowances=VALUES(total_allowances),total_commissions=VALUES(total_commissions),final_salary=VALUES(final_salary)")
               ->execute([$emp['id'],$month2,$base,$bonus,$deduct,$advance,$allow,$comm,$final,$d['payment_method']??'bank_transfer',$d['notes']??'']);
            echo json_encode(['success'=>true,'message'=>"✅ Payroll processed for **{$emp['full_name']}** — Final: {$sym} ".number_format($final,2),'link'=>SITE_URL.'/payroll.php']);

        } elseif (($readOnly = handleReadOnlyAction($db, $type, $d, $sym)) !== null) {
            // Safety net: report/lookup types normally run inline during the chat turn
            // (see handleReadOnlyAction() call above), but handle them here too in case
            // this endpoint is ever hit directly with one of these action types.
            echo json_encode(['success'=>true,'message'=>$readOnly['message'],'link'=>$readOnly['link']]);
        } else {
            echo json_encode(['success'=>false,'message'=>'Unknown action.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>'❌ '.$e->getMessage()]);
    }
    exit;
}

$sym       = getSetting('currency_symbol', 'Rs.');
$hasApiKey = !empty(getSetting('anthropic_api_key', ''));
$month     = date('Y-m');
$rev       = $db->prepare("SELECT COALESCE(SUM(total),0) FROM invoices WHERE DATE_FORMAT(issue_date,'%Y-%m')=? AND status!='cancelled' AND invoice_type='invoice'"); $rev->execute([$month]); $rev=$rev->fetchColumn();
$sal       = $db->prepare("SELECT COALESCE(SUM(final_salary),0) FROM payroll WHERE month=?"); $sal->execute([$month]); $sal=$sal->fetchColumn();
$initialPending = getPendingApprovals($db);

pageHeader('AI Assistant');
?>

<style>
.chat-wrap { display:grid; grid-template-columns:1fr 300px; gap:18px; height:calc(100vh - 130px); min-height:520px; }
.chat-main { display:flex; flex-direction:column; background:var(--bg2); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; }
.chat-messages { flex:1; overflow-y:auto; padding:18px; display:flex; flex-direction:column; gap:12px; scroll-behavior:smooth; }
.chat-input-wrap { border-top:1px solid var(--border); padding:12px; display:flex; gap:8px; background:var(--bg2); }
.chat-input { flex:1; resize:none; background:var(--bg3); border:1px solid var(--border); color:var(--text); border-radius:10px; padding:10px 14px; font-family:Poppins,sans-serif; font-size:13px; line-height:1.5; }
.chat-input:focus { outline:none; border-color:var(--accent); }
.msg { display:flex; gap:10px; max-width:88%; }
.msg.user { align-self:flex-end; flex-direction:row-reverse; }
.msg.assistant { align-self:flex-start; }
.msg-avatar { width:30px; height:30px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:14px; flex-shrink:0; margin-top:2px; }
.msg.user .msg-avatar { background:var(--accent); }
.msg.assistant .msg-avatar { background:linear-gradient(135deg,#7c3aed,#3b82f6); }
.msg-bubble { padding:10px 14px; border-radius:12px; font-size:13px; line-height:1.65; }
.msg.user .msg-bubble { background:var(--accent); color:#fff; border-bottom-right-radius:4px; }
.msg.assistant .msg-bubble { background:var(--bg3); color:var(--text); border-bottom-left-radius:4px; }
.msg-bubble strong { font-weight:700; }
.action-card { background:rgba(59,130,246,.08); border:1px solid rgba(59,130,246,.2); border-radius:10px; padding:12px 14px; margin-top:8px; }
.action-title { font-weight:700; color:var(--accent); font-size:13px; margin-bottom:8px; }
.action-row { display:flex; justify-content:space-between; padding:3px 0; border-bottom:1px solid rgba(255,255,255,.05); font-size:12px; color:var(--text2); }
.action-row:last-of-type { border-bottom:none; }
.action-row strong { color:var(--text); }
.btn-confirm { background:var(--green); color:#fff; border:none; border-radius:7px; padding:7px 16px; font-size:12px; font-weight:700; cursor:pointer; margin-top:10px; }
.btn-cancel-ai { background:transparent; color:var(--text2); border:1px solid var(--border); border-radius:7px; padding:7px 12px; font-size:12px; cursor:pointer; margin-top:10px; margin-left:6px; }
.result-ok { background:rgba(0,196,140,.1); border:1px solid rgba(0,196,140,.25); border-radius:8px; padding:9px 12px; margin-top:8px; font-size:12.5px; color:var(--green); }
.result-ok a { color:var(--accent); }
.result-err { background:rgba(255,77,109,.1); border:1px solid rgba(255,77,109,.25); border-radius:8px; padding:9px 12px; margin-top:8px; font-size:12.5px; color:var(--red); }
.typing { display:flex; gap:4px; align-items:center; }
.typing span { width:7px; height:7px; background:var(--text2); border-radius:50%; animation:dot .9s infinite; }
.typing span:nth-child(2) { animation-delay:.15s; }
.typing span:nth-child(3) { animation-delay:.3s; }
@keyframes dot { 0%,60%,100%{transform:translateY(0)} 30%{transform:translateY(-6px)} }
.side-panel { display:flex; flex-direction:column; gap:14px; overflow-y:auto; }
.suggest-btn { background:var(--bg3); border:1px solid var(--border); color:var(--text2); border-radius:8px; padding:9px 12px; font-size:12px; text-align:left; cursor:pointer; transition:.15s; width:100%; font-family:Poppins,sans-serif; line-height:1.4; }
.suggest-btn:hover { border-color:var(--accent); color:var(--accent); background:rgba(59,130,246,.06); }
.no-key { background:rgba(245,166,35,.1); border:1px solid rgba(245,166,35,.3); border-radius:10px; padding:14px; font-size:13px; color:var(--yellow); margin-bottom:16px; }
@media(max-width:768px) { .chat-wrap { grid-template-columns:1fr; height:auto; } .side-panel { display:none; } }
</style>

<?php if (!$hasApiKey): ?>
<div class="no-key">
  <strong>⚙️ API Key Required</strong><br>
  Add your Anthropic API key in <a href="<?= SITE_URL ?>/settings.php#ai" style="color:var(--yellow);font-weight:700">Settings → AI Assistant</a>.
</div>
<?php endif; ?>

<div class="chat-wrap">
  <div class="chat-main">
    <div class="chat-messages" id="chatMessages">
      <div class="msg assistant">
        <div class="msg-avatar">🤖</div>
        <div class="msg-bubble">
          <strong>Hi! I'm your AI assistant for <?= h(getSetting('company_name','Creative Elements')) ?>.</strong><br><br>
          I can create invoices, log expenses, add clients, process payroll, and pull reports — all through natural language. Every action I propose shows up as a card for you to confirm before anything is saved. Your manual workflow is unchanged.<br><br>
          I'll also automatically flag pending vendor invoice submissions and staff expense requests here for you to approve or reject.<br><br>
          <?php if (!$hasApiKey): ?>⚠️ <em>Add your API key in Settings to activate me.</em><?php else: ?>Try asking me something! 👇<?php endif; ?>
        </div>
      </div>
    </div>
    <div class="chat-input-wrap">
      <textarea class="chat-input" id="chatInput" rows="2"
        placeholder="e.g. Create an invoice for Ford Mustang, USD 500 for content management"
        onkeydown="handleKey(event)"></textarea>
      <button class="btn btn-primary" onclick="sendMessage()" style="align-self:flex-end;padding:10px 18px" <?= !$hasApiKey?'disabled title="Add API key in Settings first"':'' ?>>
        Send ↑
      </button>
    </div>
  </div>

  <div class="side-panel">
    <div class="card" style="padding:14px">
      <div class="card-title" style="font-size:12px;margin-bottom:10px">💡 Try These</div>
      <?php foreach ([
        "📄 Create invoice for [client] USD 500 for content management June 2026",
        "💰 Add Facebook Ads expense for [client] USD 35, 15% markup",
        "🏢 Add a new client called [company name]",
        "👤 Process payroll for [employee] June 2026",
        "📊 Show me this month's revenue and expenses",
        "📋 Create a quotation for a new website",
        "💱 What is the current USD rate?",
      ] as $s): ?>
        <button class="suggest-btn" style="margin-bottom:5px" onclick="document.getElementById('chatInput').value=this.textContent.trim();document.getElementById('chatInput').focus()"><?= h($s) ?></button>
      <?php endforeach; ?>
    </div>

    <div class="card" style="padding:14px">
      <div class="card-title" style="font-size:12px;margin-bottom:8px">⚡ <?= date('F') ?> Stats</div>
      <div style="display:flex;flex-direction:column;gap:6px;font-size:12px;color:var(--text2)">
        <div style="display:flex;justify-content:space-between"><span>Revenue</span><strong style="color:var(--green)"><?= $sym ?> <?= number_format($rev,2) ?></strong></div>
        <div style="display:flex;justify-content:space-between"><span>Payroll</span><strong><?= $sym ?> <?= number_format($sal,2) ?></strong></div>
        <div style="display:flex;justify-content:space-between"><span>Employees</span><strong><?= $db->query("SELECT COUNT(*) FROM employees WHERE status='active'")->fetchColumn() ?></strong></div>
        <div style="display:flex;justify-content:space-between"><span>Clients</span><strong><?= $db->query("SELECT COUNT(*) FROM clients WHERE status='active'")->fetchColumn() ?></strong></div>
      </div>
    </div>

    <div class="card" style="padding:14px">
      <div class="card-title" style="font-size:12px;margin-bottom:8px">🔗 Quick Links</div>
      <?php foreach ([['📋','Invoices','invoices.php'],['💰','Expenses','expenses.php'],['👥','Payroll','payroll.php'],['🏢','Clients','clients.php'],['📊','Dashboard','dashboard.php']] as [$i,$l,$u]): ?>
      <a href="<?= SITE_URL ?>/<?= $u ?>" style="font-size:12px;color:var(--text2);text-decoration:none;padding:5px 0;display:flex;align-items:center;gap:6px;border-bottom:1px solid var(--border)"><?= $i ?> <?= $l ?> →</a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
let history = [];
let pending = null;

function handleKey(e) {
    if (e.key==='Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
}

function fmt(t) {
    return t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/\*\*(.*?)\*\*/g,'<strong>$1</strong>')
            .replace(/\n/g,'<br>');
}

function addMsg(role, html, extra='') {
    const wrap = document.getElementById('chatMessages');
    const d = document.createElement('div');
    d.className = `msg ${role}`;
    d.innerHTML = `<div class="msg-avatar">${role==='user'?'👤':'🤖'}</div><div class="msg-bubble">${html}${extra}</div>`;
    wrap.appendChild(d);
    wrap.scrollTop = wrap.scrollHeight;
    return d;
}

function showTyping() {
    const wrap = document.getElementById('chatMessages');
    const d = document.createElement('div');
    d.id = 'typing'; d.className = 'msg assistant';
    d.innerHTML = '<div class="msg-avatar">🤖</div><div class="msg-bubble"><div class="typing"><span></span><span></span><span></span></div></div>';
    wrap.appendChild(d); wrap.scrollTop = wrap.scrollHeight;
}
function hideTyping() { document.getElementById('typing')?.remove(); }

function buildCard(action) {
    if (!action) return '';
    const d = action.data || {};
    const titles = {create_invoice:'📄 Create Invoice',create_expense:'💰 Add Expense',create_client:'🏢 Add Client',create_payroll:'👥 Process Payroll',mark_invoice_paid:'✅ Mark Invoice Paid',get_report:'📊 Get Report'};
    let rows = '';
    if (action.action==='mark_invoice_paid') {
        rows = `<div class="action-row"><span>Client</span><strong>${d.client_name||'—'}</strong></div>
                <div class="action-row"><span>Invoice #</span><strong>${d.invoice_number||'—'}</strong></div>
                <div class="action-row"><span>Month</span><strong>${d.month||'—'}</strong></div>
                <div class="action-row"><span>Paid Date</span><strong>${d.paid_date||'Today'}</strong></div>`;
    } else if (action.action==='create_invoice') {
        rows = `<div class="action-row"><span>Client</span><strong>${d.client_name||'—'}</strong></div>
                <div class="action-row"><span>Currency</span><strong>${d.currency||'LKR'}</strong></div>
                <div class="action-row"><span>Date</span><strong>${d.issue_date||'—'}</strong></div>
                ${(d.items||[]).map(i=>`<div class="action-row"><span>${i.desc}</span><strong>${d.currency||'LKR'} ${parseFloat(i.price||0).toLocaleString('en',{minimumFractionDigits:2})}</strong></div>`).join('')}
                <div class="action-row"><span>Status</span><strong>${d.status||'draft'}</strong></div>`;
    } else if (action.action==='create_expense') {
        rows = `<div class="action-row"><span>Category</span><strong>${d.expense_category||'—'}</strong></div>
                <div class="action-row"><span>Client</span><strong>${d.client_name||'Internal'}</strong></div>
                <div class="action-row"><span>Amount</span><strong>${d.currency||'LKR'} ${parseFloat(d.cost_amount||0).toLocaleString('en',{minimumFractionDigits:2})}</strong></div>
                <div class="action-row"><span>Markup</span><strong>${d.markup_percentage||0}%</strong></div>`;
    } else if (action.action==='create_client') {
        rows = `<div class="action-row"><span>Company</span><strong>${d.company_name||'—'}</strong></div>
                <div class="action-row"><span>Contact</span><strong>${d.contact_name||'—'}</strong></div>
                <div class="action-row"><span>Email</span><strong>${d.email||'—'}</strong></div>
                <div class="action-row"><span>Currency</span><strong>${d.default_currency||'LKR'}</strong></div>`;
    } else if (action.action==='create_payroll') {
        rows = `<div class="action-row"><span>Employee</span><strong>${d.employee_name||'—'}</strong></div>
                <div class="action-row"><span>Month</span><strong>${d.month||'—'}</strong></div>
                <div class="action-row"><span>Bonus</span><strong>${d.bonus||0}</strong></div>`;
    }
    return `<div class="action-card"><div class="action-title">${titles[action.action]||action.action}</div>${rows}<div><button class="btn-confirm" onclick="execAction(this)">✅ Confirm & Execute</button><button class="btn-cancel-ai" onclick="cancelAction(this)">Cancel</button></div></div>`;
}

// ── Pending approvals: vendor invoice submissions & staff expense requests ──
// Surfaced automatically on page load and after every message, per admin's choice.
const shownApprovalIds = new Set();
function buildApprovalCard(item) {
    const key = item.type + ':' + item.id;
    const rows = item.rows.map(([label, val]) => `<div class="action-row"><span>${label}</span><strong>${val}</strong></div>`).join('');
    return `<div class="action-card" style="border-color:rgba(245,166,35,.35);background:rgba(245,166,35,.06)">
        <div class="action-title" style="color:var(--yellow)">${item.title}</div>
        ${rows}
        <div>
          <button class="btn-confirm" onclick="execApproval('${item.type}',${item.id},this)">✅ Approve</button>
          <button class="btn-cancel-ai" style="color:var(--red);border-color:rgba(255,77,109,.3)" onclick="execReject('${item.type}',${item.id},this)">❌ Reject</button>
        </div>
      </div>`;
}
function renderPendingApprovals(list) {
    (list || []).forEach(item => {
        const key = item.type + ':' + item.id;
        if (shownApprovalIds.has(key)) return;
        shownApprovalIds.add(key);
        addMsg('assistant', '🔔 <strong>New approval needed</strong>', buildApprovalCard(item));
    });
}
async function execApproval(type, id, btn) {
    const card = btn.closest('.action-card');
    card.querySelectorAll('button').forEach(b => b.disabled = true);
    const actionType = type === 'vendor_submission' ? 'approve_vendor_submission' : 'approve_expense_request';
    await runApprovalAction(actionType, id, card);
}
async function execReject(type, id, btn) {
    const reason = type === 'vendor_submission' ? (prompt('Reason for rejecting this invoice (optional):') || '') : '';
    const card = btn.closest('.action-card');
    card.querySelectorAll('button').forEach(b => b.disabled = true);
    const actionType = type === 'vendor_submission' ? 'reject_vendor_submission' : 'reject_expense_request';
    await runApprovalAction(actionType, id, card, reason);
}
async function runApprovalAction(actionType, id, card, reason='') {
    try {
        const fd = new FormData();
        fd.append('action','execute');
        fd.append('payload', JSON.stringify({action: actionType, data: {id, reason}}));
        const res  = await fetch(location.href, {method:'POST', body:fd});
        const data = await res.json();
        const cls  = data.success ? 'result-ok' : 'result-err';
        const link = data.link ? ` <a href="${data.link}" target="_blank">View →</a>` : '';
        card.insertAdjacentHTML('afterend', `<div class="${cls}">${fmt(data.message||'')}${link}</div>`);
        card.remove();
    } catch (e) {
        card.insertAdjacentHTML('afterend', `<div class="result-err">❌ Action failed. Please try again or use the manual page.</div>`);
    }
}

async function sendMessage() {
    const inp = document.getElementById('chatInput');
    const txt = inp.value.trim(); if (!txt) return;
    inp.value = '';
    addMsg('user', fmt(txt));
    history.push({role:'user', content:txt});
    showTyping();

    try {
        const fd = new FormData();
        fd.append('action','chat');
        fd.append('messages', JSON.stringify(history));
        const res  = await fetch(location.href, {method:'POST', body:fd});
        const data = await res.json();
        hideTyping();

        if (data.error) {
            addMsg('assistant', '❌ ' + fmt(data.error));
            history.push({role:'assistant', content:data.error});
            return;
        }

        const reply = data.reply || '';
        pending = data.action || null;
        const linkHtml = data.link ? `<div style="margin-top:8px"><a href="${data.link}" target="_blank" style="color:var(--accent)">View →</a></div>` : '';
        addMsg('assistant', fmt(reply), linkHtml + buildCard(pending));
        history.push({role:'assistant', content:reply});
        renderPendingApprovals(data.pendingApprovals);
    } catch(e) {
        hideTyping();
        addMsg('assistant', '❌ Network error. Please try again.');
    }
}

async function execAction(btn) {
    if (!pending) return;
    const card = btn ? btn.closest('.action-card') : document.querySelector('.action-card:last-of-type');
    card?.querySelectorAll('.btn-confirm,.btn-cancel-ai').forEach(b=>b.remove());
    const snap = pending; pending = null;
    showTyping();
    try {
        const fd = new FormData();
        fd.append('action','execute');
        fd.append('payload', JSON.stringify(snap));
        const res  = await fetch(location.href, {method:'POST', body:fd});
        const data = await res.json();
        hideTyping();
        const cls  = data.success ? 'result-ok' : 'result-err';
        const link = data.link ? ` <a href="${data.link}" target="_blank">View →</a>` : '';
        addMsg('assistant', `<div class="${cls}">${fmt(data.message||'')}${link}</div>`);
        history.push({role:'assistant', content: data.message||''});
    } catch(e) {
        hideTyping();
        addMsg('assistant','❌ Execution failed. Please try manually.');
    }
}

function cancelAction(btn) {
    pending = null;
    const card = btn ? btn.closest('.action-card') : document.querySelector('.action-card:last-of-type');
    card?.querySelectorAll('.btn-confirm,.btn-cancel-ai').forEach(b=>b.remove());
    addMsg('assistant','Cancelled. Let me know if you need anything else.');
}

document.addEventListener('DOMContentLoaded', () => {
    renderPendingApprovals(<?= json_encode($initialPending) ?>);
});
</script>

<?php pageFooter(); ?>
