<?php
/**
 * Mini oyun kozmetik kataloğu, sahiplik, kuşanım.
 *
 * Kozmetikler YALNIZCA görseldir: fizik, hız, hitbox, skor veya oyun
 * görünürlüğünü değiştirmez. Bu dosya hiçbir oyun parametresi okumaz.
 */
declare(strict_types=1);

const MG_LOADOUT_SLOTS = ['character', 'color', 'trail', 'frame'];

const MG_CATEGORY_LABELS = [
    'character' => 'Karakter',
    'color' => 'Renk paketi',
    'trail' => 'İz efekti',
    'frame' => 'Çerçeve',
];

function mg_catalog_seed(): array
{
    $path = __DIR__ . '/../../data/minigame/catalog.php';
    return is_file($path) ? (array)require $path : [];
}

function mg_normalize_item(array $item): array
{
    $visual = is_array($item['visual'] ?? null) ? $item['visual'] : [];
    return [
        'id' => preg_replace('/[^a-z0-9-]/', '', strtolower((string)($item['id'] ?? ''))) ?: 'item',
        'name' => trim((string)($item['name'] ?? 'Kozmetik')),
        'category' => in_array($item['category'] ?? '', MG_LOADOUT_SLOTS, true) ? $item['category'] : 'color',
        'description' => trim((string)($item['description'] ?? '')),
        'price' => max(0, (int)($item['price'] ?? 0)),
        'free' => (int)($item['free'] ?? 0) === 1 ? 1 : 0,
        'purchasable' => (int)($item['purchasable'] ?? 1) === 1 ? 1 : 0,
        'published' => (int)($item['published'] ?? 1) === 1 ? 1 : 0,
        'rarity' => in_array($item['rarity'] ?? '', ['common', 'rare', 'epic', 'champion'], true) ? $item['rarity'] : 'common',
        'visual' => [
            'body' => isset($visual['body']) && is_string($visual['body']) ? $visual['body'] : null,
            'primary' => mg_safe_color($visual['primary'] ?? '#c5f46f'),
            'secondary' => mg_safe_color($visual['secondary'] ?? '#1b152c'),
            'glow' => max(0.0, min(1.0, (float)($visual['glow'] ?? 0.4))),
        ],
        'order' => (int)($item['order'] ?? 100),
    ];
}

/** CSS/JS'e giden renk değerleri: yalnızca #rrggbb kabul edilir. */
function mg_safe_color($value): string
{
    $value = (string)$value;
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : '#c5f46f';
}

function mg_catalog(bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) {
        return $cache;
    }
    mg_ensure_storage();
    $stored = mg_read_json('catalog.json', []);
    if (!$stored || empty($stored['items'])) {
        $items = [];
        foreach (mg_catalog_seed() as $item) {
            $items[] = mg_normalize_item($item);
        }
        $stored = ['items' => $items, 'updated_utc' => mg_now_utc()];
        mg_write_json('catalog.json', $stored);
    }
    $items = [];
    foreach ($stored['items'] as $item) {
        $items[] = mg_normalize_item($item);
    }
    usort($items, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);
    $cache = $items;
    return $cache;
}

function mg_catalog_save(array $items, ?int $actorId = null): array
{
    $clean = [];
    $seen = [];
    foreach ($items as $item) {
        if (!is_array($item) || empty($item['id'])) {
            continue;
        }
        $normalized = mg_normalize_item($item);
        if (isset($seen[$normalized['id']])) {
            throw new RuntimeException('Aynı kozmetik kimliği birden fazla kez tanımlanamaz: ' . $normalized['id']);
        }
        $seen[$normalized['id']] = true;
        $clean[] = $normalized;
    }
    mg_write_json('catalog.json', ['items' => $clean, 'updated_utc' => mg_now_utc(), 'updated_by' => $actorId]);
    mg_audit($actorId, 'catalog.save', 'catalog', ['items' => count($clean)]);
    return $clean;
}

function mg_catalog_item(string $itemId): ?array
{
    foreach (mg_catalog(true) as $item) {
        if ($item['id'] === $itemId) {
            return $item;
        }
    }
    return null;
}

function mg_default_loadout(): array
{
    return ['character' => 'char-default', 'color' => '', 'trail' => '', 'frame' => ''];
}

/** Ücretsiz kozmetikler herkese ait sayılır; ayrıca satır tutulmaz. */
function mg_free_item_ids(): array
{
    $ids = [];
    foreach (mg_catalog() as $item) {
        if ($item['free'] === 1) {
            $ids[] = $item['id'];
        }
    }
    return $ids;
}

/** @return string[] sahip olunan kozmetik kimlikleri */
function mg_owned_item_ids(int $userId, ?array $data = null): array
{
    $owned = mg_free_item_ids();
    $data = $data ?? dg_read_data();
    foreach ($data['minigame_entitlements'] ?? [] as $row) {
        if ((int)($row['user_id'] ?? 0) === $userId) {
            $owned[] = (string)$row['item_id'];
        }
    }
    return array_values(array_unique($owned));
}

function mg_owns_item(int $userId, string $itemId, ?array $data = null): bool
{
    return in_array($itemId, mg_owned_item_ids($userId, $data), true);
}

function mg_loadout(int $userId, ?array $data = null): array
{
    $loadout = mg_default_loadout();
    $data = $data ?? dg_read_data();
    foreach ($data['minigame_loadouts'] ?? [] as $row) {
        if ((int)($row['user_id'] ?? 0) === $userId) {
            foreach (MG_LOADOUT_SLOTS as $slot) {
                $value = (string)($row[$slot] ?? '');
                if ($value !== '') {
                    $loadout[$slot] = $value;
                }
            }
        }
    }
    return $loadout;
}

/**
 * Kuşanım. Sahip olunmayan veya yanlış kategorideki eşya kuşanılamaz.
 * Delist edilmiş ama sahip olunan eşya kuşanılabilir (sahipler kaybetmez).
 */
function mg_equip_item(int $userId, string $slot, string $itemId): array
{
    if (!in_array($slot, MG_LOADOUT_SLOTS, true)) {
        return ['ok' => false, 'code' => 'bad_slot', 'error' => 'Geçersiz yuva.'];
    }
    $item = mg_catalog_item($itemId);
    if (!$item) {
        return ['ok' => false, 'code' => 'not_found', 'error' => 'Kozmetik bulunamadı.'];
    }
    if ($item['category'] !== $slot) {
        return ['ok' => false, 'code' => 'bad_category', 'error' => 'Bu kozmetik bu yuvaya takılamaz.'];
    }
    return dg_mutate(static function (array &$data) use ($userId, $slot, $itemId, $item): array {
        if (!mg_owns_item($userId, $itemId, $data)) {
            return ['ok' => false, 'code' => 'not_owned', 'error' => 'Bu kozmetiğe sahip değilsin.'];
        }
        $index = null;
        foreach ($data['minigame_loadouts'] ?? [] as $i => $row) {
            if ((int)($row['user_id'] ?? 0) === $userId) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            $data['minigame_loadouts'][] = [
                'id' => dg_next_id($data, 'minigame_loadouts'),
                'user_id' => $userId,
                'character' => mg_default_loadout()['character'],
                'color' => '',
                'trail' => '',
                'frame' => '',
                'updated_at' => dg_now(),
            ];
            $index = count($data['minigame_loadouts']) - 1;
        }
        $data['minigame_loadouts'][$index][$slot] = $itemId;
        $data['minigame_loadouts'][$index]['updated_at'] = dg_now();
        return ['ok' => true, 'item' => $item];
    });
}

function mg_unequip_item(int $userId, string $slot): array
{
    if (!in_array($slot, MG_LOADOUT_SLOTS, true)) {
        return ['ok' => false, 'code' => 'bad_slot', 'error' => 'Geçersiz yuva.'];
    }
    if ($slot === 'character') {
        return ['ok' => false, 'code' => 'required_slot', 'error' => 'Karakter yuvası boş bırakılamaz.'];
    }
    return dg_mutate(static function (array &$data) use ($userId, $slot): array {
        foreach ($data['minigame_loadouts'] ?? [] as $i => $row) {
            if ((int)($row['user_id'] ?? 0) === $userId) {
                $data['minigame_loadouts'][$i][$slot] = '';
                $data['minigame_loadouts'][$i]['updated_at'] = dg_now();
            }
        }
        return ['ok' => true];
    });
}

/**
 * Kuşanımdan türetilen görsel palet. Oyun fiziğine ETKİ ETMEZ.
 */
function mg_loadout_visuals(array $loadout): array
{
    $byId = [];
    foreach (mg_catalog(true) as $item) {
        $byId[$item['id']] = $item;
    }
    $pick = static function (string $id) use ($byId): ?array {
        return $id !== '' && isset($byId[$id]) ? $byId[$id] : null;
    };
    $character = $pick((string)($loadout['character'] ?? ''));
    $color = $pick((string)($loadout['color'] ?? ''));
    $trail = $pick((string)($loadout['trail'] ?? ''));
    $frame = $pick((string)($loadout['frame'] ?? ''));

    return [
        'character' => $character ? $character['id'] : 'char-default',
        'body' => $character && $character['visual']['body'] ? $character['visual']['body'] : 'runner',
        'primary' => mg_safe_color($color['visual']['primary'] ?? ($character['visual']['primary'] ?? '#c5f46f')),
        'secondary' => mg_safe_color($color['visual']['secondary'] ?? ($character['visual']['secondary'] ?? '#1b152c')),
        'glow' => (float)($color['visual']['glow'] ?? ($character['visual']['glow'] ?? 0.4)),
        'trail' => $trail ? $trail['id'] : '',
        'trail_color' => mg_safe_color($trail['visual']['primary'] ?? '#63f5e8'),
        'frame' => $frame ? $frame['id'] : '',
        'frame_color' => mg_safe_color($frame['visual']['primary'] ?? '#63f5e8'),
        'frame_rarity' => $frame ? $frame['rarity'] : 'common',
    ];
}

/** Şampiyon kozmetiği bir kez verilir (sezon kazananı). */
function mg_grant_champion(int $userId, int $seasonId, string $itemId = 'frame-champion'): array
{
    return dg_mutate(static function (array &$data) use ($userId, $seasonId, $itemId): array {
        foreach ($data['minigame_entitlements'] ?? [] as $row) {
            if ((int)($row['user_id'] ?? 0) === $userId && (string)$row['item_id'] === $itemId) {
                return ['ok' => true, 'already' => true];
            }
        }
        $data['minigame_entitlements'][] = [
            'id' => dg_next_id($data, 'minigame_entitlements'),
            'user_id' => $userId,
            'item_id' => $itemId,
            'source' => 'season:' . $seasonId,
            'acquired_at' => dg_now(),
            'acquired_utc' => mg_now_utc(),
        ];
        return ['ok' => true, 'already' => false];
    });
}
