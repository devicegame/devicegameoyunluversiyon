<?php
/**
 * Mini Oyun — başlangıç ayarları (SEED).
 *
 * ⚠ DEMO / PROTOTİP: Bu dosyadaki D-Coin tutarları, ödüller ve bütçeler
 * ÖRNEKTİR. Gerçek değerler işletmeci onayından sonra admin panelinden
 * girilmelidir. `economy.approved = 0` iken arayüz bunu açıkça etiketler ve
 * ödüllü yarışma (season) varsayılan olarak KAPALIDIR.
 *
 * Yayına alma kontrol listesi: MINI-OYUN.md → "Yayın öncesi onay".
 */
declare(strict_types=1);

return [
    'schema' => 2,

    // Bayraklar birbirinden bağımsızdır: ödülleri kapatmak oyunu kapatmaz.
    'flags' => [
        'game' => 1,          // sıralı (kayıtlı) turlar açık
        'practice' => 1,      // antrenman (kayıtsız) turlar açık
        'rewards' => 0,       // D-Coin ödemeleri KAPALI (tutarlar onaylanana kadar)
        'shop' => 0,          // kozmetik satışı KAPALI (fiyatlar onaylanana kadar)
        'leaderboard' => 1,   // sıralama tabloları açık
        'season' => 0,        // ödüllü haftalık yarışma KAPALI (onay gerekir)
    ],

    'limits' => [
        'max_ranked_runs_per_day' => 20, // 0 = sınırsız
        'daily_user_reward_cap' => 100,  // 0 = sınırsız
        'daily_global_budget' => 5000,   // 0 = sınırsız
        'run_ttl_seconds' => 180,        // koşu token'ı ömrü
        'min_valid_ticks' => 600,        // 10 sn altı tur: ödül yok, tabloya girmez
        'max_inputs' => 600,             // istek başına girdi sınırı
        'retention_days' => 30,          // koşu kaydı saklama
        'timing_tolerance_ticks' => 6,   // saat farkı toleransı
    ],

    // Günlük görevler: her biri günde BİR kez değerlendirilir.
    // metric: valid_runs | best_score | best_cells
    'tasks' => [
        [
            'id' => 'gunluk-tur',
            'title' => 'İlk sıçrama',
            'description' => 'Geçerli bir turu tamamla (en az 10 saniye).',
            'metric' => 'valid_runs',
            'target' => 1,
            'reward' => 15,
            'order' => 1,
            'enabled' => 1,
        ],
        [
            'id' => 'skor-800',
            'title' => '800 kulübü',
            'description' => 'Tek turda 800 puan topla.',
            'metric' => 'best_score',
            'target' => 800,
            'reward' => 25,
            'order' => 2,
            'enabled' => 1,
        ],
        [
            'id' => 'hucre-15',
            'title' => 'Hücre avcısı',
            'description' => 'Tek turda 15 enerji hücresi topla.',
            'metric' => 'best_cells',
            'target' => 15,
            'reward' => 20,
            'order' => 3,
            'enabled' => 1,
        ],
        [
            'id' => 'uc-tur',
            'title' => 'Isınma turu',
            'description' => 'Bugün 3 geçerli tur tamamla.',
            'metric' => 'valid_runs',
            'target' => 3,
            'reward' => 30,
            'order' => 4,
            'enabled' => 1,
        ],
    ],

    'economy' => [
        'approved' => 0,
        'note' => 'Prototip: D-Coin tutarları ve kozmetik fiyatları örnektir. İşletmeci onayından sonra güncellenmelidir.',
    ],

    'maintenance_note' => '',
];
