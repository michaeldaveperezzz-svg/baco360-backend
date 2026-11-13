<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/ApiBaseController.php';

class AnnouncementsController extends ApiBaseController
{
    public function index()
    {
        $actor = $this->authenticate();
        $announcements = $this->db->raw(
            'SELECT a.id, a.created_by, a.title, a.message, a.published_at, a.created_at, a.archived_at,
                    u.name AS author_name,
                    CASE WHEN ar.user_id IS NULL THEN a.published_at ELSE ar.read_at END AS read_at
             FROM announcements a
             LEFT JOIN announcement_recipients ar ON ar.announcement_id = a.id AND ar.user_id = ?
             JOIN users u ON u.id = a.created_by
             WHERE (ar.user_id = ? AND a.archived_at IS NULL)
                OR a.created_by = ?
                OR ? = ?
             ORDER BY a.published_at DESC, a.id DESC
             LIMIT 100',
            [$actor['id'], $actor['id'], $actor['id'], $actor['role'], 'admin']
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($announcements as &$announcement) {
            $announcement['id'] = (int) $announcement['id'];
            $announcement['created_by'] = (int) $announcement['created_by'];
        }
        unset($announcement);

        $this->respondSuccess(['announcements' => $announcements]);
    }

    public function recipients()
    {
        $this->api->require_method('GET');
        $actor = $this->authenticate();
        if (!in_array($actor['role'], ['admin', 'staff'], true)) {
            $this->api->respond_error('Only administrators and staff may view announcement recipients.', 403);
        }

        $roles = $actor['role'] === 'admin' ? ['resident', 'staff'] : ['staff'];
        $placeholders = implode(', ', array_fill(0, count($roles), '?'));
        $recipients = $this->db->raw(
            'SELECT id, name, role FROM users
             WHERE is_active = 1 AND role IN (' . $placeholders . ')
             ORDER BY role, name, id',
            $roles
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($recipients as &$recipient) {
            $recipient['id'] = (int) $recipient['id'];
        }
        unset($recipient);

        $this->respondSuccess(['recipients' => $recipients]);
    }

    public function update($id)
    {
        $this->api->require_method('PUT');
        $actor = $this->authenticate();
        if (!in_array($actor['role'], ['admin', 'staff'], true)) {
            $this->api->respond_error('Only administrators and staff may edit announcements.', 403);
        }

        $announcement = $this->findAnnouncement($id);
        if (!$announcement) $this->api->respond_error('Announcement not found.', 404);
        if ($announcement['archived_at'] !== null) {
            $this->api->respond_error('Restore the announcement before editing it.', 409);
        }

        $input = $this->api->body();
        if (!is_array($input) || !is_string($input['title'] ?? null) || !is_string($input['message'] ?? null)) {
            $this->api->respond_error('A title and message are required.', 422);
        }
        $title = trim($input['title']);
        $message = trim($input['message']);
        if ($title === '' || strlen($title) > 255) {
            $this->api->respond_error('Enter an announcement title of up to 255 characters.', 422);
        }
        if ($message === '' || strlen($message) > 10000) {
            $this->api->respond_error('Enter an announcement message of up to 10,000 characters.', 422);
        }

        $this->db->raw(
            'UPDATE announcements SET title = ?, message = ?, updated_at = NOW() WHERE id = ?',
            [$title, $message, $id]
        );
        try {
            $this->audit('updated', 'announcements', $id, 'Edited community announcement.');
        } catch (Throwable $error) {
            error_log('Announcement ' . $id . ' was updated but its audit record could not be saved: ' . $error->getMessage());
        }

        $updated = $this->findAnnouncement($id);
        $updated['created_by'] = (int) $updated['created_by'];
        $this->respondSuccess(['announcement' => $updated], 'Announcement updated. Email was not sent again.');
    }

    public function destroy($id)
    {
        $this->api->require_method('DELETE');
        $actor = $this->authenticate();
        if (!in_array($actor['role'], ['admin', 'staff'], true)) {
            $this->api->respond_error('Only administrators and staff may delete announcements.', 403);
        }
        if (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id < 1) {
            $this->api->respond_error('Invalid announcement ID.', 422);
        }

        $announcement = $this->findAnnouncement($id);
        if (!$announcement) $this->api->respond_error('Announcement not found.', 404);
        if ($actor['role'] === 'staff' && (int) $announcement['created_by'] !== (int) $actor['id']) {
            $this->api->respond_error('Staff may only delete announcements they created.', 403);
        }

        $deleted = $this->db->raw('DELETE FROM announcements WHERE id = ?', [$id]);
        if ($deleted->rowCount() !== 1) {
            $this->api->respond_error('Announcement could not be deleted. Please try again.', 409);
        }

        $auditWarning = false;
        try {
            $this->audit('deleted', 'announcements', $id, 'Deleted community announcement and its recipient notifications.');
        } catch (Throwable $error) {
            $auditWarning = true;
            error_log('Announcement ' . $id . ' was deleted but its audit record could not be saved: ' . $error->getMessage());
        }

        $this->respondSuccess(['audit_warning' => $auditWarning], 'Announcement deleted.');
    }

    public function archive($id)
    {
        $this->api->require_method('PATCH');
        $actor = $this->authenticate();
        if (!in_array($actor['role'], ['admin', 'staff'], true)) {
            $this->api->respond_error('Only administrators and staff may archive announcements.', 403);
        }
        $announcement = $this->findAnnouncement($id);
        if (!$announcement) $this->api->respond_error('Announcement not found.', 404);
        if ($announcement['archived_at'] === null) {
            $this->db->raw('UPDATE announcements SET archived_at = NOW(), updated_at = NOW() WHERE id = ?', [$id]);
        }
        try {
            $this->audit('archived', 'announcements', $id, 'Archived community announcement.');
        } catch (Throwable $error) {
            error_log('Announcement ' . $id . ' was archived but its audit record could not be saved: ' . $error->getMessage());
        }
        $this->respondSuccess(null, 'Announcement archived.');
    }

    public function restore($id)
    {
        $this->api->require_method('PATCH');
        $actor = $this->authenticate();
        if (!in_array($actor['role'], ['admin', 'staff'], true)) {
            $this->api->respond_error('Only administrators and staff may restore announcements.', 403);
        }
        $announcement = $this->findAnnouncement($id);
        if (!$announcement) $this->api->respond_error('Announcement not found.', 404);
        if ($announcement['archived_at'] !== null) {
            $this->db->raw('UPDATE announcements SET archived_at = NULL, updated_at = NOW() WHERE id = ?', [$id]);
        }
        try {
            $this->audit('restored', 'announcements', $id, 'Restored community announcement.');
        } catch (Throwable $error) {
            error_log('Announcement ' . $id . ' was restored but its audit record could not be saved: ' . $error->getMessage());
        }
        $this->respondSuccess(null, 'Announcement restored.');
    }

    public function publish()
    {
        $this->api->require_method('POST');
        $actor = $this->authenticate();
        if (!in_array($actor['role'], ['admin', 'staff'], true)) {
            $this->api->respond_error('Only administrators and staff may publish announcements.', 403);
        }

        $input = $this->api->body();
        if (!is_array($input) || !is_string($input['title'] ?? null) || !is_string($input['message'] ?? null)) {
            $this->api->respond_error('A title and message are required.', 422);
        }
        $title = trim((string) ($input['title'] ?? ''));
        $message = trim((string) ($input['message'] ?? ''));
        if ($title === '' || strlen($title) > 255) {
            $this->api->respond_error('Enter an announcement title of up to 255 characters.', 422);
        }
        if ($message === '' || strlen($message) > 10000) {
            $this->api->respond_error('Enter an announcement message of up to 10,000 characters.', 422);
        }

        $audience = $input['audience'] ?? null;
        $allowedAudiences = $actor['role'] === 'admin'
            ? ['all_staff', 'all_residents', 'specific_users']
            : ['all_staff', 'specific_users'];
        if (!is_string($audience) || !in_array($audience, $allowedAudiences, true)) {
            $this->api->respond_error('Choose a valid announcement audience.', 422);
        }

        $recipientIds = [];
        if ($audience === 'specific_users') {
            $requestedIds = $input['recipient_ids'] ?? null;
            if (!is_array($requestedIds) || !$requestedIds) {
                $this->api->respond_error('Choose at least one recipient.', 422);
            }
            foreach ($requestedIds as $requestedId) {
                $validId = filter_var($requestedId, FILTER_VALIDATE_INT);
                if ($validId === false || $validId < 1) {
                    $this->api->respond_error('The selected recipient list is invalid.', 422);
                }
                $recipientIds[] = (int) $validId;
            }
            $recipientIds = array_values(array_unique($recipientIds));
        }

        $targetRoles = $audience === 'all_staff' || $actor['role'] === 'staff'
            ? ['staff']
            : ($audience === 'all_residents' ? ['resident'] : ['resident', 'staff']);
        $rolePlaceholders = implode(', ', array_fill(0, count($targetRoles), '?'));

        if (!$this->db->transaction()) {
            $this->api->respond_error('Could not start publishing the announcement. Please try again.', 500);
        }

        $announcementId = null;
        try {
            if ($audience === 'specific_users') {
                $idPlaceholders = implode(', ', array_fill(0, count($recipientIds), '?'));
                $recipients = $this->db->raw(
                    'SELECT id, name, email FROM users
                     WHERE is_active = 1 AND role IN (' . $rolePlaceholders . ')
                       AND id IN (' . $idPlaceholders . ')
                     ORDER BY id',
                    [...$targetRoles, ...$recipientIds]
                )->fetchAll(PDO::FETCH_ASSOC);
                if (count($recipients) !== count($recipientIds)) {
                    $this->db->roll_back();
                    $this->api->respond_error('One or more selected recipients are no longer active or are not available for your role.', 422);
                }
            } else {
                $recipients = $this->db->raw(
                    'SELECT id, name, email FROM users
                     WHERE is_active = 1 AND role IN (' . $rolePlaceholders . ')
                     ORDER BY id',
                    $targetRoles
                )->fetchAll(PDO::FETCH_ASSOC);
            }

            if (!$recipients) {
                $this->db->roll_back();
                $this->api->respond_error('There are no active users in the selected audience.', 422);
            }

            $this->db->raw(
                'INSERT INTO announcements (created_by, title, message, published_at, created_at)
                 VALUES (?, ?, ?, NOW(), NOW())',
                [$actor['id'], $title, $message]
            );
            $announcementId = (int) $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();

            $idPlaceholders = implode(', ', array_fill(0, count($recipients), '?'));
            $this->db->raw(
                'INSERT INTO announcement_recipients (announcement_id, user_id, read_at, created_at)
                 SELECT ?, id, IF(id = ?, NOW(), NULL), NOW()
                 FROM users
                 WHERE is_active = 1 AND role IN (' . $rolePlaceholders . ')
                   AND id IN (' . $idPlaceholders . ')',
                [$announcementId, $actor['id'], ...$targetRoles, ...array_column($recipients, 'id')]
            );
            if ((int) $this->db->raw('SELECT ROW_COUNT()')->fetchColumn() !== count($recipients)) {
                throw new RuntimeException('The announcement recipient list changed while publishing.');
            }
            if (!$this->db->commit()) {
                throw new RuntimeException('Could not commit the announcement and recipients.');
            }
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('Could not publish announcement ' . ($announcementId ?? '(not created)') . ': ' . $error->getMessage());
            $this->api->respond_error('Could not publish the announcement to the selected recipients. Please try again.', 500);
        }

        $auditWarning = false;
        try {
            $this->audit('published', 'announcements', $announcementId, 'Published community announcement.');
        } catch (Throwable $error) {
            $auditWarning = true;
            error_log('Announcement ' . $announcementId . ' was prepared but its audit record could not be saved: ' . $error->getMessage());
        }

        $this->respondSuccess([
            'announcement' => [
                'id' => $announcementId,
                'created_by' => (int) $actor['id'],
                'author_name' => $actor['name'],
                'title' => $title,
                'message' => $message,
                'published_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
                'read_at' => null,
                'archived_at' => null,
            ],
            'recipient_count' => count($recipients),
            'audit_warning' => $auditWarning,
        ], 'Announcement published.', 201);
    }

    public function markRead($id)
    {
        $this->api->require_method('PATCH');
        $actor = $this->authenticate();
        $this->db->raw(
            'UPDATE announcement_recipients SET read_at = COALESCE(read_at, NOW())
             WHERE announcement_id = ? AND user_id = ?',
            [$id, $actor['id']]
        );
        $this->respondSuccess(null, 'Announcement marked as read.');
    }

    private function findAnnouncement($id)
    {
        return $this->db->raw(
            'SELECT a.id, a.created_by, a.title, a.message, a.published_at, a.created_at, a.updated_at, a.archived_at,
                    u.name AS author_name
             FROM announcements a JOIN users u ON u.id = a.created_by
             WHERE a.id = ? LIMIT 1',
            [$id]
        )->fetch(PDO::FETCH_ASSOC);
    }
}
