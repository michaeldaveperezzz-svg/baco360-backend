<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/ApiBaseController.php';

class CategoriesController extends ApiBaseController
{
    public function index()
    {
        $actor = $this->authenticate();
        $includeInactive = $actor['role'] === 'admin'
            && filter_var($_GET['include_inactive'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $sql = 'SELECT id, name, description, is_active FROM issue_categories';
        if (!$includeInactive) $sql .= ' WHERE is_active = 1';
        $sql .= ' ORDER BY name';
        $categories = $this->db->raw($sql)->fetchAll(PDO::FETCH_ASSOC);
        $this->respondSuccess($categories);
    }

    public function show($id)
    {
        $actor = $this->authenticate();
        $category = $this->findCategory($id);
        if (!$category || (!$category['is_active'] && $actor['role'] !== 'admin')) {
            $this->api->respond_error('Category not found.', 404);
        }
        $this->respondSuccess($category);
    }

    public function store()
    {
        $this->api->require_method('POST');
        $actor = $this->requireAdmin();
        $input = $this->api->body();
        $name = trim($input['name'] ?? '');
        $description = trim($input['description'] ?? '');
        if ($name === '' || strlen($name) > 100) {
            $this->api->respond_error('Category name is required and must be 100 characters or fewer.', 422);
        }

        try {
            $this->db->raw(
                'INSERT INTO issue_categories (name, description, is_active, created_at, updated_at)
                 VALUES (?, ?, 1, NOW(), NOW())',
                [$name, $description ?: null]
            );
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') $this->api->respond_error('A category with this name already exists.', 409);
            throw $error;
        }
        $id = $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
        $this->actor = $actor;
        $this->audit('created', 'issue_categories', $id, 'Created category ' . $name);
        $this->respondSuccess($this->findCategory($id), 'Category created.', 201);
    }

    public function update($id)
    {
        $this->api->require_method('PUT');
        $this->requireAdmin();
        $category = $this->findCategory($id);
        if (!$category) $this->api->respond_error('Category not found.', 404);
        $input = $this->api->body();
        $fields = [];
        $values = [];

        foreach (['name' => 100, 'description' => 65535] as $field => $maxLength) {
            if (!array_key_exists($field, $input)) continue;
            $value = trim((string) $input[$field]);
            if ($field === 'name' && $value === '') $this->api->respond_error('Category name cannot be empty.', 422);
            if (strlen($value) > $maxLength) $this->api->respond_error('Category ' . $field . ' is too long.', 422);
            $fields[] = $field . ' = ?';
            $values[] = $value ?: null;
        }
        if (array_key_exists('is_active', $input)) {
            $fields[] = 'is_active = ?';
            $values[] = filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        if (!$fields) $this->api->respond_error('No category changes were provided.', 422);

        $values[] = $id;
        try {
            $this->db->raw('UPDATE issue_categories SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = ?', $values);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') $this->api->respond_error('A category with this name already exists.', 409);
            throw $error;
        }
        $this->audit('updated', 'issue_categories', $id, 'Updated category ' . $id);
        $this->respondSuccess($this->findCategory($id), 'Category updated.');
    }

    public function toggle($id)
    {
        $this->api->require_method('PUT');
        $this->requireAdmin();
        $category = $this->findCategory($id);
        if (!$category) $this->api->respond_error('Category not found.', 404);
        $nextState = $category['is_active'] ? 0 : 1;
        $this->db->raw('UPDATE issue_categories SET is_active = ?, updated_at = NOW() WHERE id = ?', [$nextState, $id]);
        $this->audit('updated', 'issue_categories', $id, $nextState ? 'Activated category' : 'Paused category');
        $this->respondSuccess($this->findCategory($id), 'Category status updated.');
    }

    public function destroy($id)
    {
        $this->api->require_method('DELETE');
        $this->requireAdmin();
        if (!$this->findCategory($id)) $this->api->respond_error('Category not found.', 404);
        $used = $this->db->raw('SELECT COUNT(*) AS total FROM reports WHERE category_id = ?', [$id])->fetch(PDO::FETCH_ASSOC);
        if ((int) $used['total'] > 0) {
            $this->api->respond_error('This category is used by existing reports. Pause it instead of deleting it.', 409);
        }
        $this->db->raw('DELETE FROM issue_categories WHERE id = ?', [$id]);
        $this->audit('deleted', 'issue_categories', $id, 'Deleted category ' . $id);
        $this->respondSuccess(null, 'Category deleted.');
    }

    private function findCategory($id)
    {
        $category = $this->db->raw(
            'SELECT id, name, description, is_active FROM issue_categories WHERE id = ? LIMIT 1',
            [$id]
        )->fetch(PDO::FETCH_ASSOC);
        if ($category) $category['is_active'] = (bool) $category['is_active'];
        return $category;
    }
}
