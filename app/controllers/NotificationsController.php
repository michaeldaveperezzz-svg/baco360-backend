<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/ApiBaseController.php';

class NotificationsController extends ApiBaseController
{
    public function index()
    {
        $actor = $this->authenticate();
        $reportNotifications = $this->db->raw(
            'SELECT n.id, n.report_id, n.actor_id, n.event_type, n.priority, n.created_at, n.read_at,
                    r.reference_number, r.title AS report_title, COALESCE(u.name, \'Community member\') AS actor_name,
                    NULL AS announcement_id, NULL AS announcement_message
             FROM user_notifications n
             LEFT JOIN reports r ON r.id = n.report_id
             LEFT JOIN users u ON u.id = n.actor_id
             WHERE n.recipient_id = ?
             ORDER BY n.id DESC
             LIMIT 50',
            [$actor['id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        $announcementNotifications = $this->db->raw(
            'SELECT -a.id AS id, NULL AS report_id, a.created_by AS actor_id, ? AS event_type, ? AS priority,
                    a.published_at AS created_at, ar.read_at, NULL AS reference_number,
                    a.title AS report_title, u.name AS actor_name, a.id AS announcement_id,
                    a.message AS announcement_message
             FROM announcement_recipients ar
             JOIN announcements a ON a.id = ar.announcement_id
             JOIN users u ON u.id = a.created_by
             WHERE ar.user_id = ? AND (a.archived_at IS NULL OR ? IN (?, ?))
             ORDER BY a.id DESC
             LIMIT 50',
            ['announcement', 'medium', $actor['id'], $actor['role'], 'admin', 'staff']
        )->fetchAll(PDO::FETCH_ASSOC);
        $notifications = array_merge($reportNotifications, $announcementNotifications);
        usort($notifications, static function ($left, $right) {
            $dateOrder = strcmp((string) $right['created_at'], (string) $left['created_at']);
            return $dateOrder !== 0 ? $dateOrder : ((int) $right['id'] <=> (int) $left['id']);
        });
        $notifications = array_slice($notifications, 0, 50);

        foreach ($notifications as &$notification) {
            $notification['id'] = (int) $notification['id'];
            $notification['report_id'] = $notification['report_id'] === null ? null : (int) $notification['report_id'];
            $notification['actor_id'] = $notification['actor_id'] === null ? null : (int) $notification['actor_id'];
            $notification['announcement_id'] = $notification['announcement_id'] === null ? null : (int) $notification['announcement_id'];
        }
        unset($notification);

        $this->respondSuccess(['notifications' => $notifications]);
    }

    public function markRead($id)
    {
        $this->api->require_method('PATCH');
        $actor = $this->authenticate();
        if (filter_var($id, FILTER_VALIDATE_INT) === false) {
            $this->api->respond_error('Invalid notification ID.', 422);
        }
        if ((int) $id < 0) {
            $this->db->raw(
                'UPDATE announcement_recipients SET read_at = COALESCE(read_at, NOW())
                 WHERE announcement_id = ? AND user_id = ?',
                [abs((int) $id), $actor['id']]
            );
        } else {
            $this->db->raw(
                'UPDATE user_notifications SET read_at = COALESCE(read_at, NOW()) WHERE id = ? AND recipient_id = ?',
                [$id, $actor['id']]
            );
        }
        $this->respondSuccess(null, 'Notification marked as read.');
    }

    public function markAllRead()
    {
        $this->api->require_method('PATCH');
        $actor = $this->authenticate();
        $this->db->raw(
            'UPDATE user_notifications SET read_at = NOW() WHERE recipient_id = ? AND read_at IS NULL',
            [$actor['id']]
        );
        $this->db->raw(
            'UPDATE announcement_recipients SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL',
            [$actor['id']]
        );
        $this->respondSuccess(null, 'Notifications marked as read.');
    }

    public function destroy($id)
    {
        $this->api->require_method('DELETE');
        $actor = $this->authenticate();
        if (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id < 1) {
            $this->api->respond_error('Invalid notification ID.', 422);
        }
        $this->db->raw(
            'DELETE FROM user_notifications WHERE id = ? AND recipient_id = ?',
            [$id, $actor['id']]
        );
        $this->respondSuccess(null, 'Notification deleted.');
    }

    public function destroyAll()
    {
        $this->api->require_method('DELETE');
        $actor = $this->authenticate();
        if (!$this->db->transaction()) {
            $this->api->respond_error('Could not start deleting notifications. Please try again.', 500);
        }

        try {
            $this->db->raw(
                'DELETE FROM user_notifications WHERE recipient_id = ?',
                [$actor['id']]
            );
            $this->db->raw(
                'DELETE FROM announcement_recipients WHERE user_id = ?',
                [$actor['id']]
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('Could not delete all notifications: ' . $error->getMessage());
            $this->api->respond_error('Could not delete notifications. Please try again.', 500);
        }

        $this->respondSuccess(null, 'All notifications deleted.');
    }
}
