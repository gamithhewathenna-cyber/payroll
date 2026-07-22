<?php
// Lazily generate (and persist) the secure token clients use to open an invoice
// without logging in — see invoice_view.php.
function getInvoiceAccessToken($db, $invoiceId) {
    $stmt = $db->prepare("SELECT access_token FROM invoices WHERE id=?");
    $stmt->execute([$invoiceId]);
    $token = $stmt->fetchColumn();
    if (!$token) {
        $token = bin2hex(random_bytes(20));
        $db->prepare("UPDATE invoices SET access_token=? WHERE id=?")->execute([$token, $invoiceId]);
    }
    return $token;
}
