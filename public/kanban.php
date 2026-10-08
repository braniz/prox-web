<?php

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$config = load_json('api');
$allUsers = array_keys(load_users());
sort($allUsers, SORT_NATURAL | SORT_FLAG_CASE);
$formatDate = static function (string $iso): string {
    $ts = strtotime($iso);
    return $ts === false ? '' : date('d.m.Y H:i', $ts);
};
$taskStatusMap = [
    'todo' => 'Geplant / ToDo',
    'doing' => 'In Arbeit',
    'done' => 'Erledigt',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $tasks = load_kanban_tasks();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $vmid = trim((string) ($_POST['vmid'] ?? ''));
        $node = trim((string) ($_POST['node'] ?? ''));
        if ($title !== '') {
            $newId = 1;
            foreach ($tasks as $task) {
                $newId = max($newId, (int) ($task['id'] ?? 0) + 1);
            }
            $tasks[] = [
                'id' => $newId,
                'title' => $title,
                'description' => $description,
                'status' => 'todo',
                'vmid' => $vmid,
                'node' => $node,
                'creator' => (string) ($user['username'] ?? ''),
                'created_at' => date('c'),
                'updated_at' => date('c'),
            ];
            save_kanban_tasks($tasks);
        }
    } elseif ($action === 'edit') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title !== '') {
            foreach ($tasks as &$task) {
                if ((int) ($task['id'] ?? 0) === $taskId) {
                    $task['title'] = $title;
                    $task['description'] = trim((string) ($_POST['description'] ?? ''));
                    $task['vmid'] = trim((string) ($_POST['vmid'] ?? ''));
                    $task['node'] = trim((string) ($_POST['node'] ?? ''));
                    $assignee = trim((string) ($_POST['assignee'] ?? ''));
                    $task['assignee'] = in_array($assignee, $allUsers, true) ? $assignee : '';
                    $task['updated_at'] = date('c');
                    break;
                }
            }
            unset($task);
            save_kanban_tasks($tasks);
        }
    } elseif ($action === 'move') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $newStatus = (string) ($_POST['status'] ?? 'todo');
        foreach ($tasks as &$task) {
            if ((int) ($task['id'] ?? 0) === $taskId && isset($taskStatusMap[$newStatus])) {
                $task['status'] = $newStatus;
                $task['updated_at'] = date('c');
            }
        }
        unset($task);
        save_kanban_tasks($tasks);
    } elseif ($action === 'delete') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $tasks = array_values(array_filter($tasks, static fn (array $task): bool => (int) ($task['id'] ?? 0) !== $taskId));
        save_kanban_tasks($tasks);
    }

    header('Location: /kanban.php');
    exit;
}

$tasks = load_kanban_tasks();
$clusterResult = fetch_proxmox_data($config);
$resources = [];
if ($clusterResult['success'] && is_array($clusterResult['data']['resources'] ?? null)) {
    foreach ($clusterResult['data']['resources'] as $resource) {
        if (!is_array($resource)) {
            continue;
        }
        $type = strtolower((string) ($resource['type'] ?? ''));
        if (!in_array($type, ['qemu', 'lxc', 'docker'], true)) {
            continue;
        }
        $resources[] = [
            'vmid' => (string) ($resource['vmid'] ?? ''),
            'node' => (string) ($resource['node'] ?? ''),
            'name' => (string) ($resource['name'] ?? ''),
            'type' => strtoupper($type),
        ];
    }
}

$knownNodes = [];
foreach (($clusterResult['success'] && is_array($clusterResult['data']['nodes'] ?? null)) ? $clusterResult['data']['nodes'] : [] as $nodeEntry) {
    if (is_array($nodeEntry) && trim((string) ($nodeEntry['node'] ?? '')) !== '') {
        $knownNodes[] = trim((string) $nodeEntry['node']);
    }
}
foreach ($resources as $resource) {
    if ($resource['node'] !== '') {
        $knownNodes[] = $resource['node'];
    }
}
$knownNodes = array_values(array_unique($knownNodes));
sort($knownNodes, SORT_NATURAL | SORT_FLAG_CASE);
$nodeOptions = static function (string $selected) use ($knownNodes): string {
    $html = '<option value="">Keine Angabe</option>';
    $options = $knownNodes;
    if ($selected !== '' && !in_array($selected, $options, true)) {
        $options[] = $selected;
    }
    foreach ($options as $name) {
        $html .= '<option value="' . e($name) . '"' . ($name === $selected ? ' selected' : '') . '>' . e($name) . '</option>';
    }
    return $html;
};

render_header('Kanban', 'kanban');

echo '<style>'
    . '.kanban-board{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;align-items:start;}'
    . '.kanban-column{padding:0;overflow:hidden;}'
    . '.kanban-column.drop-target{outline:2px dashed #94a3b8;outline-offset:-2px;background:#f8fafc;}'
    . '.kanban-card{border:1px solid #dfe7f1;border-radius:10px;padding:.75rem;background:#fff;cursor:grab;user-select:none;touch-action:none;}'
    . '.kanban-card.dragging{opacity:.45;box-shadow:0 14px 28px rgba(15,23,42,.18);}'
    . '.kanban-card:active{cursor:grabbing;}'
    . '.kanban-card details{cursor:auto;user-select:text;}'
    . '.kanban-column.drop-target{outline:2px dashed #94a3b8;outline-offset:-2px;background:#f8fafc;}'
    . '</style>';

echo '<div class="section-card" style="margin-bottom:1rem;">'
    . '<h2 style="margin-top:0;">Neues Todo</h2>'
    . '<form method="post" action="/kanban.php">'
    . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
    . '<input type="hidden" name="action" value="add">'
    . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem;">'
    . '<label><span class="muted">Titel</span><br><input type="text" name="title" required style="width:100%;box-sizing:border-box;"></label>'
    . '<label><span class="muted">Beschreibung</span><br><input type="text" name="description" style="width:100%;box-sizing:border-box;"></label>'
    . '<label><span class="muted">VM / Container</span><br><select name="vmid" style="width:100%;box-sizing:border-box;">'
    . '<option value="">Keine Zuordnung</option>';
foreach ($resources as $resource) {
    echo '<option value="' . e($resource['vmid']) . '" data-node="' . e($resource['node']) . '">' . e($resource['node']) . ' · ' . e($resource['name']) . ' (' . e($resource['type']) . ')</option>';
}
echo '</select></label>'
    . '<label><span class="muted">Node</span><br><select name="node" id="new-node" style="width:100%;box-sizing:border-box;">' . $nodeOptions('') . '</select></label>'
    . '</div>'
    . '<div style="margin-top:.75rem;"><button type="submit">Todo anlegen</button></div>'
    . '</form>'
    . '</div>';

$columns = ['todo' => [], 'doing' => [], 'done' => []];
foreach ($tasks as $task) {
    $status = (string) ($task['status'] ?? 'todo');
    $columns[$status ?? 'todo'][] = $task;
}

echo '<div class="kanban-board">';
foreach ($columns as $statusKey => $columnTasks) {
    echo '<div class="section-card kanban-column" data-status="' . e($statusKey) . '" style="padding:0;overflow:hidden;">'
        . '<div style="padding:.75rem .9rem;background:#f8fafc;border-bottom:1px solid #dfe7f1;font-weight:700;">' . e($taskStatusMap[$statusKey] ?? ucfirst($statusKey)) . ' (' . count($columnTasks) . ')</div>'
        . '<div style="padding:.75rem .9rem;display:flex;flex-direction:column;gap:.75rem;min-height:120px;">';
    if ($columnTasks === []) {
        echo '<div class="muted">Keine Einträge.</div>';
    } else {
        foreach ($columnTasks as $task) {
            $taskId = (int) ($task['id'] ?? 0);
            $vmInfo = '';
            if (($task['vmid'] ?? '') !== '') {
                $vmInfo = 'VMID ' . e((string) $task['vmid']);
                if (($task['node'] ?? '') !== '') {
                    $vmInfo .= ' · Knoten ' . e((string) $task['node']);
                }
            }
            echo '<div class="kanban-card" data-task-id="' . e((string) $taskId) . '" data-status="' . e($statusKey) . '" style="border:1px solid #dfe7f1;border-radius:10px;padding:.75rem;background:#fff;">'
                . '<div style="font-weight:700;margin-bottom:.2rem;">' . e((string) $task['title']) . '</div>'
                . (!empty($task['description']) ? '<div class="muted" style="margin-bottom:.5rem;">' . e((string) $task['description']) . '</div>' : '')
                . (!empty($vmInfo) ? '<div class="muted" style="font-size:.8rem;margin-bottom:.5rem;">' . e($vmInfo) . '</div>' : '')
                . '<div class="muted" style="font-size:.8rem;margin-bottom:.5rem;">Ersteller: ' . e((string) ($task['creator'] ?? '') !== '' ? (string) $task['creator'] : 'Unbekannt') . '</div>'
                . ((string) ($task['assignee'] ?? '') !== '' ? '<div class="muted" style="font-size:.8rem;margin-bottom:.5rem;">In Arbeit von: <strong>' . e((string) $task['assignee']) . '</strong></div>' : '')
                . '<div class="muted" style="font-size:.8rem;margin-bottom:.5rem;">Erstellt: ' . e($formatDate((string) ($task['created_at'] ?? '')))
                . (($task['updated_at'] ?? '') !== ($task['created_at'] ?? '') ? ' · Geändert: ' . e($formatDate((string) ($task['updated_at'] ?? ''))) : '') . '</div>'
                . '<div style="display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.6rem;">';
            foreach (['todo', 'doing', 'done'] as $direction) {
                if ($direction === $statusKey) {
                    continue;
                }
                echo '<form method="post" action="/kanban.php" style="display:inline;">'
                    . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
                    . '<input type="hidden" name="action" value="move">'
                    . '<input type="hidden" name="task_id" value="' . e((string) $taskId) . '">'
                    . '<input type="hidden" name="status" value="' . e($direction) . '">'
                    . '<button type="submit" style="padding:.25rem .5rem;">' . e($taskStatusMap[$direction] ?? ucfirst($direction)) . '</button>'
                    . '</form>';
            }
            echo '<form method="post" action="/kanban.php" style="display:inline;">'
                . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
                . '<input type="hidden" name="action" value="delete">'
                . '<input type="hidden" name="task_id" value="' . e((string) $taskId) . '">'
                . '<button type="submit" style="padding:.25rem .5rem;">Löschen</button>'
                . '</form>'
                . '</div>'
                . '<details style="margin-top:.6rem;"><summary style="cursor:pointer;color:#0b4d8c;">Bearbeiten</summary>'
                . '<form method="post" action="/kanban.php" style="margin-top:.5rem;">'
                . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
                . '<input type="hidden" name="action" value="edit">'
                . '<input type="hidden" name="task_id" value="' . e((string) $taskId) . '">'
                . '<label><span class="muted">Titel</span><br><input type="text" name="title" required value="' . e((string) $task['title']) . '" style="width:100%;box-sizing:border-box;"></label>'
                . '<label><span class="muted">Beschreibung</span><br><textarea name="description" rows="3" style="width:100%;box-sizing:border-box;">' . e((string) ($task['description'] ?? '')) . '</textarea></label>'
                . '<label><span class="muted">VM / Container</span><br><select name="vmid" style="width:100%;box-sizing:border-box;"><option value="">Keine Zuordnung</option>';
            $currentVmid = (string) ($task['vmid'] ?? '');
            $vmidKnown = false;
            foreach ($resources as $resource) {
                $isSelected = $resource['vmid'] === $currentVmid && ($task['node'] ?? '') === $resource['node'];
                $vmidKnown = $vmidKnown || $resource['vmid'] === $currentVmid;
                echo '<option value="' . e($resource['vmid']) . '"' . ($isSelected ? ' selected' : '') . '>' . e($resource['node']) . ' · ' . e($resource['name']) . ' (' . e($resource['type']) . ')</option>';
            }
            if ($currentVmid !== '' && !$vmidKnown) {
                echo '<option value="' . e($currentVmid) . '" selected>VMID ' . e($currentVmid) . '</option>';
            }
            echo '</select></label>';
            if (count($allUsers) > 1) {
                echo '<label><span class="muted">In Arbeit von</span><br><select name="assignee" style="width:100%;box-sizing:border-box;"><option value="">Niemand</option>';
                foreach ($allUsers as $userName) {
                    echo '<option value="' . e($userName) . '"' . ($userName === (string) ($task['assignee'] ?? '') ? ' selected' : '') . '>' . e($userName) . '</option>';
                }
                echo '</select></label>';
            }
            echo ''
                . '<label><span class="muted">Node</span><br><select name="node" style="width:100%;box-sizing:border-box;">' . $nodeOptions((string) ($task['node'] ?? '')) . '</select></label>'
                . '<button type="submit" style="padding:.25rem .5rem;">Speichern</button>'
                . '</form></details>'
                . '</div>';
        }
    }
    echo '</div></div>';
}

echo '</div>';

echo <<<'SCRIPT'
<script>
(function () {
    const csrfToken = document.querySelector('input[name="csrf"]') ? document.querySelector('input[name="csrf"]').value : '';
    const moveTask = function (taskId, targetStatus) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/kanban.php';

        const addField = function (name, value) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        };

        addField('csrf', csrfToken);
        addField('action', 'move');
        addField('task_id', String(taskId));
        addField('status', targetStatus);

        document.body.appendChild(form);
        form.submit();
    };

    let dragState = null;
    let draggedCard = null;

    const newVm = document.querySelector('form select[name="vmid"]');
    const newNode = document.getElementById('new-node');
    if (newVm && newNode) {
        newVm.addEventListener('change', function () {
            const option = newVm.options[newVm.selectedIndex];
            const node = option ? option.getAttribute('data-node') : '';
            if (node) {
                newNode.value = node;
            }
        });
    }

    const resetDrag = function () {
        if (draggedCard) {
            draggedCard.classList.remove('dragging');
            draggedCard.style.position = '';
            draggedCard.style.left = '';
            draggedCard.style.top = '';
            draggedCard.style.width = '';
            draggedCard.style.zIndex = '';
            draggedCard.style.pointerEvents = '';
        }
        document.querySelectorAll('.kanban-column').forEach(function (column) {
            column.classList.remove('drop-target');
        });
        dragState = null;
        draggedCard = null;
    };

    document.querySelectorAll('.kanban-card').forEach(function (card) {
        card.addEventListener('pointerdown', function (event) {
            if (event.target.closest('button, form, input, textarea, select, a, details')) {
                return;
            }
            event.preventDefault();
            const rect = card.getBoundingClientRect();
            dragState = {
                startX: event.clientX,
                startY: event.clientY,
                startLeft: rect.left,
                startTop: rect.top,
                width: rect.width,
            };
            draggedCard = card;
            card.classList.add('dragging');
            card.style.position = 'fixed';
            card.style.left = rect.left + 'px';
            card.style.top = rect.top + 'px';
            card.style.width = rect.width + 'px';
            card.style.zIndex = '9999';
            card.style.pointerEvents = 'none';
            card.setPointerCapture(event.pointerId);
        });
    });

    document.addEventListener('pointermove', function (event) {
        if (!dragState || !draggedCard) {
            return;
        }
        const dx = event.clientX - dragState.startX;
        const dy = event.clientY - dragState.startY;
        draggedCard.style.left = (dragState.startLeft + dx) + 'px';
        draggedCard.style.top = (dragState.startTop + dy) + 'px';

        const target = document.elementFromPoint(event.clientX, event.clientY);
        const column = target ? target.closest('.kanban-column') : null;
        document.querySelectorAll('.kanban-column').forEach(function (item) {
            item.classList.toggle('drop-target', item === column);
        });
    });

    document.addEventListener('pointerup', function (event) {
        if (!dragState || !draggedCard) {
            return;
        }
        const target = document.elementFromPoint(event.clientX, event.clientY);
        const column = target ? target.closest('.kanban-column') : null;
        if (column) {
            moveTask(draggedCard.dataset.taskId, column.dataset.status);
        }
        resetDrag();
    });

    document.addEventListener('pointercancel', resetDrag);
})();
</script>
SCRIPT;

render_footer();
