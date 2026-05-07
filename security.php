<?php
require_once 'functions.php';
$config = get_config();
if (!isset($_SESSION['auth']) || $_SESSION['role'] !== 'security') {
    header('Location: index.php');
    exit;
}

$username = $_SESSION['username'];
$user_fullname = '';
$message = '';
$error = '';

// Obtener nombre completo del usuario
$admin_result = ldap_connect_samba($config);
if ($admin_result['success']) {
    $admin_conn = $admin_result['conn'];
    $search = ldap_search($admin_conn, $config['ldap']['base_dn'], "(sAMAccountName=$username)", ['givenName', 'sn', 'displayName']);
    if ($search) {
        $entries = ldap_get_entries($admin_conn, $search);
        if ($entries['count'] > 0) {
            $given = $entries[0]['givenname'][0] ?? '';
            $sn = $entries[0]['sn'][0] ?? '';
            $user_fullname = trim($given . ' ' . $sn);
            if (empty($user_fullname)) $user_fullname = $entries[0]['displayname'][0] ?? $username;
        }
    }
    ldap_unbind($admin_conn);
}

// Cambio de contraseña
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'];
    $new = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];
    
    $complexity_ok = true;
    if (strlen($new) < 8) {
        $error = "La nueva contraseña debe tener al menos 8 caracteres.";
        $complexity_ok = false;
    } elseif (!preg_match('/[0-9]/', $new)) {
        $error = "La nueva contraseña debe contener al menos un número.";
        $complexity_ok = false;
    } elseif (!preg_match('/[^A-Za-z0-9]/', $new)) {
        $error = "La nueva contraseña debe contener al menos un carácter especial (por ejemplo, !@#$%^&*).";
        $complexity_ok = false;
    } elseif ($new !== $confirm) {
        $error = "Las contraseñas nuevas no coinciden.";
        $complexity_ok = false;
    }
    
    if ($complexity_ok) {
        $ldapconn = ldap_connect($config['ldap']['host'], $config['ldap']['port']);
        if (!$ldapconn) {
            $error = "No se pudo conectar al servidor LDAP.";
        } else {
            ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, 3);
            $domain_parts = explode(',', $config['ldap']['base_dn']);
            $domain_fqdn = '';
            foreach ($domain_parts as $part) {
                if (stripos($part, 'dc=') === 0) {
                    $domain_fqdn .= substr($part, 3) . '.';
                }
            }
            $domain_fqdn = rtrim($domain_fqdn, '.');
            $user_upn = $username . '@' . $domain_fqdn;
            $bind = @ldap_bind($ldapconn, $user_upn, $current);
            if (!$bind) {
                $error = "Contraseña actual incorrecta.";
            } else {
                $result = samba_set_password($username, $new, $config);
                if ($result['success']) {
                    $message = "Contraseña actualizada correctamente.";
                } else {
                    $error = "Error al cambiar la contraseña: " . $result['error'];
                }
            }
            ldap_unbind($ldapconn);
        }
    }
}

// Parámetros de ordenación
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'username';
$sort_order = isset($_GET['sort_order']) ? $_GET['sort_order'] : 'asc';
$next_order = ($sort_order === 'asc') ? 'desc' : 'asc';

// Obtener lista de usuarios
$ldap_result = ldap_connect_samba($config);
if (!$ldap_result['success']) {
    die("Error de conexión LDAP: " . htmlspecialchars($ldap_result['error']));
}
$ldapconn = $ldap_result['conn'];

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perpage = 20;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$users_data = get_users_paged($ldapconn, $config['ldap']['base_dn'], $page, $perpage, $search, '', $sort_by, $sort_order);
$users = $users_data['users'];
$total_users = $users_data['total'];
$total_pages = ceil($total_users / $perpage);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seguridad - <?php echo htmlspecialchars($config['app']['name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .help-icon { margin-left: 5px; color: #6c757d; cursor: help; }
        th a { color: white; text-decoration: none; }
        th a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<nav class="navbar navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand"><?php echo htmlspecialchars($config['app']['name']); ?> - Panel de Seguridad Informática</span>
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
    
    <div class="row">
        <!-- Lista de usuarios -->
        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Lista de usuarios</h5>
                </div>
                <div class="card-body">
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
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                    <tr><td colspan="4" class="text-center">No se encontraron usuarios</td></tr>
                                <?php else: ?>
                                    <?php foreach ($users as $u): 
                                        $sAMAccountName = $u['samaccountname'][0] ?? '';
                                        $given = $u['givenname'][0] ?? '';
                                        $sn = $u['sn'][0] ?? '';
                                        $fullname = trim($given . ' ' . $sn);
                                        if (empty($fullname)) $fullname = $sAMAccountName;
                                        $email = $sAMAccountName . '@ecim.co.cu';
                                        $ou = get_ou_from_dn($u['distinguishedname'][0] ?? '');
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($sAMAccountName); ?></td>
                                        <td><?php echo htmlspecialchars($fullname); ?></td>
                                        <td><?php echo htmlspecialchars($email); ?></td>
                                        <td><?php echo htmlspecialchars($ou); ?></td>
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
            </div>
        </div>
        
        <!-- Cambio de contraseña (igual) -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">Cambiar su contraseña</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Nombre completo</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($user_fullname); ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nombre de usuario</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($username); ?>" disabled>
                    </div>
                    <form method="post">
                        <input type="hidden" name="change_password" value="1">
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Contraseña actual</label>
                            <i class="bi bi-question-circle help-icon" data-bs-toggle="tooltip" title="Escriba su contraseña actual"></i>
                            <input type="password" name="current_password" id="current_password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label for="new_password" class="form-label">Nueva contraseña</label>
                            <i class="bi bi-question-circle help-icon" data-bs-toggle="tooltip" title="Nueva contraseña para su cuenta. Debe tener al menos 8 caracteres, incluir un número y un carácter especial."></i>
                            <input type="password" name="new_password" id="new_password" class="form-control" required>
                            <small class="text-muted">Requisitos: mínimo 8 caracteres, al menos un número y un carácter especial (ej. !@#$%^&*).</small>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirmar nueva contraseña</label>
                            <i class="bi bi-question-circle help-icon" data-bs-toggle="tooltip" title="Escriba nuevamente la misma contraseña"></i>
                            <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Cambiar contraseña</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
</script>
<?php echo get_footer(); ?>
</body>
</html>