<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/ApiBaseController.php';

class AdminController extends ApiBaseController
{
    public function auditLogs()
    {
        $this->requireAdmin();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
        $conditions = [];
        $values = [];
        foreach (['action', 'entity_type', 'user_id'] as $filter) {
            if (!isset($_GET[$filter]) || $_GET[$filter] === '') continue;
            $conditions[] = 'a.' . $filter . ' = ?';
            $values[] = $_GET[$filter];
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $filter => $operator) {
            if (!isset($_GET[$filter]) || $_GET[$filter] === '') continue;
            $date = DateTime::createFromFormat('Y-m-d', $_GET[$filter]);
            if (!$date || $date->format('Y-m-d') !== $_GET[$filter]) {
                $this->api->respond_error('Invalid date filter.', 422);
            }
            $conditions[] = 'DATE(a.created_at) ' . $operator . ' ?';
            $values[] = $_GET[$filter];
        }

        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $total = (int) $this->db->raw('SELECT COUNT(*) AS total FROM audit_logs a' . $where, $values)
            ->fetch(PDO::FETCH_ASSOC)['total'];
        $offset = ($page - 1) * $perPage;
        $logs = $this->db->raw(
            'SELECT a.id, COALESCE(u.name, \'System\') AS user_name, a.action,
                    COALESCE(a.entity_type, \'system\') AS entity_type, a.entity_id,
                    COALESCE(a.description, \'\') AS description, COALESCE(a.ip_address, \'\') AS ip_address,
                    a.created_at
             FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id' . $where .
             ' ORDER BY a.created_at DESC, a.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $values
        )->fetchAll(PDO::FETCH_ASSOC);
        $actions = $this->db->raw('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
        $entityTypes = $this->db->raw(
            'SELECT DISTINCT entity_type FROM audit_logs WHERE entity_type IS NOT NULL ORDER BY entity_type'
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->respondSuccess([
            'logs' => $logs,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
            'filters' => ['actions' => $actions, 'entity_types' => $entityTypes],
        ]);
    }

    public function dashboardStats()
    {
        $this->requireAdmin();
        $counts = $this->db->raw(
            'SELECT COUNT(*) AS total_reports,
                    SUM(status = ?) AS pending_reports,
                    SUM(status IN (?, ?)) AS in_progress_reports,
                    SUM(status = ?) AS resolved_reports
             FROM reports',
            ['submitted', 'assigned', 'in_progress', 'resolved']
        )->fetch(PDO::FETCH_ASSOC);
        $users = $this->db->raw(
            'SELECT COUNT(*) AS total_users, SUM(is_active = 1) AS active_users FROM users'
        )->fetch(PDO::FETCH_ASSOC);
        $categoryCount = (int) $this->db->raw(
            'SELECT COUNT(*) AS total FROM issue_categories WHERE is_active = 1'
        )->fetch(PDO::FETCH_ASSOC)['total'];
        $statuses = $this->db->raw(
            'SELECT status, COUNT(*) AS total FROM reports GROUP BY status'
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $categories = $this->db->raw(
            'SELECT c.name, COUNT(r.id) AS total FROM issue_categories c
             LEFT JOIN reports r ON r.category_id = c.id GROUP BY c.id, c.name ORDER BY c.name'
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $recent = $this->db->raw(
            'SELECT id, action AS type, COALESCE(description, action) AS description, created_at
             FROM audit_logs ORDER BY created_at DESC, id DESC LIMIT 10'
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->respondSuccess([
            'total_reports' => (int) $counts['total_reports'],
            'pending_reports' => (int) $counts['pending_reports'],
            'in_progress_reports' => (int) $counts['in_progress_reports'],
            'resolved_reports' => (int) $counts['resolved_reports'],
            'total_users' => (int) $users['total_users'],
            'active_users' => (int) $users['active_users'],
            'total_categories' => $categoryCount,
            'reports_by_status' => $statuses,
            'reports_by_category' => $categories,
            'recent_activity' => $recent,
        ]);
    }
}
