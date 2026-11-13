<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

abstract class ApiBaseController extends Controller
{
    protected $actor;
    protected $sessionId;

    public function __construct()
    {
        parent::__construct();
        $this->call->library('api');
        $this->call->database();
    }

    protected function authenticate()
    {
        $token = $this->api->require_jwt();
        $this->sessionId = $token['session_id'] ?? null;
        $stmt = $this->db->raw(
            'SELECT id, name, email, phone, role, is_active, auth_version, email_verified_at, profile_photo, created_at
             FROM users WHERE id = ? LIMIT 1',
            [$token['sub']]
        );
        $this->actor = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$this->actor || (int) $this->actor['is_active'] !== 1
            || (int) ($token['auth_version'] ?? 0) !== (int) $this->actor['auth_version']) {
            $this->api->respond_error('Unauthorized', 401);
        }
        if ($this->sessionId !== null) {
            if (!is_string($this->sessionId) || preg_match('/\A[a-f0-9]{32}\z/', $this->sessionId) !== 1) {
                $this->api->respond_error('Unauthorized', 401);
            }
            $sessionExists = $this->db->raw(
                'SELECT 1 FROM refresh_tokens
                 WHERE user_id = ? AND session_id = ? AND expires_at > NOW()
                 LIMIT 1',
                [$this->actor['id'], $this->sessionId]
            )->fetchColumn();
            if ($sessionExists === false) {
                $this->api->respond_error('Unauthorized', 401);
            }
        }
        unset($this->actor['auth_version']);

        $settings = $this->db->raw(
            'SELECT sms_enabled, email_enabled, report_updates_enabled, resolution_updates_enabled
             FROM notification_settings WHERE user_id = ? LIMIT 1',
            [$this->actor['id']]
        )->fetch(PDO::FETCH_ASSOC);
        $this->actor['notification_settings'] = $settings ?: [
            'sms_enabled' => 1,
            'email_enabled' => 1,
            'report_updates_enabled' => 1,
            'resolution_updates_enabled' => 1,
        ];

        return $this->actor;
    }

    protected function requireAdmin()
    {
        $actor = $this->authenticate();
        if ($actor['role'] !== 'admin') {
            $this->api->respond_error('Administrator access is required.', 403);
        }
        return $actor;
    }

    protected function respondSuccess($data, $message = 'OK', $status = 200)
    {
        $this->api->respond([
            'success' => true,
            'data' => $data,
            'message' => $message,
        ], $status);
    }

    protected function audit($action, $entityType, $entityId, $description, $userId = null)
    {
        $this->db->raw(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, description, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $userId ?? $this->actor['id'],
                $action,
                $entityType,
                $entityId,
                $description,
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 1000),
            ]
        );
    }

    protected function notifyUser($recipientId, $reportId, $eventType, $priority)
    {
        $this->db->raw(
            'INSERT INTO user_notifications (recipient_id, report_id, actor_id, event_type, priority, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [$recipientId, $reportId, $this->actor['id'], $eventType, $priority]
        );
    }

    protected function notifyRoles($roles, $reportId, $eventType, $priority, $exceptUserId = null)
    {
        if (!$roles) return;

        $placeholders = implode(', ', array_fill(0, count($roles), '?'));
        $values = [$reportId, $this->actor['id'], $eventType, $priority, ...$roles];
        $sql = 'INSERT INTO user_notifications (recipient_id, report_id, actor_id, event_type, priority, created_at)
                SELECT id, ?, ?, ?, ?, NOW() FROM users WHERE role IN (' . $placeholders . ') AND is_active = 1';
        if ($exceptUserId !== null) {
            $sql .= ' AND id <> ?';
            $values[] = $exceptUserId;
        }
        $this->db->raw($sql, $values);
    }

    protected function getUser($id)
    {
        return $this->db->raw(
            'SELECT id, name, email, phone, role, is_active, email_verified_at, profile_photo, created_at
             FROM users WHERE id = ? LIMIT 1',
            [$id]
        )->fetch(PDO::FETCH_ASSOC);
    }

    protected function withProfilePhotoUrl($user)
    {
        if ($user) {
            $user['profile_photo_url'] = !empty($user['profile_photo'])
                ? '/user/profile-photo/' . rawurlencode((string) $user['id'])
                : null;
        }
        return $user;
    }

    protected function getNotificationSettings($userId)
    {
        $settings = $this->db->raw(
            'SELECT sms_enabled, email_enabled, report_updates_enabled, resolution_updates_enabled
             FROM notification_settings WHERE user_id = ? LIMIT 1',
            [$userId]
        )->fetch(PDO::FETCH_ASSOC);
        return $settings ?: [
            'sms_enabled' => 1,
            'email_enabled' => 1,
            'report_updates_enabled' => 1,
            'resolution_updates_enabled' => 1,
        ];
    }
}
