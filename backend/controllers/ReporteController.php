<?php
/**
 * Clase ReporteController
 * Genera reportes agregados de merma (oro + materiales) por responsable, proceso y producto.
 */
class ReporteController {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Verifica que el usuario tenga permiso para ver reportes:
     * admin de sesión o flag ver_reportes=1 en su registro de Usuarios.
     * Devuelve true si tiene acceso; si no, responde y devuelve false.
     */
    private function checkPermisoReportes() {
        if (!SessionManager::get('logged_in')) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'No autenticado.'], JSON_UNESCAPED_UNICODE);
            return false;
        }
        if (SessionManager::get('is_admin')) {
            return true;
        }
        $userId = (int)(SessionManager::get('user_id') ?? 0);
        if ($userId > 0) {
            $stmt = $this->db->prepare("SELECT ver_reportes FROM Usuarios WHERE id = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && (int)($row['ver_reportes'] ?? 0) === 1) {
                return true;
            }
        }
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Acceso denegado'], JSON_UNESCAPED_UNICODE);
        return false;
    }

    /**
     * Construye la consulta base con filtros comunes.
     * Devuelve [sql, params]; el SELECT final se arma con las columnas de agrupación.
     */
    private function buildBaseQuery($where, $params) {
        $sql = "FROM Movimientos m
            JOIN Trazabilidad t ON m.consecutivo = t.consecutivo
            LEFT JOIN Responsables r ON t.responsable_id = r.id
            LEFT JOIN Procesos p ON t.proceso_id = p.id
            LEFT JOIN Productos prod ON t.producto_id = prod.id
            WHERE t.estado != 'cancelado'";
        if (!empty($where)) {
            $sql .= ' AND ' . implode(' AND ', $where);
        }
        return [$sql, $params];
    }

    /**
     * Ejecuta una agrupación (responsable / proceso / producto) y normaliza filas.
     */
    private function agrupar($selectCols, $groupBy, $idKey, $nombreKey, $baseSql, $params) {
        $sql = "SELECT $selectCols,
            COALESCE(SUM(CASE WHEN m.tipo = 'entrega' THEN m.peso_oro ELSE 0 END), 0) AS oro_entregado,
            COALESCE(SUM(CASE WHEN m.tipo = 'recibido' THEN m.peso_oro ELSE 0 END), 0) AS oro_recibido,
            COALESCE(SUM(CASE WHEN m.tipo = 'entrega' THEN m.peso_materiales ELSE 0 END), 0) AS materiales_entregados,
            COALESCE(SUM(CASE WHEN m.tipo = 'recibido' THEN m.peso_materiales ELSE 0 END), 0) AS materiales_recibidos,
            COUNT(DISTINCT m.consecutivo) AS ordenes
            $baseSql
            GROUP BY $groupBy";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $oro_ent = (float)$r['oro_entregado'];
            $oro_rec = (float)$r['oro_recibido'];
            $mat_ent = (float)$r['materiales_entregados'];
            $mat_rec = (float)$r['materiales_recibidos'];
            $total_ent = round($oro_ent + $mat_ent, 2);
            $total_rec = round($oro_rec + $mat_rec, 2);
            $merma_gr = round($total_ent - $total_rec, 2);
            $merma_pct = $total_ent > 0 ? round($merma_gr / $total_ent * 100, 2) : 0.0;
            $rows[] = [
                $idKey => (int)$r['id'],
                $nombreKey => (string)$r['nombre'],
                'oro_entregado' => $oro_ent,
                'oro_recibido' => $oro_rec,
                'materiales_entregados' => $mat_ent,
                'materiales_recibidos' => $mat_rec,
                'total_entregado' => $total_ent,
                'total_recibido' => $total_rec,
                'merma_gr' => $merma_gr,
                'merma_pct' => $merma_pct,
                'ordenes' => (int)$r['ordenes']
            ];
        }
        // Ordenar por merma_gr descendente
        usort($rows, function ($a, $b) {
            if ($a['merma_gr'] == $b['merma_gr']) return 0;
            return ($a['merma_gr'] > $b['merma_gr']) ? -1 : 1;
        });
        return $rows;
    }

    /**
     * Reporte de merma global y por responsable/proceso/producto.
     * GET /api/reportes/merma?desde=YYYY-MM-DD&hasta=YYYY-MM-DD&responsable_id=&proceso_id=
     */
    public function getMermaReport($data) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->checkPermisoReportes()) {
            return;
        }

        try {
            // --- Filtros ---
            $where = [];
            $params = [];

            $desde = isset($data['desde']) ? trim((string)$data['desde']) : '';
            if ($desde !== '') {
                $where[] = "m.fecha >= ?";
                $params[] = preg_match('/\d{2}:\d{2}/', $desde) ? $desde : ($desde . ' 00:00:00');
            }
            $hasta = isset($data['hasta']) ? trim((string)$data['hasta']) : '';
            if ($hasta !== '') {
                $where[] = "m.fecha <= ?";
                $params[] = preg_match('/\d{2}:\d{2}/', $hasta) ? $hasta : ($hasta . ' 23:59:59');
            }
            $responsable_id = isset($data['responsable_id']) && $data['responsable_id'] !== '' ? (int)$data['responsable_id'] : 0;
            if ($responsable_id > 0) {
                $where[] = "t.responsable_id = ?";
                $params[] = $responsable_id;
            }
            $proceso_id = isset($data['proceso_id']) && $data['proceso_id'] !== '' ? (int)$data['proceso_id'] : 0;
            if ($proceso_id > 0) {
                $where[] = "t.proceso_id = ?";
                $params[] = $proceso_id;
            }

            list($baseSql, $params) = $this->buildBaseQuery($where, $params);

            // --- Global ---
            $sqlGlobal = "SELECT
                COALESCE(SUM(CASE WHEN m.tipo = 'entrega' THEN m.peso_oro ELSE 0 END), 0) AS oro_entregado,
                COALESCE(SUM(CASE WHEN m.tipo = 'recibido' THEN m.peso_oro ELSE 0 END), 0) AS oro_recibido,
                COALESCE(SUM(CASE WHEN m.tipo = 'entrega' THEN m.peso_materiales ELSE 0 END), 0) AS materiales_entregados,
                COALESCE(SUM(CASE WHEN m.tipo = 'recibido' THEN m.peso_materiales ELSE 0 END), 0) AS materiales_recibidos,
                COUNT(DISTINCT m.consecutivo) AS ordenes
                $baseSql";
            $stmt = $this->db->prepare($sqlGlobal);
            $stmt->execute($params);
            $g = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $g_oro_ent = (float)($g['oro_entregado'] ?? 0);
            $g_oro_rec = (float)($g['oro_recibido'] ?? 0);
            $g_mat_ent = (float)($g['materiales_entregados'] ?? 0);
            $g_mat_rec = (float)($g['materiales_recibidos'] ?? 0);
            $g_total_ent = round($g_oro_ent + $g_mat_ent, 2);
            $g_total_rec = round($g_oro_rec + $g_mat_rec, 2);
            $g_merma_gr = round($g_total_ent - $g_total_rec, 2);
            $global = [
                'oro_entregado' => $g_oro_ent,
                'materiales_entregados' => $g_mat_ent,
                'total_entregado' => $g_total_ent,
                'oro_recibido' => $g_oro_rec,
                'materiales_recibidos' => $g_mat_rec,
                'total_recibido' => $g_total_rec,
                'merma_gr' => $g_merma_gr,
                'merma_pct' => $g_total_ent > 0 ? round($g_merma_gr / $g_total_ent * 100, 2) : 0.0,
                'ordenes' => (int)($g['ordenes'] ?? 0)
            ];

            // --- Agrupaciones ---
            $por_responsable = $this->agrupar(
                "t.responsable_id AS id, r.nombre AS nombre",
                "t.responsable_id, r.nombre",
                'responsable_id', 'responsable', $baseSql, $params
            );
            $por_proceso = $this->agrupar(
                "t.proceso_id AS id, p.nombre AS nombre",
                "t.proceso_id, p.nombre",
                'proceso_id', 'proceso', $baseSql, $params
            );
            $por_producto = $this->agrupar(
                "t.producto_id AS id, prod.nombre AS nombre",
                "t.producto_id, prod.nombre",
                'producto_id', 'producto', $baseSql, $params
            );

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'data' => [
                    'global' => $global,
                    'por_responsable' => $por_responsable,
                    'por_proceso' => $por_proceso,
                    'por_producto' => $por_producto
                ]
            ], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            Logger::error('Error en getMermaReport', ['exception' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error al generar el reporte: ' . $e->getMessage()]);
        }
    }
}
