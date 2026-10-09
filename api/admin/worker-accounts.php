<?php
/**
 * api/admin/worker-accounts.php   POST  (admin only)
 * ---------------------------------------------------------------------------
 * Admin control of worker LOGIN accounts. No worker id, name or password is
 * ever hardcoded — every action is keyed by worker_id / the database.
 *
 * Body: { action, worker_id?, email? }
 *   action = provision   -> ensure EVERY eligible worker has a login account
 *   action = create      -> create/activate the login for one worker (+setup link)
 *   action = activate    -> set the account status back to active
 *   action = deactivate  -> disable login (keeps the worker + all history)
 *   action = reset       -> issue a fresh one-time password-setup link
 *
 * One-time tokens are returned ONCE so the admin can hand the link to the
 * worker. Passwords are never returned, generated or logged.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/worker_accounts.php';

try {
    api_require_role('admin');
    api_require_csrf();

    $action   = clean_str((string) jinput('action', ''), 20);
    $workerId = (int) jinput('worker_id', 0);
    $email    = clean_str((string) jinput('email', ''), 150);

    switch ($action) {
        // ------------------------------------------------ provision all ------
        case 'provision': {
            $result = provision_worker_accounts(current_user_id(), true);

            // Attach the one-time setup link for every newly created account.
            foreach ($result['items'] as &$item) {
                if (!empty($item['token'])) {
                    $item['setup_url'] = worker_setup_url($item['token']);
                    unset($item['token']);    // do not echo raw tokens separately
                }
            }
            unset($item);

            $c = $result['counts'];
            $msg = sprintf(
                '%d worker(s) checked · %d already active · %d awaiting setup · %d inactive · %d created',
                $c['eligible'], $c['active'], $c['awaiting_setup'], $c['inactive'], $c['created']
            );
            if ($c['needs_attention'] > 0) {
                $msg .= ' · ' . $c['needs_attention'] . ' need attention';
            }

            log_activity('worker_accounts_provisioned', 'user', null,
                'Worker account provisioning run: ' . $msg);

            json_success($msg, $result);
            break;
        }

        // --------------------------------------------------- create one ------
        case 'create': {
            if ($workerId <= 0) {
                json_error('Select a worker first.', 422);
            }
            $res = ensure_worker_account($workerId, $email ?: null, current_user_id(), true);

            $data = ['worker_id' => $res['worker_id'], 'user_id' => $res['user_id'],
                     'email' => $res['email'], 'created' => $res['created']];
            if (!empty($res['token'])) {
                $data['setup_url'] = worker_setup_url($res['token']);
            }
            $data['account'] = worker_account_state(['user_id' => $res['user_id']]);

            $msg = $res['created']
                ? 'Account created for the worker. Share the one-time setup link.'
                : 'This worker already has a linked login account.';
            json_success($msg, $data);
            break;
        }

        // ------------------------------------------------------- activate -----
        case 'activate': {
            if ($workerId <= 0) {
                json_error('Select a worker first.', 422);
            }
            $res = set_worker_account_active($workerId, true, current_user_id());
            $res['account'] = worker_account_state(['user_id' => $res['user_id']]);
            json_success('Login activated for this worker.', $res);
            break;
        }

        // ----------------------------------------------------- deactivate -----
        case 'deactivate': {
            if ($workerId <= 0) {
                json_error('Select a worker first.', 422);
            }
            $res = set_worker_account_active($workerId, false, current_user_id());
            $res['account'] = worker_account_state(['user_id' => $res['user_id']]);
            json_success('Login deactivated. Work history is untouched.', $res);
            break;
        }

        // ---------------------------------------------------------- reset -----
        case 'reset': {
            if ($workerId <= 0) {
                json_error('Select a worker first.', 422);
            }
            $res = regenerate_worker_setup_link($workerId, current_user_id());
            $res['setup_url'] = worker_setup_url($res['token']);
            unset($res['token']);
            $res['account'] = worker_account_state(['user_id' => $res['user_id']]);
            json_success('One-time setup link created. Share it with the worker.', $res);
            break;
        }

        default:
            json_error('Unknown action.', 422);
    }
} catch (Throwable $e) {
    api_handle_exception($e);
}
