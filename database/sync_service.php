<?php
/**
 * Sevam Supabase & SQLite Synchronization Service
 * 
 * Provides robust two-way data persistence between Supabase (PostgreSQL Cloud)
 * and SQLite (local container instance).
 * Prevents data loss during Cloud Run container restarts, scale-to-zero, or redeployments.
 */

require_once __DIR__ . '/../php/supabase.php';

/**
 * Hydrate the local SQLite database from Supabase cloud.
 * Called on container boot and whenever local DB needs fresh sync.
 *
 * @param PDO $pdo
 * @param bool $force
 * @return array Sync statistics
 */
function sevam_hydrate_from_supabase($pdo, $force = false) {
    $syncLockFile = sys_get_temp_dir() . '/sevam_supabase_last_sync.txt';
    if (!$force && file_exists($syncLockFile)) {
        $lastSync = intval(@file_get_contents($syncLockFile));
        // Don't re-hydrate if synced less than 120 seconds ago
        if ((time() - $lastSync) < 120) {
            return ['status' => 'skipped', 'message' => 'Synced recently'];
        }
    }

    $stats = [
        'users' => 0,
        'providers' => 0,
        'groups' => 0,
        'listings' => 0,
        'requests' => 0,
        'messages' => 0,
        'errors' => []
    ];

    try {
        // 1. Fetch user credentials (password hashes) from Supabase actions
        $credMap = [];
        $credRes = supabase_request("actions?action=eq.user_credential&select=user_id,details", "GET");
        if ($credRes['success'] && is_array($credRes['response'])) {
            foreach ($credRes['response'] as $cr) {
                $uid = intval($cr['user_id'] ?? 0);
                if ($uid > 0 && !empty($cr['details']['password_hash'])) {
                    $credMap[$uid] = $cr['details']['password_hash'];
                }
            }
        }

        // Default bcrypt hashes for standard fallback
        $defaultProviderHash = password_hash('provider123', PASSWORD_BCRYPT);
        $defaultGroupHash = password_hash('group123', PASSWORD_BCRYPT);
        $defaultAdminHash = '$2y$10$lUuKXDPVotPJoLWFzXYWduUXFMAR3NM6Mz0oRhqc0lL.VjOyrQCaW'; // admin123

        // 2. Hydrate Users from Supabase
        $usersRes = supabase_request("users?select=*&order=id.asc", "GET");
        if ($usersRes['success'] && is_array($usersRes['response'])) {
            $userUpsert = $pdo->prepare("
                INSERT INTO users (id, username, email, password, role, status, created_at)
                VALUES (:id, :username, :email, :password, :role, :status, :created_at)
                ON CONFLICT(id) DO UPDATE SET
                    username = excluded.username,
                    email = excluded.email,
                    role = excluded.role,
                    status = excluded.status
            ");

            foreach ($usersRes['response'] as $u) {
                $uid = intval($u['id'] ?? 0);
                if ($uid <= 0) continue;

                // Check if user already exists locally and has a password
                $existing = $pdo->query("SELECT password FROM users WHERE id = {$uid}")->fetch();
                $pwdHash = $existing['password'] ?? null;

                if (!$pwdHash) {
                    if (isset($credMap[$uid])) {
                        $pwdHash = $credMap[$uid];
                    } elseif ($u['role'] === 'admin') {
                        $pwdHash = $defaultAdminHash;
                    } elseif ($u['role'] === 'provider') {
                        $pwdHash = $defaultProviderHash;
                    } else {
                        $pwdHash = $defaultGroupHash;
                    }
                }

                $createdAt = !empty($u['created_at']) ? date('Y-m-d H:i:s', strtotime($u['created_at'])) : date('Y-m-d H:i:s');
                $userUpsert->execute([
                    ':id' => $uid,
                    ':username' => $u['username'] ?? '',
                    ':email' => $u['email'] ?? '',
                    ':password' => $pwdHash,
                    ':role' => $u['role'] ?? 'guest',
                    ':status' => $u['status'] ?? 'active',
                    ':created_at' => $createdAt
                ]);
                $stats['users']++;
            }
        }

        // 3. Hydrate Food Providers from Supabase
        $provRes = supabase_request("food_providers?select=*&order=id.asc", "GET");
        if ($provRes['success'] && is_array($provRes['response'])) {
            $provUpsert = $pdo->prepare("
                INSERT INTO food_providers (id, user_id, business_name, owner_name, phone, address, city, state, business_type, fssai_status, fssai_number, created_at)
                VALUES (:id, :user_id, :business_name, :owner_name, :phone, :address, :city, :state, :business_type, :fssai_status, :fssai_number, :created_at)
                ON CONFLICT(id) DO UPDATE SET
                    user_id = excluded.user_id,
                    business_name = excluded.business_name,
                    owner_name = excluded.owner_name,
                    phone = excluded.phone,
                    address = excluded.address,
                    city = excluded.city,
                    state = excluded.state,
                    business_type = excluded.business_type,
                    fssai_status = excluded.fssai_status,
                    fssai_number = excluded.fssai_number
            ");

            foreach ($provRes['response'] as $p) {
                $pid = intval($p['id'] ?? 0);
                $pUserId = intval($p['user_id'] ?? 0);
                if ($pUserId <= 0) continue;

                // Ensure unique positive ID for SQLite
                if ($pid <= 0) {
                    $pid = $pUserId;
                }

                $createdAt = !empty($p['created_at']) ? date('Y-m-d H:i:s', strtotime($p['created_at'])) : date('Y-m-d H:i:s');
                $provUpsert->execute([
                    ':id' => $pid,
                    ':user_id' => $pUserId,
                    ':business_name' => $p['business_name'] ?? 'Provider Kitchen',
                    ':owner_name' => $p['owner_name'] ?? 'Owner',
                    ':phone' => $p['phone'] ?? '',
                    ':address' => $p['address'] ?? '',
                    ':city' => $p['city'] ?? '',
                    ':state' => $p['state'] ?? '',
                    ':business_type' => $p['business_type'] ?? 'Restaurant',
                    ':fssai_status' => $p['fssai_status'] ?? 'Not Certified',
                    ':fssai_number' => $p['fssai_number'] ?? null,
                    ':created_at' => $createdAt
                ]);
                $stats['providers']++;
            }
        }

        // 4. Hydrate Social Working Groups from Supabase
        $grpRes = supabase_request("social_working_groups?select=*&order=id.asc", "GET");
        if ($grpRes['success'] && is_array($grpRes['response'])) {
            $grpUpsert = $pdo->prepare("
                INSERT INTO social_working_groups (id, user_id, group_name, representative_name, phone, address, city, state, organization_type, ngo_status, darpan_id, created_at)
                VALUES (:id, :user_id, :group_name, :representative_name, :phone, :address, :city, :state, :organization_type, 'Registered', :darpan_id, :created_at)
                ON CONFLICT(id) DO UPDATE SET
                    user_id = excluded.user_id,
                    group_name = excluded.group_name,
                    representative_name = excluded.representative_name,
                    phone = excluded.phone,
                    address = excluded.address,
                    city = excluded.city,
                    state = excluded.state,
                    organization_type = excluded.organization_type,
                    darpan_id = excluded.darpan_id
            ");

            foreach ($grpRes['response'] as $g) {
                $gid = intval($g['id'] ?? 0);
                $gUserId = intval($g['user_id'] ?? 0);
                if ($gUserId <= 0) continue;

                if ($gid <= 0) {
                    $gid = $gUserId;
                }

                $createdAt = !empty($g['created_at']) ? date('Y-m-d H:i:s', strtotime($g['created_at'])) : date('Y-m-d H:i:s');
                $grpUpsert->execute([
                    ':id' => $gid,
                    ':user_id' => $gUserId,
                    ':group_name' => $g['group_name'] ?? 'Social Group',
                    ':representative_name' => $g['representative_name'] ?? 'Representative',
                    ':phone' => $g['phone'] ?? '',
                    ':address' => $g['address'] ?? '',
                    ':city' => $g['city'] ?? '',
                    ':state' => $g['state'] ?? '',
                    ':organization_type' => $g['organization_type'] ?? 'Registered NGO',
                    ':darpan_id' => $g['darpan_id'] ?? null,
                    ':created_at' => $createdAt
                ]);
                $stats['groups']++;
            }
        }

        // 5. Hydrate Food Listings from Supabase
        $listRes = supabase_request("food_listings?select=*&order=id.asc", "GET");
        if ($listRes['success'] && is_array($listRes['response'])) {
            $listUpsert = $pdo->prepare("
                INSERT INTO food_listings (id, provider_id, category_id, food_name, food_type, quantity, available_quantity, quantity_unit, price, prep_date, available_date, available_start_time, available_end_time, expiry_date, expiry_time, food_description, image_url, pickup_info, status, created_at)
                VALUES (:id, :provider_id, :category_id, :food_name, :food_type, :quantity, :available_quantity, :quantity_unit, :price, :prep_date, :available_date, :available_start_time, :available_end_time, :expiry_date, :expiry_time, :food_description, :image_url, :pickup_info, :status, :created_at)
                ON CONFLICT(id) DO UPDATE SET
                    provider_id = excluded.provider_id,
                    food_name = excluded.food_name,
                    food_type = excluded.food_type,
                    quantity = excluded.quantity,
                    available_quantity = excluded.available_quantity,
                    quantity_unit = excluded.quantity_unit,
                    price = excluded.price,
                    available_date = excluded.available_date,
                    expiry_date = excluded.expiry_date,
                    status = excluded.status
            ");

            foreach ($listRes['response'] as $l) {
                $lid = intval($l['id'] ?? 0);
                if ($lid <= 0) continue;

                $createdAt = !empty($l['created_at']) ? date('Y-m-d H:i:s', strtotime($l['created_at'])) : date('Y-m-d H:i:s');
                $listUpsert->execute([
                    ':id' => $lid,
                    ':provider_id' => intval($l['provider_id'] ?? 1),
                    ':category_id' => intval($l['category_id'] ?? 1),
                    ':food_name' => $l['food_name'] ?? 'Surplus Meal',
                    ':food_type' => $l['food_type'] ?? 'Veg',
                    ':quantity' => floatval($l['quantity'] ?? 0),
                    ':available_quantity' => floatval($l['available_quantity'] ?? 0),
                    ':quantity_unit' => $l['quantity_unit'] ?? 'kg',
                    ':price' => floatval($l['price'] ?? 0),
                    ':prep_date' => $l['available_date'] ?? date('Y-m-d'),
                    ':available_date' => $l['available_date'] ?? date('Y-m-d'),
                    ':available_start_time' => $l['available_start_time'] ?? '17:00:00',
                    ':available_end_time' => $l['available_end_time'] ?? '21:00:00',
                    ':expiry_date' => $l['expiry_date'] ?? date('Y-m-d'),
                    ':expiry_time' => $l['expiry_time'] ?? '23:59:00',
                    ':food_description' => $l['food_name'] ?? '',
                    ':image_url' => (($l['food_type'] ?? 'Veg') === 'Veg') ? 'images/provider_kitchen.jpg' : 'images/community_distrib.jpg',
                    ':pickup_info' => !empty($l['pickup_info']) ? $l['pickup_info'] : 'Pickup at provider location',
                    ':status' => $l['status'] ?? 'Available',
                    ':created_at' => $createdAt
                ]);
                $stats['listings']++;
            }
        }

        // 6. Hydrate Food Requests from Supabase
        $reqRes = supabase_request("food_requests?select=*&order=id.asc", "GET");
        if ($reqRes['success'] && is_array($reqRes['response'])) {
            $reqUpsert = $pdo->prepare("
                INSERT INTO food_requests (id, food_id, group_id, requested_quantity, requested_date, requested_time, message, status, created_at, updated_at)
                VALUES (:id, :food_id, :group_id, :requested_quantity, :requested_date, :requested_time, :message, :status, :created_at, :updated_at)
                ON CONFLICT(id) DO UPDATE SET
                    requested_quantity = excluded.requested_quantity,
                    status = excluded.status,
                    message = excluded.message
            ");

            foreach ($reqRes['response'] as $r) {
                $rid = intval($r['id'] ?? 0);
                if ($rid <= 0) continue;

                $createdAt = !empty($r['created_at']) ? date('Y-m-d H:i:s', strtotime($r['created_at'])) : date('Y-m-d H:i:s');
                $reqUpsert->execute([
                    ':id' => $rid,
                    ':food_id' => intval($r['food_id'] ?? 1),
                    ':group_id' => intval($r['group_id'] ?? 1),
                    ':requested_quantity' => floatval($r['requested_quantity'] ?? 0),
                    ':requested_date' => $r['requested_date'] ?? date('Y-m-d'),
                    ':requested_time' => $r['requested_time'] ?? '18:00:00',
                    ':message' => $r['message'] ?? '',
                    ':status' => $r['status'] ?? 'Pending',
                    ':created_at' => $createdAt,
                    ':updated_at' => $createdAt
                ]);
                $stats['requests']++;
            }
        }

        // 7. Update SQLite AUTOINCREMENT sequence counters to stay ahead of Supabase IDs
        $tablesWithSeq = [
            'users' => 'id',
            'food_providers' => 'id',
            'social_working_groups' => 'id',
            'food_listings' => 'id',
            'food_requests' => 'id'
        ];

        foreach ($tablesWithSeq as $tbl => $col) {
            $maxId = intval($pdo->query("SELECT MAX({$col}) FROM {$tbl}")->fetchColumn());
            if ($maxId > 0) {
                $pdo->exec("INSERT OR REPLACE INTO sqlite_sequence (name, seq) VALUES ('{$tbl}', {$maxId})");
            }
        }

        @file_put_contents($syncLockFile, strval(time()));

    } catch (Throwable $e) {
        $stats['errors'][] = $e->getMessage();
    }

    return $stats;
}

// If invoked from CLI directly
if (php_sapi_name() === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    require_once __DIR__ . '/../php/db.php';
    echo "Starting full synchronization from Supabase...\n";
    $result = sevam_hydrate_from_supabase($pdo, true);
    echo "Hydration completed:\n" . json_encode($result, JSON_PRETTY_PRINT) . "\n";
}
