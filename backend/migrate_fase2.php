<?php
/**
 * Script de migración Fase 2: Movimientos Parciales
 * Migra datos de la estructura antigua (Trazabilidad monolítica) a la nueva
 * (Trazabilidad como header + tabla Movimientos como detalle).
 *
 * Uso: php backend/migrate_fase2.php
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/core/Logger.php';

header('Content-Type: application/json');

Logger::info('Iniciando migración Fase 2');

try {
    $db = Database::getInstance()->getConnection();
    $db->beginTransaction();

    // ----------------------------------------------------
    // 1. Verificar/Crear tabla Movimientos
    // ----------------------------------------------------
    $stmt = $db->prepare(
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND LOWER(table_name) = 'movimientos'
         LIMIT 1"
    );
    $stmt->execute();
    if (!$stmt->fetch()) {
        $sql = "CREATE TABLE Movimientos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            consecutivo INT NOT NULL,
            tipo ENUM('entrega','recibido') NOT NULL,
            peso DECIMAL(10,2) NOT NULL,
            fecha DATETIME NOT NULL,
            fotos_path TEXT NULL,
            observaciones TEXT NULL,
            registrado_por INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (consecutivo) REFERENCES Trazabilidad(consecutivo)
                ON DELETE CASCADE
        ) ENGINE=InnoDB";
        $db->exec($sql);
        Logger::info('Tabla Movimientos creada');
    } else {
        Logger::info('Tabla Movimientos ya existe');
    }

    // ----------------------------------------------------
    // 2. Agregar columnas nuevas a Trazabilidad si no existen
    // ----------------------------------------------------
    $columnasNuevas = [
        "estado" => "ALTER TABLE Trazabilidad ADD COLUMN IF NOT EXISTS estado ENUM('pendiente','parcial','completado','cancelado') NOT NULL DEFAULT 'pendiente'",
        "cantidad" => "ALTER TABLE Trazabilidad ADD COLUMN IF NOT EXISTS cantidad INT NOT NULL DEFAULT 1",
        "creado_por" => "ALTER TABLE Trazabilidad ADD COLUMN IF NOT EXISTS creado_por INT NOT NULL DEFAULT 0",
        "observaciones_header" => "ALTER TABLE Trazabilidad ADD COLUMN IF NOT EXISTS observaciones_header TEXT NULL"
    ];

    foreach ($columnasNuevas as $nombre => $sql) {
        try {
            $db->exec($sql);
            Logger::info("Columna {$nombre} verificada/agregada");
        } catch (PDOException $e) {
            Logger::warn("Columna {$nombre} posiblemente ya existe: " . $e->getMessage());
        }
    }

    // ----------------------------------------------------
    // 3. Índices para performance
    // ----------------------------------------------------
    $indices = [
        "idx_movimientos_consecutivo" => "SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'Movimientos' AND index_name = 'idx_movimientos_consecutivo'",
        "idx_movimientos_tipo" => "SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'Movimientos' AND index_name = 'idx_movimientos_tipo'",
        "idx_trazabilidad_estado" => "SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'Trazabilidad' AND index_name = 'idx_trazabilidad_estado'",
        "idx_trazabilidad_responsable" => "SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'Trazabilidad' AND index_name = 'idx_trazabilidad_responsable'",
        "idx_trazabilidad_fecha" => "SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'Trazabilidad' AND index_name = 'idx_trazabilidad_fecha'"
    ];
    $sqlCrear = [
        "idx_movimientos_consecutivo" => "CREATE INDEX idx_movimientos_consecutivo ON Movimientos(consecutivo)",
        "idx_movimientos_tipo" => "CREATE INDEX idx_movimientos_tipo ON Movimientos(tipo)",
        "idx_trazabilidad_estado" => "CREATE INDEX idx_trazabilidad_estado ON Trazabilidad(estado)",
        "idx_trazabilidad_responsable" => "CREATE INDEX idx_trazabilidad_responsable ON Trazabilidad(responsable_id)",
        "idx_trazabilidad_fecha" => "CREATE INDEX idx_trazabilidad_fecha ON Trazabilidad(fecha_entrega)"
    ];
    foreach ($indices as $nombre => $checkSql) {
        try {
            $chk = $db->query($checkSql);
            if (!$chk->fetch()) {
                $db->exec($sqlCrear[$nombre]);
                Logger::info("Índice {$nombre} creado");
            }
        } catch (PDOException $e) {
            Logger::warn("Índice {$nombre}: " . $e->getMessage());
        }
    }

    // ----------------------------------------------------
    // 4. Migrar datos existentes de Trazabilidad -> Movimientos
    // ----------------------------------------------------
    $stmtRows = $db->query("SELECT * FROM Trazabilidad");
    $rows = $stmtRows->fetchAll(PDO::FETCH_ASSOC);

    $migrados = 0;
    $movimientosInsertados = 0;

    foreach ($rows as $row) {
        $consecutivo = (int)$row['consecutivo'];
        $estado = empty($row['fecha_recibido']) ? 'pendiente' : 'completado';

        // Parsear observaciones para separar ENTREGA / RECIBIDO
        $obs = $row['observaciones'] ?? '';
        $obsEntrega = '';
        $obsRecibido = '';
        foreach (preg_split("/\r?\n/", (string)$obs) as $line) {
            $lineTrim = trim($line);
            if (stripos($lineTrim, 'ENTREGA:') === 0) {
                $obsEntrega = trim(substr($lineTrim, 8, JSON_UNESCAPED_UNICODE));
            } elseif (stripos($lineTrim, 'RECIBIDO:') === 0) {
                $obsRecibido = trim(substr($lineTrim, 9, JSON_UNESCAPED_UNICODE));
            } elseif ($lineTrim !== '') {
                // Líneas sin prefijo: asignar a entrega por defecto si aún no hay nada
                if ($obsEntrega === '') {
                    $obsEntrega = $lineTrim;
                }
            }
        }

        // Actualizar header Trazabilidad
        $upd = $db->prepare(
            "UPDATE Trazabilidad SET
                estado = ?,
                cantidad = 1,
                creado_por = 0,
                observaciones_header = ?
             WHERE consecutivo = ?"
        );
        $upd->execute([$estado, $obsEntrega, $consecutivo]);
        $migrados++;

        // Insertar movimiento de ENTREGA si existe peso_entregado y fecha_entrega
        if (!empty($row['peso_entregado']) && !empty($row['fecha_entrega'])) {
            $insEnt = $db->prepare(
                "INSERT INTO Movimientos
                    (consecutivo, tipo, peso, fecha, fotos_path, observaciones, registrado_por)
                 VALUES (?, ?, ?, ?, ?, ?, 0)"
            );
            $insEnt->execute([
                $consecutivo,
                'entrega',
                (float)$row['peso_entregado'],
                $row['fecha_entrega'],
                $row['foto_entrega_path'] ?? null,
                $obsEntrega
            ]);
            $movimientosInsertados++;
        }

        // Insertar movimiento de RECIBIDO si existe fecha_recibido y peso_recibido
        if (!empty($row['fecha_recibido']) && !empty($row['peso_recibido'])) {
            $insRec = $db->prepare(
                "INSERT INTO Movimientos
                    (consecutivo, tipo, peso, fecha, fotos_path, observaciones, registrado_por)
                 VALUES (?, ?, ?, ?, ?, ?, 0)"
            );
            $insRec->execute([
                $consecutivo,
                'recibido',
                (float)$row['peso_recibido'],
                $row['fecha_recibido'],
                $row['foto_recibido_path'] ?? null,
                $obsRecibido
            ]);
            $movimientosInsertados++;
        }
    }

    $db->commit();

    $mensaje = "Migración Fase 2 completada. Ordenes migradas: {$migrados}. Movimientos insertados: {$movimientosInsertados}.";
    Logger::info($mensaje);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => $mensaje,
        'data' => [
            'ordenes_migradas' => $migrados,
            'movimientos_insertados' => $movimientosInsertados
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    Logger::error('Error en migración Fase 2', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error en la migración: ' . $e->getMessage()
    ]);
}
