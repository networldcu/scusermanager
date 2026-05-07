<?php
require_once 'functions.php';
$config = get_config();
if (!isset($_SESSION['auth']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$ldap_result = ldap_connect_samba($config);
if (!$ldap_result['success']) {
    die("Error de conexión LDAP: " . htmlspecialchars($ldap_result['error']));
}
$ldapconn = $ldap_result['conn'];

$message = '';
$error = '';

// Crear usuario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $givenname = trim($_POST['givenname']);
    $sn = trim($_POST['sn']);
    $ou_dn = $_POST['ou'];
    $groups = $_POST['groups'] ?? [];
    $create_mailbox = isset($_POST['create_mailbox']) ? true : false;
    
    if (empty($username) || empty($password) || empty($givenname) || empty($sn) || empty($ou_dn)) {
        $error = "Todos los campos obligatorios deben ser llenados.";
    } else {
        $result = samba_create_user($username, $password, $givenname, $sn, $ou_dn, $config);
        if ($result['success']) {
            if (!empty($groups)) {
                $group_result = samba_add_user_to_groups($username, $groups, $config);
                if (!$group_result['success']) {
                    $error .= " Asignación de grupos falló: " . implode(', ', $group_result['errors']);
                }
            }
            if ($create_mailbox) {
                $carbonio_result = create_carbonio_mailbox($username, $givenname, $sn, "$givenname $sn", $config);
                if ($carbonio_result['success']) {
                    $message = "Usuario creado exitosamente en Samba y Carbonio.";
                } else {
                    $error .= " Usuario creado en Samba, pero falló al crear buzón en Carbonio (paso: {$carbonio_result['step']}). Error: {$carbonio_result['error']}. Debe crear el buzón manualmente.";
                }
            } else {
                $message = "Usuario creado exitosamente solo en Samba.";
            }
        } else {
            $error = "Error al crear usuario con samba-tool: " . $result['error'];
        }
    }
}

// Eliminar usuario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $username = trim($_POST['username']);
    $delete_mailbox = isset($_POST['delete_mailbox']) ? true : false;
    
    $result = samba_delete_user($username, $config);
    if ($result['success']) {
        if ($delete_mailbox) {
            $carbonio_result = delete_carbonio_mailbox($username, $config);
            if ($carbonio_result['success']) {
                $message = "Usuario eliminado de Samba y buzón de Carbonio eliminado.";
            } else {
                $error = "Usuario eliminado de Samba, pero falló al eliminar buzón de Carbonio: {$carbonio_result['error']}. Debe eliminarlo manualmente.";
            }
        } else {
            $message = "Usuario eliminado solo de Samba (buzón de Carbonio conservado).";
        }
    } else {
        $error = "Error al eliminar usuario con samba-tool: " . $result['error'];
    }
}

// Parámetros de ordenación
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'username';
$sort_order = isset($_GET['sort_order']) ? $_GET['sort_order'] : 'asc';
$next_order = ($sort_order === 'asc') ? 'desc' : 'asc';

// Paginación y listado
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perpage = 20;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$users_data = get_users_paged($ldapconn, $config['ldap']['base_dn'], $page, $perpage, $search, '', $sort_by, $sort_order);
$users = $users_data['users'];
$total_users = $users_data['total'];
$total_pages = ceil($total_users / $perpage);

$ous = get_all_ous($ldapconn, $config['ldap']['base_dn']);
$groups_list = get_all_groups($ldapconn, $config['ldap']['base_dn']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - <?php echo htmlspecialchars($config['app']['name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .navbar-brand { font-weight: bold; }
        .table-responsive { margin-top: 15px; }
        .card { border-radius: 10px; }
        .modal-content { border-radius: 10px; }
        .help-icon { margin-left: 5px; color: #6c757d; cursor: help; }
        th a { color: white; text-decoration: none; }
        th a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<nav class="navbar navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand"><?php echo htmlspecialchars($config['app']['name']); ?> - Panel de Administración</span>
        <div>
            <a href="export.php" class="btn btn-outline-info me-2">Exportar usuarios</a>
            <a href="logout.php" class="btn btn-outline-light">Cerrar sesión</a>
        </div>
    </div>
</nav>
<div class="container mt-4">
    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <ul class="nav nav-tabs" id="myTab" role="tablist">
        <li class="nav-item"><button class="nav-link active" id="users-tab" data-bs-toggle="tab" data-bs-target="#users" type="button" role="tab">Usuarios</button></li>
        <li class="nav-item"><button class="nav-link" id="create-tab" data-bs-toggle="tab" data-bs-target="#create" type="button" role="tab">Crear Usuario</button></li>
    </ul>
    
    <div class="tab-content mt-3">
        <!-- Listado de usuarios -->
        <div class="tab-pane fade show active" id="users" role="tabpanel">
            <form method="get" class="mb-3">
                <div class="input-group">
                    <input type="text" name="search" class="form-control" placeholder="Buscar por usuario, nombre o apellidos..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-primary">Buscar</button>
                    <?php if ($search): ?>
                        <a href="?page=1" class="btn btn-secondary">Limpiar</a>
                    <?php endif; ?>
                </div>
                <input type="hidden" name="page" value="1">
                <input type="hidden" name="sort_by" value="<?php echo $sort_by; ?>">
                <input type="hidden" name="sort_order" value="<?php echo $sort_order; ?>">
            </form>
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'username', 'sort_order' => $next_order, 'page' => 1])); ?>">Usuario <?php echo ($sort_by == 'username') ? ($sort_order == 'asc' ? '▲' : '▼') : ''; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'fullname', 'sort_order' => $next_order, 'page' => 1])); ?>">Nombre y Apellidos <?php echo ($sort_by == 'fullname') ? ($sort_order == 'asc' ? '▲' : '▼') : ''; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'email', 'sort_order' => $next_order, 'page' => 1])); ?>">Correo electrónico <?php echo ($sort_by == 'email') ? ($sort_order == 'asc' ? '▲' : '▼') : ''; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'ou', 'sort_order' => $next_order, 'page' => 1])); ?>">Unidad Organizativa <?php echo ($sort_by == 'ou') ? ($sort_order == 'asc' ? '▲' : '▼') : ''; ?></a></th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr><td colspan="6" class="text-center">No se encontraron usuarios</td></tr>
                        <?php else: ?>
                            <?php foreach ($users as $u): 
                                $sAMAccountName = $u['samaccountname'][0] ?? '';
                                $given = $u['givenname'][0] ?? '';
                                $sn = $u['sn'][0] ?? '';
                                $fullname = trim($given . ' ' . $sn);
                                if (empty($fullname)) $fullname = $sAMAccountName;
                                $email = $sAMAccountName . '@ecim.co.cu';
                                $ou = get_ou_from_dn($u['distinguishedname'][0] ?? '');
                                $accountControl = $u['useraccountcontrol'][0] ?? '512';
                                $enabled = ($accountControl & 2) == 0;
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($sAMAccountName); ?></td>
                                <td><?php echo htmlspecialchars($fullname); ?></td>
                                <td><?php echo htmlspecialchars($email); ?></td>
                                <td><?php echo htmlspecialchars($ou); ?></td>
                                <td><?php echo $enabled ? 'Habilitado' : 'Bloqueado'; ?></td>
                                <td>
                                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editModal" 
                                        data-username="<?php echo htmlspecialchars($sAMAccountName); ?>"
                                        data-given="<?php echo htmlspecialchars($given); ?>"
                                        data-sn="<?php echo htmlspecialchars($sn); ?>">Editar</button>
                                    <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteModal"
                                        data-username="<?php echo htmlspecialchars($sAMAccountName); ?>">Eliminar</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($total_pages > 1): ?>
                <nav><ul class="pagination justify-content-center">
                    <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page-1])); ?>">Anterior</a>
                    </li>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page+1])); ?>">Siguiente</a>
                    </li>
                </ul></nav>
            <?php endif; ?>
        </div>
        
        <!-- Crear usuario (sin cambios) -->
        <div class="tab-pane fade" id="create" role="tabpanel">
            <div class="card"><div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3"><label>Nombre de usuario</label><input type="text" name="username" class="form-control" required></div>
                    <div class="mb-3"><label>Contraseña</label><input type="password" name="password" class="form-control" required></div>
                    <div class="mb-3"><label>Nombre</label><input type="text" name="givenname" class="form-control" required></div>
                    <div class="mb-3"><label>Apellidos</label><input type="text" name="sn" class="form-control" required></div>
                    <div class="mb-3">
                        <label>Unidad Organizativa</label>
                        <select name="ou" class="form-control" required>
                            <option value="">Seleccione...</option>
                            <?php foreach ($ous as $ou): ?>
                                <option value="<?php echo htmlspecialchars($ou['dn']); ?>"><?php echo htmlspecialchars($ou['ou']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label>Grupos</label>
                        <select name="groups[]" multiple class="form-control" size="5">
                            <?php foreach ($groups_list as $group): ?>
                                <option value="<?php echo htmlspecialchars($group['dn']); ?>"><?php echo htmlspecialchars($group['cn']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Ctrl+clic para seleccionar múltiples</small>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" name="create_mailbox" class="form-check-input" id="createMailbox">
                        <label class="form-check-label" for="createMailbox">Crear buzón de correo en Carbonio</label>
                        <i class="bi bi-question-circle help-icon" data-bs-toggle="tooltip" title="Al marcar esta opción se creará una cuenta de correo en el servidor de correo de la empresa"></i>
                    </div>
                    <button type="submit" class="btn btn-primary">Crear usuario</button>
                </form>
            </div></div>
        </div>
    </div>
</div>

<!-- Modales (iguales que antes) -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post" action="edit_user.php">
            <div class="modal-header"><h5 class="modal-title">Editar Usuario</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="username" id="edit_username">
                <div class="mb-3"><label>Nombre</label><input type="text" name="givenname" id="edit_given" class="form-control"></div>
                <div class="mb-3"><label>Apellidos</label><input type="text" name="sn" id="edit_sn" class="form-control"></div>
                <div class="mb-3"><label>Nueva contraseña (dejar vacío para no cambiar)</label><input type="password" name="newpassword" class="form-control"></div>
                <div class="mb-3 form-check">
                    <input type="checkbox" name="enable_account" class="form-check-input" id="enableCheck">
                    <label class="form-check-label">Habilitar cuenta (desbloquear)</label>
                    <i class="bi bi-question-circle help-icon" data-bs-toggle="tooltip" title="Si la cuenta estaba bloqueada, al marcar esta opción se desbloqueará"></i>
                </div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Guardar cambios</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post">
            <div class="modal-header"><h5 class="modal-title">Eliminar Usuario</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="username" id="delete_username">
                <p>¿Está seguro de eliminar al usuario <strong id="delete_username_span"></strong>?</p>
                <div class="form-check">
                    <input type="checkbox" name="delete_mailbox" class="form-check-input" id="deleteMailbox">
                    <label class="form-check-label text-danger" for="deleteMailbox">Eliminar también el buzón de Carbonio</label>
                    <i class="bi bi-question-circle help-icon text-danger" data-bs-toggle="tooltip" title="Al marcar esta opción se eliminará la cuenta de correo del usuario y se perderá toda su información incluyendo sus correos y contactos, esta opción no es reversible."></i>
                </div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-danger">Eliminar</button></div>
        </form>
    </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    var editModal = document.getElementById('editModal');
    editModal.addEventListener('show.bs.modal', function(event) {
        var button = event.relatedTarget;
        document.getElementById('edit_username').value = button.getAttribute('data-username');
        document.getElementById('edit_given').value = button.getAttribute('data-given');
        document.getElementById('edit_sn').value = button.getAttribute('data-sn');
    });
    var deleteModal = document.getElementById('deleteModal');
    deleteModal.addEventListener('show.bs.modal', function(event) {
        var button = event.relatedTarget;
        document.getElementById('delete_username').value = button.getAttribute('data-username');
        document.getElementById('delete_username_span').innerText = button.getAttribute('data-username');
    });
</script>
<?php echo get_footer(); ?>
</body>
</html>