<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/ApiBaseController.php';

class CommunityReportsController extends ApiBaseController
{
    private const DEFAULT_PER_PAGE = 12;
    private const MAX_PER_PAGE = 30;
    private const MAX_PAGE = 1000000;
    private const MAX_COMMENT_LENGTH = 2000;

    public function index()
    {
        $actor = $this->requireResident();
        $page = $this->queryInteger('page', 1, 1, self::MAX_PAGE);
        $perPage = $this->queryInteger('per_page', self::DEFAULT_PER_PAGE, 1, self::MAX_PER_PAGE);
        $total = (int) $this->db->raw(
            'SELECT COUNT(*) FROM reports WHERE status = ?',
            ['resolved']
        )->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $reports = $this->db->raw(
            'SELECT r.id, r.reference_number, r.category_id, c.name AS category_name, r.title,
                    r.description, r.status, r.priority, r.location_address, r.submitted_at,
                    r.resolved_at, r.resolution_description,
                    (SELECT COUNT(*) FROM community_report_reactions cr WHERE cr.report_id = r.id) AS heart_count,
                    EXISTS(
                        SELECT 1 FROM community_report_reactions cr
                        WHERE cr.report_id = r.id AND cr.user_id = ?
                    ) AS hearted_by_me,
                    (SELECT COUNT(*) FROM community_report_comments cc WHERE cc.report_id = r.id) AS comment_count
             FROM reports r
             JOIN issue_categories c ON c.id = r.category_id
             WHERE r.status = ?
             ORDER BY COALESCE(r.resolved_at, r.updated_at, r.submitted_at) DESC, r.id DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset,
            [$actor['id'], 'resolved']
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($reports as &$report) {
            $report['id'] = (int) $report['id'];
            $report['category_id'] = (int) $report['category_id'];
            $report['heart_count'] = (int) $report['heart_count'];
            $report['hearted_by_me'] = (bool) $report['hearted_by_me'];
            $report['comment_count'] = (int) $report['comment_count'];
            $report['attachments'] = $this->db->raw(
                'SELECT id, original_name, mime_type, file_size, attachment_type
                 FROM report_attachments WHERE report_id = ? ORDER BY id',
                [$report['id']]
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($report['attachments'] as &$attachment) {
                $attachment['id'] = (int) $attachment['id'];
                $attachment['file_size'] = (int) $attachment['file_size'];
            }
            unset($attachment);
        }
        unset($report);

        $this->respondSuccess([
            'reports' => $reports,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    public function viewPost($id)
    {
        $actor = $this->authenticate();
        if (!in_array($actor['role'], ['admin', 'staff'], true)) {
            $this->api->respond_error('Staff or admin access is required.', 403);
        }
        $reportId = $this->requireResolvedReport($id);
        if ($actor['role'] === 'staff' && !$this->db->raw(
            'SELECT 1 FROM report_assignments
             WHERE report_id = ? AND staff_id = ? AND unassigned_at IS NULL
             LIMIT 1',
            [$reportId, $actor['id']]
        )->fetchColumn()) {
            $this->api->respond_error('Resolved report not found.', 404);
        }

        $post = $this->db->raw(
            'SELECT r.id, r.reference_number, r.category_id, c.name AS category_name, r.title,
                    r.description, r.status, r.priority, r.location_address, r.submitted_at,
                    r.resolved_at, r.resolution_description,
                    (SELECT COUNT(*) FROM community_report_reactions cr WHERE cr.report_id = r.id) AS heart_count,
                    (SELECT COUNT(*) FROM community_report_comments cc WHERE cc.report_id = r.id) AS comment_count
             FROM reports r
             JOIN issue_categories c ON c.id = r.category_id
             WHERE r.id = ? AND r.status = ?
             LIMIT 1',
            [$reportId, 'resolved']
        )->fetch(PDO::FETCH_ASSOC);
        if (!$post) $this->api->respond_error('Resolved report not found.', 404);

        $post['id'] = (int) $post['id'];
        $post['category_id'] = (int) $post['category_id'];
        $post['heart_count'] = (int) $post['heart_count'];
        $post['comment_count'] = (int) $post['comment_count'];
        $post['attachments'] = $this->db->raw(
            'SELECT id, original_name, mime_type, file_size, attachment_type
             FROM report_attachments WHERE report_id = ? ORDER BY id',
            [$reportId]
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($post['attachments'] as &$attachment) {
            $attachment['id'] = (int) $attachment['id'];
            $attachment['file_size'] = (int) $attachment['file_size'];
        }
        unset($attachment);

        $post['comments'] = $this->db->raw(
            'SELECT id, comment, created_at
             FROM community_report_comments WHERE report_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT 100',
            [$reportId]
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($post['comments'] as &$comment) {
            $comment['id'] = (int) $comment['id'];
            $comment['author'] = 'Resident';
        }
        unset($comment);

        $this->respondSuccess($post);
    }

    public function comments($id)
    {
        $this->requireResident();
        $reportId = $this->requireResolvedReport($id);
        $page = $this->queryInteger('page', 1, 1, self::MAX_PAGE);
        $perPage = $this->queryInteger('per_page', 50, 1, 100);
        $total = (int) $this->db->raw(
            'SELECT COUNT(*) FROM community_report_comments WHERE report_id = ?',
            [$reportId]
        )->fetchColumn();
        $comments = $this->db->raw(
            'SELECT id, comment, created_at
             FROM community_report_comments WHERE report_id = ?
             ORDER BY created_at DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            [$reportId]
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($comments as &$comment) {
            $comment['id'] = (int) $comment['id'];
            $comment['author'] = 'Resident';
        }
        unset($comment);

        $this->respondSuccess([
            'comments' => $comments,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    public function addComment($id)
    {
        $this->api->require_method('POST');
        $actor = $this->requireResident();
        $reportId = $this->requireResolvedReport($id);
        $input = $this->api->body();
        $comment = trim((string) ($input['comment'] ?? ''));
        if ($comment === '' || strlen($comment) > self::MAX_COMMENT_LENGTH) {
            $this->api->respond_error('Enter a comment of up to 2,000 characters.', 422);
        }

        $this->db->raw(
            'INSERT INTO community_report_comments (report_id, user_id, comment, created_at)
             VALUES (?, ?, ?, NOW())',
            [$reportId, $actor['id'], $comment]
        );
        $this->notifyReportAudience($reportId, 'community_comment', $actor['id']);
        $commentId = (int) $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
        $createdAt = $this->db->raw(
            'SELECT created_at FROM community_report_comments WHERE id = ?',
            [$commentId]
        )->fetchColumn();
        $this->respondSuccess([
            'id' => $commentId,
            'author' => 'Resident',
            'comment' => $comment,
            'created_at' => $createdAt,
        ], 'Comment posted.', 201);
    }

    public function setHeart($id)
    {
        $this->api->require_method('PUT');
        $actor = $this->requireResident();
        $reportId = $this->requireResolvedReport($id);
        $input = $this->api->body();
        if (!array_key_exists('hearted', $input) || filter_var($input['hearted'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null) {
            $this->api->respond_error('Choose whether the report should be hearted.', 422);
        }
        $hearted = filter_var($input['hearted'], FILTER_VALIDATE_BOOLEAN);
        if ($hearted) {
            $reaction = $this->db->raw(
                'INSERT IGNORE INTO community_report_reactions (report_id, user_id, created_at)
                 VALUES (?, ?, NOW())',
                [$reportId, $actor['id']]
            );
            if ($reaction->rowCount() > 0) {
                $this->notifyReportAudience($reportId, 'community_reaction', $actor['id']);
            }
        } else {
            $this->db->raw(
                'DELETE FROM community_report_reactions WHERE report_id = ? AND user_id = ?',
                [$reportId, $actor['id']]
            );
        }
        $heartCount = (int) $this->db->raw(
            'SELECT COUNT(*) FROM community_report_reactions WHERE report_id = ?',
            [$reportId]
        )->fetchColumn();
        $this->respondSuccess(['hearted' => $hearted, 'heart_count' => $heartCount]);
    }

    private function notifyReportAudience($reportId, $eventType, $actorId)
    {
        $this->db->raw(
            'INSERT INTO user_notifications (recipient_id, report_id, actor_id, event_type, priority, created_at)
             SELECT DISTINCT u.id, r.id, ?, ?, r.priority, NOW()
             FROM reports r
             JOIN users u ON u.is_active = 1
             WHERE r.id = ?
               AND (
                   u.role = \'admin\'
                   OR (
                       u.role = \'staff\'
                       AND EXISTS (
                           SELECT 1 FROM report_assignments ra
                           WHERE ra.report_id = r.id
                             AND ra.staff_id = u.id
                             AND ra.unassigned_at IS NULL
                       )
                   )
               )',
            [$actorId, $eventType, $reportId]
        );
    }

    private function requireResident()
    {
        $actor = $this->authenticate();
        if ($actor['role'] !== 'resident') {
            $this->api->respond_error('Resident access is required.', 403);
        }
        return $actor;
    }

    private function requireResolvedReport($id)
    {
        $reportId = filter_var($id, FILTER_VALIDATE_INT);
        if ($reportId === false || $reportId < 1) {
            $this->api->respond_error('Report not found.', 404);
        }
        $exists = $this->db->raw(
            'SELECT id FROM reports WHERE id = ? AND status = ? LIMIT 1',
            [$reportId, 'resolved']
        )->fetchColumn();
        if (!$exists) $this->api->respond_error('Resolved report not found.', 404);
        return (int) $exists;
    }

    private function queryInteger($key, $default, $minimum, $maximum)
    {
        if (!isset($_GET[$key]) || $_GET[$key] === '') return $default;
        $value = filter_var($_GET[$key], FILTER_VALIDATE_INT);
        if ($value === false || $value < $minimum || $value > $maximum) {
            $this->api->respond_error('Invalid ' . $key . ' value.', 422);
        }
        return $value;
    }
}
