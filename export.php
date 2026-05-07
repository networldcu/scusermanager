<?php
require_once 'functions.php';
$config = get_config();
if (!isset($_SESSION['auth']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'security')) {
    header('Location: index.php');
    exit;
}

$ldap_result = ldap_connect_samba($config);
if (!$ldap_result['success']) {
    die("Error de conexión LDAP: " . htmlspecialchars($ldap_result['error']));
}
$ldapconn = $ldap_result['conn'];

// Obtener parámetros
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perpage = 20;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$ou_filter = isset($_GET['ou_filter']) ? trim($_GET['ou_filter']) : '';
$export_type = isset($_GET['export_type']) ? $_GET['export_type'] : '';
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'username';
$sort_order = isset($_GET['sort_order']) ? $_GET['sort_order'] : 'asc';

// Exportación a HTML o PDF (vista imprimible)
if ($export_type === 'html' || $export_type === 'pdf') {
    $users = get_all_users_filtered($ldapconn, $config['ldap']['base_dn'], $search, $ou_filter, $sort_by, $sort_order);
    $data = [];
    foreach ($users as $u) {
        $sAMAccountName = $u['samaccountname'][0] ?? '';
        $given = $u['givenname'][0] ?? '';
        $sn = $u['sn'][0] ?? '';
        $fullname = trim($given . ' ' . $sn);
        if (empty($fullname)) $fullname = $sAMAccountName;
        $email = $sAMAccountName . '@ecim.co.cu';
        $ou = get_ou_from_dn($u['distinguishedname'][0] ?? '');
        $data[] = [
            'fullname' => $fullname,
            'email' => $email,
            'ou' => $ou,
            'username' => $sAMAccountName
        ];
    }
    
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Exportar usuarios - <?php echo htmlspecialchars($config['app']['name']); ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 20px; background-color: #f8f9fa; }
            .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #0d6efd; padding-bottom: 15px; }
            .header h1 { color: #0d6efd; margin-bottom: 5px; }
            .header p { color: #6c757d; margin-bottom: 0; }
            .table-container { background: white; border-radius: 10px; box-shadow: 0 0 15px rgba(0,0,0,0.1); padding: 20px; margin-bottom: 30px; }
            table { width: 100%; border-collapse: collapse; font-size: 14px; }
            th { background-color: #0d6efd; color: white; padding: 12px; text-align: left; font-weight: 600; }
            td { padding: 10px; border-bottom: 1px solid #dee2e6; }
            tr:hover { background-color: #f1f3f5; }
            .footer { text-align: center; font-size: 12px; color: #6c757d; margin-top: 20px; }
            @media print {
                body { background-color: white; padding: 0; }
                .no-print { display: none; }
                .table-container { box-shadow: none; padding: 0; }
                th { background-color: #0d6efd !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h1><?php echo htmlspecialchars($config['app']['name']); ?></h1>
            <p>Reporte de usuarios generado el <?php echo date('d/m/Y H:i:s'); ?></p>
            <?php if ($search): ?><p><strong>Búsqueda:</strong> <?php echo htmlspecialchars($search); ?></p><?php endif; ?>
            <?php if ($ou_filter): $ou_name = get_ou_from_dn($ou_filter); ?><p><strong>Unidad Organizativa:</strong> <?php echo htmlspecialchars($ou_name); ?></p><?php endif; ?>
            <p><strong>Ordenado por:</strong> <?php echo ucfirst($sort_by); ?> (<?php echo ($sort_order == 'asc') ? 'Ascendente' : 'Descendente'; ?>)</p>
        </div>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Nombre y Apellidos</th>
                        <th>Correo</th>
                        <th>Unidad Organizativa</th>
                        <th>Usuario</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($data)): ?>
                        <tr><td colspan="4" style="text-align: center;">No se encontraron usuarios</td></tr>
                    <?php else: ?>
                        <?php foreach ($data as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['fullname']); ?></td>
                            <td><?php echo htmlspecialchars($row['email']); ?></td>
                            <td><?php echo htmlspecialchars($row['ou']); ?></td>
                            <td><?php echo htmlspecialchars($row['username']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="footer no-print">
            <p>Este reporte puede ser impreso o guardado como PDF desde el menú de su navegador (Ctrl+P o Archivo → Imprimir).</p>
            <button onclick="window.print();" class="btn btn-primary">Guardar como PDF / Imprimir</button>
            <a href="export.php?<?php echo http_build_query(array_merge($_GET, ['export_type' => ''])); ?>" class="btn btn-secondary">Volver</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Vista normal con paginación
$users_data = get_users_paged($ldapconn, $config['ldap']['base_dn'], $page, $perpage, $search, $ou_filter, $sort_by, $sort_order);
$users = $users_data['users'];
$total_users = $users_data['total'];
$total_pages = ceil($total_users / $perpage);
$next_order = ($sort_order === 'asc') ? 'desc' : 'asc';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exportar usuarios - <?php echo htmlspecialchars($config['app']['name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>.help-icon { margin-left: 5px; color: #6c757d; cursor: help; } th a { color: white; text-decoration: none; } th a:hover { text-decoration: underline; }</style>
</head>
<body>
<nav class="navbar navbar-dark bg-dark"><div class="container-fluid"><span class="navbar-brand"><?php echo htmlspecialchars($config['app']['name']); ?> - Exportar usuarios</span><a href="<?php echo ($_SESSION['role'] === 'admin') ? 'admin.php' : 'security.php'; ?>" class="btn btn-outline-light">Volver</a></div></nav>
<div class="container mt-4">
    <div class="card mb-4"><div class="card-header bg-primary text-white"><h5 class="mb-0">Filtros y exportación</h5></div><div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-4"><label for="search" class="form-label">Buscar (usuario, nombre, apellidos)</label><input type="text" name="search" id="search" class="form-control" value="<?php echo htmlspecialchars($search); ?>"></div>
            <div class="col-md-4"><label for="ou_filter" class="form-label">Filtrar por Unidad Organizativa</label><select name="ou_filter" id="ou_filter" class="form-control"><?php echo get_ou_dropdown($ldapconn, $config['ldap']['base_dn'], $ou_filter); ?></select></div>
            <div class="col-md-4 d-flex align-items-end"><button type="submit" class="btn btn-primary me-2">Aplicar filtros</button><a href="?page=1" class="btn btn-secondary">Limpiar</a></div>
            <input type="hidden" name="sort_by" value="<?php echo $sort_by; ?>">
            <input type="hidden" name="sort_order" value="<?php echo $sort_order; ?>">
        </form>
        <hr>
        <div class="btn-group">
            <a href="?<?php echo http_build_query(array_merge($_GET, ['export_type' => 'html', 'page' => 1])); ?>" class="btn btn-success" target="_blank">Exportar a HTML (vista completa)</a>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['export_type' => 'pdf', 'page' => 1])); ?>" class="btn btn-info" target="_blank">Exportar a PDF (vista imprimible)</a>
        </div>
        <small class="text-muted ms-3">La exportación respeta los filtros actuales (búsqueda, unidad organizativa y orden).</small>
    </div></div>
    <div class="card"><div class="card-header bg-secondary text-white"><h5 class="mb-0">Lista de usuarios (paginada)</h5></div><div class="card-body">
        <div class="table-responsive"><table class="table table-bordered table-striped"><thead>
            <tr>
                <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'username', 'sort_order' => $next_order, 'page' => 1])); ?>">Usuario <?php echo ($sort_by == 'username') ? ($sort_order == 'asc' ? '▲' : '▼') : ''; ?></a></th>
                <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'fullname', 'sort_order' => $next_order, 'page' => 1])); ?>">Nombre y Apellidos <?php echo ($sort_by == 'fullname') ? ($sort_order == 'asc' ? '▲' : '▼') : ''; ?></a></th>
                <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'email', 'sort_order' => $next_order, 'page' => 1])); ?>">Correo electrónico <?php echo ($sort_by == 'email') ? ($sort_order == 'asc' ? '▲' : '▼') : ''; ?></a></th>
                <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'ou', 'sort_order' => $next_order, 'page' => 1])); ?>">Unidad Organizativa <?php echo ($sort_by == 'ou') ? ($sort_order == 'asc' ? '▲' : '▼') : ''; ?></a></th>
            </tr>
        </thead><tbody>
        <?php if (empty($users)): ?><tr><td colspan="4" class="text-center">No se encontraron usuarios</td></tr>
        <?php else: foreach ($users as $u): 
            $sAMAccountName = $u['samaccountname'][0] ?? '';
            $given = $u['givenname'][0] ?? '';
            $sn = $u['sn'][0] ?? '';
            $fullname = trim($given . ' ' . $sn); if (empty($fullname)) $fullname = $sAMAccountName;
            $email = $sAMAccountName . '@ecim.co.cu';
            $ou = get_ou_from_dn($u['distinguishedname'][0] ?? '');
        ?><tr><td><?php echo htmlspecialchars($sAMAccountName); ?></td><td><?php echo htmlspecialchars($fullname); ?></td><td><?php echo htmlspecialchars($email); ?></td><td><?php echo htmlspecialchars($ou); ?></td></tr>
        <?php endforeach; endif; ?>
        </tbody></table></div>
        <?php if ($total_pages > 1): ?><nav><ul class="pagination justify-content-center">
            <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page-1])); ?>">Anterior</a></li>
            <?php for ($i = 1; $i <= $total_pages; $i++): ?><li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a></li><?php endfor; ?>
            <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page+1])); ?>">Siguiente</a></li>
        </ul></nav><?php endif; ?>
    </div></div>
</div>
<?php echo get_footer(); ?>
</body>
</html>