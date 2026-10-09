<?php
/**
 * includes/recommend.php
 * ------------------------------------------------------------------
 * SMART WORKER ASSIGNMENT ENGINE
 *
 * Scores every worker for a given request using four weighted criteria
 * and returns a ranked recommendation list:
 *
 *   Distance Score      (max 40)  closer truck = better
 *   Availability Score  (max 25)  available >> busy >> offline
 *   Workload Score      (max 20)  prefer workers with spare capacity
 *   Compatibility Score (max 15)  team must handle this waste type
 *                                                  -----------
 *                                                  TOTAL 0 - 100
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/**
 * Does a team's declared waste-type list cover the requested category?
 * "all" means the team is a general purpose team.
 */
function team_handles_type(?string $teamWasteTypes, string $wasteType): bool
{
    $list = array_map('trim', explode(',', strtolower((string) $teamWasteTypes)));
    if (!$list || $list === ['']) {
        return true;
    }
    return in_array('all', $list, true) || in_array(strtolower($wasteType), $list, true);
}

/** Human label for the compatibility result. */
function compatibility_label(bool $ok, string $wasteType): array
{
    if ($ok) {
        return ['compatible' => true, 'note' => 'Team handles ' . waste_meta($wasteType)['label']];
    }
    return ['compatible' => false, 'note' => 'Team is NOT equipped for ' . waste_meta($wasteType)['label']];
}

/**
 * HARD ELIGIBILITY GATE.
 *
 * A worker/team is only eligible to be assigned when ALL of the following
 * hold. Any failure makes the team ineligible — it must never be scored into,
 * or assigned by, the recommendation engine:
 *
 *   1. The worker record is active (not removed / offline).
 *   2. The team is not offline.
 *   3. The team is active (not maintenance / inactive).
 *   4. The team is equipped for this waste type (mandatory equipment).
 *   5. A suitable, in-service vehicle exists when the load needs one.
 *   6. The worker has a free pickup slot (capacity).
 *
 * @return array{eligible:bool, reasons:string[]}
 */
function evaluate_worker_eligibility(array $w, array $request): array
{
    $waste = (string) ($request['waste_type'] ?? 'general');
    $qty   = (string) ($request['quantity'] ?? 'small');
    $label = waste_meta($waste)['label'];
    $reasons = [];

    // 1. Worker record retired.
    if (($w['status'] ?? '') === 'removed') {
        $reasons[] = 'Worker record is removed';
    }

    // 2. Team / worker offline.
    if (($w['status'] ?? '') === 'offline' || ($w['availability'] ?? '') === 'offline') {
        $reasons[] = 'Team is offline';
    }

    // 3. Service constraint: team must be active.
    $teamStatus = $w['team_status'] ?? null;
    if ($teamStatus !== null && $teamStatus !== '' && $teamStatus !== 'active') {
        $reasons[] = 'Team is currently ' . $teamStatus;
    }

    // 4. Mandatory equipment: waste-type capability is a HARD gate.
    if (!team_handles_type($w['team_waste_types'] ?? 'all', $waste)) {
        $reasons[] = 'Not equipped for ' . $label;
    }

    // 5a. A heavy load needs a heavy vehicle (equipment, not a score penalty).
    $needsHeavy   = in_array($qty, ['large', 'bulk'], true);
    $vehicleType  = strtolower((string) ($w['vehicle_type'] ?? ''));
    $vehicleHeavy = in_array($vehicleType, ['truck', 'tipper', 'tempo'], true);

    if (empty($w['vehicle_id'])) {
        if ($needsHeavy) {
            $reasons[] = 'No vehicle assigned for a ' . $qty . ' load';
        }
    } else {
        // 5b. The assigned vehicle must be in service.
        $vehicleStatus = $w['vehicle_status'] ?? null;
        if ($vehicleStatus !== null && in_array($vehicleStatus, ['retired', 'maintenance'], true)) {
            $reasons[] = 'Assigned vehicle is ' . $vehicleStatus;
        }
        if ($needsHeavy && !$vehicleHeavy) {
            $reasons[] = 'Needs a truck/tipper for a ' . $qty . ' load, has ' . ($vehicleType ?: 'no vehicle');
        }
    }

    // 6. Capacity: the worker needs at least one free pickup slot.
    $current = (int) ($w['current_load'] ?? 0);
    $maxLoad = (int) ($w['max_load'] ?? 0);
    if ($maxLoad > 0 && $current >= $maxLoad) {
        $reasons[] = 'No spare capacity (' . $current . '/' . $maxLoad . ' pickups)';
    }

    return ['eligible' => $reasons === [], 'reasons' => array_values(array_unique($reasons))];
}

/**
 * Rank the ELIGIBLE workers for a request. Ineligible teams are dropped
 * entirely — see recommend_workers_with_excluded() to also retrieve them
 * with the reason each was rejected.
 *
 * @param array  $request  waste_requests row
 * @param int    $limit    how many recommendations to return
 * @return array List of eligible worker rows with score + reason columns added.
 */
function recommend_workers(array $request, int $limit = 5): array
{
    return recommend_workers_with_excluded($request, $limit)['eligible'];
}

/**
 * Rank eligible workers AND report every excluded team with its reasons.
 *
 * @return array{eligible:array, excluded:array, eligible_total:int}
 */
function recommend_workers_with_excluded(array $request, int $limit = 5): array
{
    $rLat = (float) ($request['latitude'] ?? 0);
    $rLng = (float) ($request['longitude'] ?? 0);
    $waste = (string) ($request['waste_type'] ?? 'general');

    $workers = db_all(
        'SELECT w.*, u.full_name, u.phone AS user_phone, u.email,
                t.team_name, t.waste_types AS team_waste_types, t.status AS team_status,
                v.vehicle_number, v.vehicle_type, v.vehicle_status, v.capacity_kg
           FROM workers w
           JOIN users u         ON u.id = w.user_id
           LEFT JOIN teams   t  ON t.id = w.team_id
           LEFT JOIN vehicles v ON v.id = w.vehicle_id
          WHERE w.status <> "removed"
          ORDER BY w.id'
    );

    // Furthest worker distance is used to normalise the distance score.
    $maxDist = 1.0;
    $dists   = [];
    foreach ($workers as $w) {
        $d = ($rLat && $rLng && $w['latitude'] !== null && $w['longitude'] !== null)
            ? haversine_km($rLat, $rLng, (float) $w['latitude'], (float) $w['longitude'])
            : null;
        $dists[(int) $w['id']] = $d;
        if ($d !== null && $d > $maxDist) {
            $maxDist = $d;
        }
    }

    $eligible = [];
    $excluded = [];

    foreach ($workers as $w) {
        $wid  = (int) $w['id'];
        $dist = $dists[$wid];

        // --- Hard eligibility gate ----------------------------------------
        $gate = evaluate_worker_eligibility($w, $request);
        if (!$gate['eligible']) {
            $row = worker_row_with_score(
                $w, 0, $dist, 0, 0, 0,
                'Excluded: ' . implode('; ', $gate['reasons']),
                'danger', 0
            );
            $row['eligible']           = false;
            $row['ineligible_reasons'] = $gate['reasons'];
            $row['reason']             = implode('; ', $gate['reasons']);
            $excluded[] = $row;
            continue;
        }

        // --- 1. Distance (max 40) -----------------------------------------
        if ($dist === null) {
            $distanceScore = 15; // unknown location: give a neutral half score
            $distanceNote  = 'Worker GPS location unknown';
        } else {
            $distanceScore = (int) max(0, round(40 * (1 - ($dist / max($maxDist * 1.2, 1)))));
            $distanceNote  = number_format($dist, 1) . ' km away';
        }

        // --- 2. Availability (max 25) -------------------------------------
        $availabilityScore = ['available' => 25, 'busy' => 10, 'offline' => 0][$w['availability']] ?? 10;

        // --- 3. Workload (max 20) -----------------------------------------
        $current = (int) $w['current_load'];
        $maxLoad = (int) ($w['max_load'] ?: 5);
        $ratio   = $maxLoad > 0 ? $current / $maxLoad : 1.0;
        if ($ratio >= 1.0) {
            $workloadScore = 0;
            $workloadNote  = 'Fully booked (' . $current . '/' . $maxLoad . ')';
        } else {
            $workloadScore = (int) round(20 * (1 - $ratio));
            $workloadNote  = $current . '/' . $maxLoad . ' pickups pending';
        }

        // --- 4. Equipment (max 15) ----------------------------------------
        // Eligibility already guarantees the team handles this waste type and
        // (for heavy loads) has a heavy vehicle, so the component is always 15.
        $compatInfo  = compatibility_label(true, $waste);
        $compatScore = 15;

        // --- Total ---------------------------------------------------------
        $total = $distanceScore + $availabilityScore + $workloadScore + $compatScore;

        // Flag the shape of the recommendation for the UI badge.
        $badge = 'neutral';
        if ($total >= 75)      $badge = 'success';
        elseif ($total >= 55)  $badge = 'primary';
        elseif ($total >= 40)  $badge = 'warning';
        elseif ($total > 0)    $badge = 'danger';

        $row = worker_row_with_score(
            $w, $total, $dist, $distanceScore, $availabilityScore,
            $workloadScore, $compatInfo['note'], $badge, $compatScore
        );
        $row['eligible']           = true;
        $row['ineligible_reasons'] = [];
        $row['reason'] = 'Handles ' . waste_meta($waste)['label']
            . '; ' . ($dist !== null ? number_format($dist, 1) . ' km away' : 'location unknown')
            . '; ' . ucfirst((string) $w['availability'])
            . '; load ' . $current . '/' . $maxLoad;
        $eligible[] = $row;
    }

    // Sort eligible by score desc, then by distance asc.
    usort($eligible, function ($a, $b) {
        if ($a['score'] === $b['score']) {
            $da = $a['distance_km'] ?? 9999;
            $db = $b['distance_km'] ?? 9999;
            return $da <=> $db;
        }
        return $b['score'] <=> $a['score'];
    });

    return [
        'eligible'       => array_slice($eligible, 0, $limit),
        'excluded'       => $excluded,
        'eligible_total' => count($eligible),
    ];
}

/** Attach the computed fields to a worker row. */
function worker_row_with_score(array $w, int $score, ?float $dist, int $dScore, int $aScore, int $wlScore, string $compatNote, string $badge, int $compatScore = 0): array
{
    $w['score']             = $score;
    $w['distance_km']       = $dist;
    $w['distance_score']    = $dScore;
    $w['availability_score'] = $aScore;
    $w['workload_score']    = $wlScore;
    $w['compat_score']      = $compatScore;
    $w['compat_note']       = $compatNote;
    $w['compatibility_note'] = $compatNote;
    $w['badge']             = $badge;
    $w['eta_minutes']       = $dist !== null ? estimate_drive_minutes($dist) : null;
    // A flat breakdown so the "why this team?" modal never has to guess.
    $w['breakdown'] = [
        ['label' => 'Distance',    'points' => $dScore,   'max' => 40,
         'note' => $dist !== null ? round($dist, 2) . ' km away' : 'no GPS fix yet'],
        ['label' => 'Availability', 'points' => $aScore,   'max' => 25],
        ['label' => 'Workload',    'points' => $wlScore,  'max' => 20],
        ['label' => 'Compatibility','points' => $compatScore, 'max' => 15, 'note' => $compatNote],
    ];
    return $w;
}

/**
 * When a worker is assigned, the worker load goes up and the status
 * flips to busy once the cap is reached.
 */
function bump_worker_load(int $workerId, int $delta = 1): void
{
    // MySQL evaluates SET assignments left to right, so the flag columns must
    // be computed *before* current_load moves — otherwise the delta is counted
    // twice.
    db_query(
        'UPDATE workers
            SET status       = IF(
                                    status = "available"
                                    AND GREATEST(0, current_load + ?) >= GREATEST(1, IFNULL(max_load, 5)),
                                    "busy", status),
                availability = IF(
                                    status = "available"
                                    AND GREATEST(0, current_load + ?) >= GREATEST(1, IFNULL(max_load, 5)),
                                    "busy", availability),
                current_load = GREATEST(0, current_load + ?),
                updated_at   = NOW()
          WHERE id = ?',
        [$delta, $delta, $delta, $workerId]
    );
}
