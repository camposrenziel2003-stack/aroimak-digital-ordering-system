<?php
include "../config.php";
header('Content-Type: application/json');

$response = ["success" => false];

try {
    $sql = "SELECT 
            o.id AS order_id,
            o.order_group_id,
            o.table_number,
            o.customer_name,
            o.status,
            o.takeout,
            o.created_at AS order_created_at,
            oi.id AS item_id,
            oi.item_name,
            oi.quantity,
            oi.price,
            oi.total,
            oi.spice_level,
            CASE oi.spice_level
                WHEN 0 THEN 'No Spice'
                WHEN 1 THEN 'Light'
                WHEN 2 THEN 'Moderate'
                WHEN 3 THEN 'Spicy'
                WHEN 4 THEN 'Extra'
                ELSE 'No Spice'
            END AS spice_text,
            oi.serving_size,
            oi.allergens AS item_allergens,
            oi.allergen_note AS item_allergen_note,
            oi.created_at AS item_created_at,
            oi.sent_to_kitchen,
            oi.added_type,
            oi.added_by,
            oi.item_status
        FROM orders o
        JOIN order_items oi ON o.order_group_id = oi.order_group_id
        WHERE DATE(o.created_at) = CURDATE() AND (o.archived IS NULL OR o.archived = 0)
        ORDER BY o.created_at ASC, oi.created_at ASC, oi.id ASC";

    $result = $conn->query($sql);
    if (!$result) {
        echo json_encode([
            'success' => false,
            'message' => 'SQL error',
            'sql' => $sql,
            'php_error' => $conn->error
        ]);
        exit;
    }

    $orders = [];
    $orderCreatedTs = [];
    $parentStatus = [];
    $parentInfo = [];

    // statuses considered "done" - do not resurrect these as new add-batches
    $doneStatuses = ['prepared','ready','served','completed'];

    // -- Step 1: Build cards. Additional items without parent will be grouped into a single card per group.
    while ($row = $result->fetch_assoc()) {
        $ogid = $row['order_group_id'];
        $order_ts = isset($row['order_created_at']) ? strtotime($row['order_created_at']) : null;
        if (!isset($orderCreatedTs[$ogid]) && $order_ts !== null) {
            $orderCreatedTs[$ogid] = $order_ts;
        }
        if (!isset($parentInfo[$ogid])) {
            $parentInfo[$ogid] = [
                'order_id' => (int)$row['order_id'],
                'table_number' => $row['table_number'],
                'customer_name' => $row['customer_name'],
                'takeout' => (int)$row['takeout'],
                'order_created_at' => $row['order_created_at'],
                'parent_status' => $row['status']
            ];
        }

        // Keep parent status up-to-date for this group
        $parentStatus[$ogid] = $row['status'];

        // NEW: If parent order is cancelled/closed, skip all rows for this order group so it won't appear in KDS
        $lowerParentStatus = strtolower(trim($row['status'] ?? ''));
        if (in_array($lowerParentStatus, ['canceled', 'cancelled', 'closed', 'cancel'], true)) {
            // skip any rows belonging to this cancelled/closed parent
            continue;
        }

        $itemCreatedAt = !empty($row['item_created_at']) ? strtotime($row['item_created_at']) : null;
        $sentToKitchenTs = !empty($row['sent_to_kitchen']) ? strtotime($row['sent_to_kitchen']) : null;
        $addedType = isset($row['added_type']) ? strtolower(trim($row['added_type'])) : '';
        $itemStatus = isset($row['item_status']) ? strtolower(trim($row['item_status'])) : 'new';

        $isAdditional = false;
        if ($itemCreatedAt !== null && isset($orderCreatedTs[$ogid]) && $itemCreatedAt > $orderCreatedTs[$ogid]) $isAdditional = true;
        // Skip weird rows: additional item not marked as additional and hasn't been sent_to_kitchen
        if ($isAdditional && !$sentToKitchenTs && $addedType !== 'additional') continue;

        // NEW: If this additional item is already done (prepared/ready/served/completed),
        // skip adding it to add-batches so it won't be resurrected when new additions arrive.
        if ($isAdditional && in_array($itemStatus, $doneStatuses, true)) {
            // item already processed — do not include in any new add-batch card
            continue;
        }

        $lowerParentStatus = strtolower(trim($parentStatus[$ogid] ?? ''));
        $treatAsMain = $isAdditional && ($lowerParentStatus === 'pending' || $lowerParentStatus === 'preparing');

        // Main? (parent still "active") or grouped additional?
        if ($isAdditional && !$treatAsMain && ($lowerParentStatus === 'completed' || $lowerParentStatus === 'canceled' || $lowerParentStatus === 'on hold')) {
            // All additional (na hindi pa served/completed) -- group into one card per group
            $groupKey = $ogid . '::add::active';
            $is_additional_batch = true;
            $cardCreatedAt = date('Y-m-d H:i:s', $itemCreatedAt ?: time()); // will fix to min below
        } elseif ($isAdditional && !$treatAsMain) {
            // Still normal batch (if parent is missing, these will be grouped later anyway)
            $batchTs = $sentToKitchenTs ?: $itemCreatedAt ?: time();
            $batchKey = date('YmdHis', $batchTs);
            $groupKey = $ogid . '::add::' . $batchKey;
            $is_additional_batch = true;
            $cardCreatedAt = date('Y-m-d H:i:s', $batchTs);
        } else {
            // main parent card
            $groupKey = $ogid . '::main';
            $is_additional_batch = false;
            $cardCreatedAt = $parentInfo[$ogid]['order_created_at'];
        }

        // Make card if not exists
        if (!isset($orders[$groupKey])) {
            $card_status = $parentInfo[$ogid]['parent_status'];
            if ($is_additional_batch) $card_status = 'Preparing';
            $orders[$groupKey] = [
                "card_key" => $groupKey,
                "order_id" => $parentInfo[$ogid]['order_id'],
                "order_group_id" => $ogid,
                "table_number" => $parentInfo[$ogid]['table_number'],
                "customer_name" => $parentInfo[$ogid]['customer_name'],
                "status" => $card_status,
                "parent_status" => $parentInfo[$ogid]['parent_status'],
                "takeout" => $parentInfo[$ogid]['takeout'],
                "created_at" => $cardCreatedAt,
                "items" => [],
                "is_additional_batch" => $is_additional_batch,
            ];
        }

        $orders[$groupKey]["items"][] = [
            "item_id" => (int)$row["item_id"],
            "item_name" => $row["item_name"],
            "quantity" => (int)$row["quantity"],
            "price" => (float)$row["price"],
            "total" => (float)$row["total"],
            "spice_level" => $row["spice_level"],
            "spice_text" => $row["spice_text"],
            "serving_size" => $row["serving_size"],
            "allergens" => $row["item_allergens"],
            "allergen_note" => $row["item_allergen_note"],
            "created_at" => $row["item_created_at"],
            "is_additional" => $isAdditional,
            "sent_to_kitchen" => $row['sent_to_kitchen'],
            "added_type" => $row['added_type'],
            "added_by" => $row['added_by'],
            "item_status" => $itemStatus,
            "origin_group" => $groupKey
        ];
    }

    // -- Step 2: Merge multiple add-batch cards into one when parent is gone.
    // For each group, if main parent is not present, collapse ALL ::add::* cards to ::add::active
    foreach ($orders as $gk => $card) {
        $ogid = $card['order_group_id'];
        $mainKey = $ogid . '::main';
        if (strpos($gk, $ogid . '::add::') === 0 && !isset($orders[$mainKey])) {
            // If not already ::add::active, gather all to one card
            if ($gk !== ($ogid . '::add::active')) {
                if (!isset($orders[$ogid . '::add::active'])) {
                    $orders[$ogid . '::add::active'] = $card;
                    $orders[$ogid . '::add::active']['items'] = [];
                }
                $orders[$ogid . '::add::active']['items'] = array_merge($orders[$ogid . '::add::active']['items'], $card['items']);
                unset($orders[$gk]);
            }
        }
    }
    // Set correct created_at for ::add::active card
    foreach ($orders as $gk => &$card) {
        if (strpos($gk, '::add::active') !== false && !empty($card['items'])) {
            $earliest = min(array_map(function($x){ return strtotime($x['created_at']); }, $card['items']));
            $card['created_at'] = date('Y-m-d H:i:s', $earliest);
        }
    }
    unset($card);

    // -- Step 3: Merge additional into main when parent is still active/pending/preparing
    $activeStatuses = ['pending','preparing'];
    $parents = [];
    foreach ($orders as $gk => $card) $parents[$card['order_group_id']] = true;
    $hideItemStatuses = ['prepared','served','completed'];

    foreach (array_keys($parents) as $parent) {
        $mainKey = $parent . '::main';
        if (!isset($orders[$mainKey])) continue;
        $mainCard = $orders[$mainKey];
        $mainStatusLower = strtolower(trim($mainCard['status'] ?? ''));
        $mainHasItems = !empty($mainCard['items']);
        $mainVisible = in_array($mainStatusLower, $activeStatuses) && $mainHasItems;
        if ($mainVisible) {
            foreach (array_keys($orders) as $gk) {
                if (strpos($gk, $parent . '::add::') === 0 && isset($orders[$gk])) {
                    $addedAny = false;
                    foreach ($orders[$gk]['items'] as $itm) {
                        $itmStatus = strtolower(trim($itm['item_status'] ?? ''));
                        if (in_array($itmStatus, $hideItemStatuses, true)) continue;
                        $orders[$mainKey]['items'][] = $itm;
                        $addedAny = true;
                    }
                    if ($addedAny) $orders[$mainKey]['has_additional'] = true;
                    unset($orders[$gk]);
                }
            }
        }
    }

    // -- Step 3.5: If parent is NOT active (not Pending/Preparing), do NOT show its main (::main) card.
    // This ensures that when the parent has left the kitchen (e.g. Ready/Completed), the main card is removed
    // and only additional cards (if any) remain visible as separate cards.
    foreach (array_keys($orders) as $gk) {
        if (substr($gk, -6) === '::main') {
            $lowerParent = strtolower(trim($orders[$gk]['parent_status'] ?? ''));
            if (!in_array($lowerParent, $activeStatuses, true)) {
                unset($orders[$gk]);
            }
        }
    }

    // Step 4: status for add::active card
    foreach ($orders as $gk => &$card) {
        if ($card['is_additional_batch']) {
            $allReady = true;
            foreach ($card['items'] as $itm) {
                $st = strtolower(trim($itm['item_status'] ?? 'new'));
                if ($st === 'new' || $st === '' || $st === 'preparing' || $st === 'pending') { $allReady = false; break; }
            }
            $card['status'] = $allReady ? 'Completed' : 'Preparing';
        }
    }
    unset($card);

    // Cleanup: Remove add-batch cards pag completed na lahat items.
    foreach (array_keys($orders) as $gk) {
        if (strpos($gk, '::add::') !== false && isset($orders[$gk])) {
            $card = $orders[$gk];
            $allDone = true;
            foreach ($card['items'] as $itm) {
                $st = strtolower(trim($itm['item_status'] ?? 'new'));
                if ($st === 'new' || $st === '' || $st === 'preparing' || $st === 'pending') $allDone = false;
            }
            if ($allDone) unset($orders[$gk]);
        }
    }

    // -- Output grouping
    $out = [];
    $byParent = [];
    foreach ($orders as $gk => $card) {
        $parent = $card['order_group_id'];
        $byParent[$parent][$gk] = $card;
    }

    foreach ($byParent as $parent => $cards) {
        if (isset($cards[$parent . '::main'])) {
            $out[] = $cards[$parent . '::main'];
            unset($cards[$parent . '::main']);
        }
        uasort($cards, function($a, $b) {
            $ta = strtotime($a['created_at'] ?? '1970-01-01 00:00:00');
            $tb = strtotime($b['created_at'] ?? '1970-01-01 00:00:00');
            return $ta <=> $tb;
        });
        foreach ($cards as $c) $out[] = $c;
    }

    $response["success"] = true;
    $response["data"] = $out;
} catch (Exception $e) {
    $response["error"] = $e->getMessage();
}

echo json_encode($response);
exit;
?>