<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json; charset=utf-8');

function dg_purchase_out(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    dg_purchase_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

if (!csrf_verify((string)($input['csrf'] ?? ''))) {
    dg_purchase_out(['ok' => false, 'error' => 'CSRF doğrulaması başarısız'], 403);
}
if (!is_logged_in()) dg_purchase_out(['ok' => false, 'error' => 'Giriş yapmalısın'], 401);

$user = current_user();
if (!$user) dg_purchase_out(['ok' => false, 'error' => 'Giriş yapmalısın'], 401);
if ((int)$user['is_banned'] === 1) dg_purchase_out(['ok' => false, 'error' => 'Hesabın engellenmiş'], 403);

$uid = (int)$user['id'];
$item_id = (int)($input['store_item_id'] ?? 0);
$method = (string)($input['payment_method'] ?? '');
if (!in_array($method, ['dcoin', 'cash'], true)) dg_purchase_out(['ok' => false, 'error' => 'Geçersiz ödeme yöntemi']);
if ($item_id <= 0) dg_purchase_out(['ok' => false, 'error' => 'Geçersiz ürün']);

try {
    $result = dg_mutate(function (array &$data) use ($uid, $item_id, $method): array {
        $userIndex = dg_index_of_id($data['users'], $uid);
        if ($userIndex === null) return ['ok' => false, 'error' => 'Kullanıcı bulunamadı'];
        if ((int)($data['users'][$userIndex]['is_banned'] ?? 0) === 1) {
            return ['ok' => false, 'error' => 'Hesabın engellenmiş'];
        }

        $itemIndex = dg_index_of_id($data['store_items'], $item_id);
        if ($itemIndex === null || (int)($data['store_items'][$itemIndex]['is_active'] ?? 0) !== 1) {
            return ['ok' => false, 'error' => 'Ürün bulunamadı'];
        }
        $item = $data['store_items'][$itemIndex];

        $stockMode = dg_store_stock_mode($item);
        $keyIndex = null;
        if ($stockMode === 'keys') {
            foreach ($data['store_keys'] as $i => $keyRow) {
                if ((int)($keyRow['store_item_id'] ?? 0) === $item_id && (int)($keyRow['is_used'] ?? 0) === 0) {
                    $keyIndex = (int)$i;
                    break;
                }
            }
            if ($keyIndex === null) return ['ok' => false, 'error' => 'Üzgünüz, bu ürün stokta kalmadı'];
        } elseif ((int)($item['stock'] ?? 0) <= 0) {
            return ['ok' => false, 'error' => 'Üzgünüz, bu ürün stokta kalmadı'];
        }

        $priceTl = (float)($item['price_tl'] ?? 0);
        $priceDcoin = (int)($item['price_dcoin'] ?? 0);
        $balance = (int)($data['users'][$userIndex]['dcoin_balance'] ?? 0);

        if ($method === 'dcoin') {
            if ($priceDcoin <= 0) return ['ok' => false, 'error' => 'Bu ürün D-Coin ile alınamıyor'];
            if ($balance < $priceDcoin) {
                return ['ok' => false, 'error' => "Yetersiz D-Coin. Gerekli: $priceDcoin, bakiyen: $balance"];
            }
            $data['users'][$userIndex]['dcoin_balance'] = $balance - $priceDcoin;
            $data['users'][$userIndex]['updated_at'] = dg_now();
        }

        $purchaseId = dg_next_id($data, 'purchases');
        $orderCode = 'DG-' . $purchaseId . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $storeKeyId = null;
        $delivered = '';
        $deliveryType = 'order_code';

        if ($stockMode === 'keys') {
            $key = $data['store_keys'][$keyIndex];
            $data['store_keys'][$keyIndex]['is_used'] = 1;
            $data['store_keys'][$keyIndex]['used_by'] = $uid;
            $data['store_keys'][$keyIndex]['used_at'] = dg_now();
            $storeKeyId = (int)$key['id'];
            $delivered = (string)$key['key_code'];
            $deliveryType = 'key';
        } else {
            // Eski mağaza ürünü için gerçek bir key uydurulmaz: bu kod,
            // sipariş/teslimat takibinde kullanılacak sipariş kodudur.
            $data['store_items'][$itemIndex]['stock'] = max(0, (int)($data['store_items'][$itemIndex]['stock'] ?? 0) - 1);
            $data['store_items'][$itemIndex]['updated_at'] = dg_now();
            $delivered = $orderCode;
        }

        $purchase = [
            'id' => $purchaseId,
            'user_id' => $uid,
            'store_item_id' => $item_id,
            'store_key_id' => $storeKeyId,
            'item_name' => (string)$item['name'],
            'payment_method' => $method,
            'price_paid_tl' => $method === 'cash' ? $priceTl : null,
            'price_paid_dcoin' => $method === 'dcoin' ? $priceDcoin : null,
            'delivered_key' => $delivered,
            'delivery_type' => $deliveryType,
            'order_code' => $orderCode,
            'created_at' => dg_now(),
        ];
        $data['purchases'][] = $purchase;
        dg_refresh_store_stock($data);

        $newBalance = (int)$data['users'][$userIndex]['dcoin_balance'];
        $out = [
            'ok' => true,
            'purchase_id' => $purchaseId,
            'order_code' => $orderCode,
            'payment_method' => $method,
            'item_name' => (string)$item['name'],
            'delivered_key' => $delivered,
            'delivery_type' => $deliveryType,
            'new_balance' => $newBalance,
        ];
        if ($method === 'dcoin') $out['price_paid_dcoin'] = $priceDcoin;
        else $out['price_paid_tl'] = $priceTl;
        return $out;
    });
} catch (Throwable $e) {
    error_log('[purchase] ' . $e->getMessage());
    dg_purchase_out(['ok' => false, 'error' => 'Satın alma sırasında hata oluştu'], 500);
}

if (!empty($result['ok'])) {
    $_SESSION['dcoin_balance'] = (int)$result['new_balance'];
}
dg_purchase_out($result, !empty($result['ok']) ? 200 : 400);
