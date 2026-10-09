<?php
/**
 * api/admin/priority.php   POST  (admin)
 * Body: { id, score?, level? }
 *
 * The engine sets the score automatically; an authorised officer can still
 * override it (e.g. after a phone escalation). The manual value is stored
 * separately in priority_factors so the audit trail stays intact.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/priority.php';

try {
    api_require_role('admin');
    api_require_csrf();

    $id    = (int) jinput('id', 0);
    $score = jinput('score');
    $level = clean_str((string) jinput('level', ''), 10);

    $req = db_row('SELECT * FROM waste_requests WHERE id = ?', [$id]);
    if (!$req) {
        json_error('Request not found.', 404);
    }

    $factors = $req['priority_factors'] ? json_decode((string) $req['priority_factors'], true) : [];

    if ($level !== '') {
        // Manual level jump: map the level onto the middle of its band.
        $bands = ['low' => 20, 'medium' => 45, 'high' => 70, 'critical' => 90];
        if (!isset($bands[$level])) {
            json_error('Unknown priority level.', 422);
        }
        $score = $bands[$level];
    }

    if (!is_numeric($score)) {
        json_error('Priority score must be a number between 0 and 100.', 422);
    }
    $score = (int) max(0, min(100, (int) $score));
    $newLevel = priority_level_for($score);

    $factors['manual_override'] = [
        'score'    => $score,
        'level'    => $newLevel,
        'by'       => current_user_id(),
        'at'       => date('Y-m-d H:i:s'),
        'was'      => (int) $req['priority_score'],
    ];

    db_query(
        'UPDATE waste_requests
            SET priority_score = ?, priority_level = ?, priority_factors = ?, updated_at = NOW()
          WHERE id = ?',
        [$score, $newLevel, json_encode($factors), $id]
    );

    // High priority requests jump the queue on the board.
    if ($score >= 61 && $req['status'] === 'pending') {
        db_query('UPDATE waste_requests SET status = "prioritized" WHERE id = ?', [$id]);
    }

    log_activity('priority_override', 'waste_request', $id,
        "Priority set to $score/100 ($newLevel) by admin (was {$req['priority_score']})");

    if ($req['user_id']) {
        notify((int) $req['user_id'], 'Request #' . $id . ' prioritised',
            'Your report was marked as ' . strtoupper($newLevel) . ' priority (' . $score . '/100).',
            'warning', '/citizen/track.php?id=' . $id);
    }

    json_success('Priority updated to ' . $score . '/100 (' . strtoupper($newLevel) . ').', [
        'id'    => $id,
        'score' => $score,
        'level' => $newLevel,
    ]);
} catch (Throwable $e) {
    api_handle_exception($e);
}
